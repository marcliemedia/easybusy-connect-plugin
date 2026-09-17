<?php

declare(strict_types=1);

namespace EasyBusyConnect\Form;

use EasyBusyConnect\Api\Booking;
use EasyBusyConnect\Api\Capabilities;
use EasyBusyConnect\Api\Catalog;
use EasyBusyConnect\Api\Leads;
use EasyBusyConnect\Api\Slots;
use EasyBusyConnect\Notify;
use EasyBusyConnect\Settings;
use EasyBusyConnect\Support\Log;
use EasyBusyConnect\Support\Uploads;
use EasyBusyConnect\Store\Submissions;

/**
 * Turns a finished draft into an EasyBusy record.
 *
 * The payload is rebuilt from server-side draft state, the slot is re-verified
 * against the live API immediately before the write, and the result is stored on
 * the draft so a repeated submit is idempotent rather than a second appointment.
 *
 * While the dry-run switch is on (default until the clinic authorises live
 * writes) the assembled payload is returned and logged, and nothing leaves the
 * site.
 */
final class Submit
{
    public function __construct(
        private Capabilities $capabilities,
        private Definition $definition,
        private Catalog $catalog,
        private Slots $slots,
        private Booking $booking,
        private Leads $leads,
        private Notify $notify
    ) {
    }

    /**
     * Persists the outcome locally (so the clinic sees it in wp-admin even when
     * EasyBusy is unreachable) and fires notifications for real sends.
     *
     * @param array<string,mixed>  $result
     * @param array<string,string> $contact
     */
    private function persist(Draft $draft, array $result, array $contact, string $type, string $status, string $errorCode = ''): void
    {
        $service = $draft->get('service');
        $snapshot = $draft->get('slot_snapshot');
        $start = \EasyBusyConnect\Support\Tz::parse((string) $draft->get('start', ''));
        $appointment = is_array($result['appointment'] ?? null) ? $result['appointment'] : [];
        $lead = is_array($result['lead'] ?? null) ? $result['lead'] : [];

        $row = [
            'type'          => $type,
            'status'        => $status,
            'dry_run'       => !empty($result['dry_run']),
            'easybusy_id'   => $appointment['appointmentId'] ?? $lead['leadId'] ?? null,
            'service_id'    => $draft->serviceId(),
            'service_name'  => is_array($service) ? (string) $service['name'] : '',
            'doctor_id'     => $draft->doctorId(),
            'doctor_name'   => is_array($snapshot) ? (string) $snapshot['doctorName'] : '',
            'slot_id'       => is_array($snapshot) ? (int) $snapshot['slotId'] : null,
            'start_at'      => $start !== null ? $start->format('Y-m-d H:i:s') : null,
            'duration_min'  => is_array($snapshot) ? (int) $snapshot['requiredSlotSize'] : null,
            'language'      => $draft->language(),
            'patient_name'  => trim(($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? '')),
            'patient_email' => $contact['email'] ?? '',
            'patient_phone' => $contact['phone'] ?? '',
            'message'       => (string) $draft->get('message', ''),
            'consent_at'    => ($contact['consent'] ?? '') !== '' ? current_time('mysql') : null,
            'error_code'    => $errorCode,
            'attribution'   => is_array($draft->get('attribution')) ? $draft->get('attribution') : [],
            'draft_token'   => $draft->token,
        ];

        Submissions::record($row);
        $this->notify->dispatch($row);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|\WP_Error
     */
    public function run(Draft $draft, array $input): array|\WP_Error
    {
        $existing = $draft->get('result');
        if (is_array($existing)) {
            return $existing + ['repeated' => true];
        }

        $serviceId = $draft->serviceId();
        if ($serviceId === null) {
            return new \WP_Error('ebc_no_service', __('Please choose a service first.', 'easybusy-connect'), ['status' => 400]);
        }

        $validated = $this->definition->validateContact($input);
        if ($validated['errors'] !== []) {
            return new \WP_Error('ebc_invalid_contact', __('Please correct the highlighted fields.', 'easybusy-connect'), [
                'status' => 422,
                'fields' => $validated['errors'],
            ]);
        }

        $contact = $validated['fields'];
        $language = $draft->language();
        $message = $this->definition->sanitiseMessage((string) $draft->get('message', ''));
        $dryRun = Settings::isDryRun();

        if (!$this->definition->bookingEnabled()) {
            return $this->asInquiry($draft, $contact, $message, $dryRun, 'lead_mode');
        }

        $slotId = (int) $draft->get('slot', 0);
        if ($slotId <= 0) {
            return new \WP_Error('ebc_no_slot', __('Please pick an appointment time.', 'easybusy-connect'), ['status' => 400]);
        }

        $slot = $this->slots->verify($slotId, $serviceId, $draft->doctorId(), $language);
        if (is_wp_error($slot)) {
            return $slot;
        }

        $start = (string) $draft->get('start', '');
        $patientInfo = $this->patientInfo($contact, $language);

        if ($dryRun) {
            $preview = [
                'dry_run'   => true,
                'mode'      => 'booking',
                'endpoint'  => sprintf('POST /v2/simple-booking/request-slots/%d', $slotId),
                'query'     => array_filter(['startTimestamp' => $start, 'languageCode' => $language]),
                'payload'   => ['serviceId' => $serviceId, 'message' => $message, 'patientInfo' => $patientInfo],
                'slot'      => $slot,
                'service'   => $this->catalog->service($serviceId, $language),
            ];
            $draft->merge(['result' => $preview]);
            Log::add('info', 'dry-run booking assembled', [
                'slot_id' => $slotId, 'service_id' => $serviceId, 'dry_run' => true, 'mode' => 'booking',
            ]);
            $this->persist($draft, $preview, $contact, 'booking', 'dry_run');

            return $preview;
        }

        $appointment = $this->booking->request($slotId, $serviceId, $patientInfo, $message, $start !== '' ? $start : null, $language);
        if (is_wp_error($appointment)) {
            // Never lose the enquiry because the booking call failed.
            $this->persist($draft, ['dry_run' => false], $contact, 'booking', 'failed', $appointment->get_error_code());

            if ($this->capabilities->grants('leads')) {
                $fallback = $this->asInquiry($draft, $contact, $message, false, 'booking_failed');
                if (!is_wp_error($fallback)) {
                    return $fallback;
                }
            }

            return $appointment;
        }

        $result = ['mode' => 'booking', 'dry_run' => false, 'appointment' => $appointment];
        $result['attachments'] = $this->sendAttachments($draft, $contact, $appointment);
        $draft->merge(['result' => $result]);
        $this->persist($draft, $result, $contact, 'booking', (string) ($appointment['status'] ?: 'sent'));

        return $result;
    }

    /**
     * The booking API has no upload endpoint, so an X-ray can only reach the
     * clinic through a lead. When files are attached to a booking, a companion
     * lead is created that references the appointment and carries the files.
     *
     * @param array<string,string> $contact
     * @param array<string,mixed>  $appointment
     * @return array{sent:int,failed:int,lead_id:int|null}
     */
    private function sendAttachments(Draft $draft, array $contact, array $appointment): array
    {
        $files = is_array($draft->get('files')) ? $draft->get('files') : [];
        $summary = ['sent' => 0, 'failed' => 0, 'lead_id' => null];

        if ($files === [] || !$this->capabilities->grants('leads')) {
            return $summary;
        }

        $reference = sprintf(
            /* translators: %s: EasyBusy appointment id */
            __('Attachments for appointment #%s', 'easybusy-connect'),
            (string) ($appointment['appointmentId'] ?? '')
        );
        $message = trim($reference . "\n\n" . (string) $draft->get('message', ''));

        $lead = $this->leads->create($contact, $message, [
            'external_id' => $draft->token,
        ]);
        if (is_wp_error($lead) || empty($lead['leadId'])) {
            $summary['failed'] = count($files);

            return $summary;
        }

        $summary['lead_id'] = (int) $lead['leadId'];
        $uploads = $this->uploadTo((int) $lead['leadId'], $draft);

        return ['sent' => $uploads['sent'], 'failed' => $uploads['failed'], 'lead_id' => $summary['lead_id']];
    }

    /**
     * Uploads every staged file to a lead, one call per file, then removes the
     * local copy — medical images must not linger in wp-content.
     *
     * @return array{sent:int,failed:int}
     */
    private function uploadTo(int $leadId, Draft $draft): array
    {
        $files = is_array($draft->get('files')) ? $draft->get('files') : [];
        $summary = ['sent' => 0, 'failed' => 0];

        if ($leadId <= 0 || $files === []) {
            return $summary;
        }

        foreach ($files as $file) {
            $uploaded = $this->leads->upload(
                $leadId,
                (string) $file['path'],
                (string) $file['name'],
                (string) $file['type']
            );
            $uploaded === true ? $summary['sent']++ : $summary['failed']++;
            Uploads::delete((string) $file['path']);
        }

        $draft->merge(['files' => []]);

        return $summary;
    }

    /**
     * @param array<string,string> $contact
     * @return array<string,mixed>|\WP_Error
     */
    private function asInquiry(Draft $draft, array $contact, string $message, bool $dryRun, string $reason): array|\WP_Error
    {
        if (!$this->capabilities->grants('leads')) {
            return new \WP_Error('ebc_no_channel', __('Online requests are temporarily unavailable. Please call the clinic.', 'easybusy-connect'), ['status' => 503]);
        }

        $attribution = is_array($draft->get('attribution')) ? $draft->get('attribution') : [];
        $attribution['external_id'] = $draft->token;

        $preferred = (string) $draft->get('preferred_time', '');
        if ($preferred !== '') {
            $message = trim($message . "\n\n" . __('Preferred time:', 'easybusy-connect') . ' ' . $preferred);
        }

        if ($dryRun) {
            $preview = [
                'dry_run'     => true,
                'mode'        => 'lead',
                'reason'      => $reason,
                'endpoint'    => 'POST /v2/lead',
                'payload'     => $this->leads->payload($contact, $message, $attribution),
                'attachments' => ['staged' => count(is_array($draft->get('files')) ? $draft->get('files') : [])],
            ];
            $draft->merge(['result' => $preview]);
            Log::add('info', 'dry-run lead assembled', ['dry_run' => true, 'mode' => 'lead']);
            $this->persist($draft, $preview, $contact, 'lead', 'dry_run');

            return $preview;
        }

        $lead = $this->leads->create($contact, $message, $attribution);
        if (is_wp_error($lead)) {
            $this->persist($draft, ['dry_run' => false], $contact, 'lead', 'failed', $lead->get_error_code());

            return $lead;
        }

        $result = ['mode' => 'lead', 'dry_run' => false, 'reason' => $reason, 'lead' => $lead];
        $result['attachments'] = $this->uploadTo((int) ($lead['leadId'] ?? 0), $draft);
        $draft->merge(['result' => $result]);
        $this->persist($draft, $result, $contact, 'lead', 'sent');

        return $result;
    }

    /**
     * @param array<string,string> $contact
     * @return array<string,mixed>
     */
    private function patientInfo(array $contact, string $language): array
    {
        $address = array_filter([
            'streetName'   => $contact['streetName'],
            'streetNumber' => $contact['streetNumber'],
            'postalCode'   => $contact['postalCode'],
            'city'         => $contact['city'],
            'countryCode'  => $contact['countryCode'],
        ], static fn (string $value): bool => $value !== '');

        $info = [
            'firstName'    => $contact['firstName'],
            'lastName'     => $contact['lastName'],
            'languageCode' => $language,
            'email'        => ['email' => $contact['email']],
            'phone'        => ['number' => $contact['phone']],
        ];

        // personalId only auto-matches together with address.countryCode; sending
        // one without the other just buries the data in the appointment note.
        if ($contact['personalId'] !== '' && $contact['countryCode'] !== '') {
            $info['personalId'] = $contact['personalId'];
        }
        if ($address !== []) {
            $info['address'] = $address;
        }

        return $info;
    }
}
