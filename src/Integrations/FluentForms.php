<?php

declare(strict_types=1);

namespace EasyBusyConnect\Integrations;

use EasyBusyConnect\Api\Capabilities;
use EasyBusyConnect\Api\Leads;
use EasyBusyConnect\Settings;
use EasyBusyConnect\Store\Submissions;
use EasyBusyConnect\Support\Log;

/**
 * Forwards existing Fluent Forms submissions to EasyBusy as leads, so the
 * contact form the site already has feeds the clinic's CRM without being
 * rebuilt. Opt-in per form id: forwarding every form by default would push
 * newsletter sign-ups into a patient pipeline.
 *
 * The forward runs on a scheduled single event, never inline — a slow or failing
 * API call must not delay the visitor's form response.
 */
final class FluentForms
{
    public const HOOK = 'ebc_forward_fluent_submission';

    public function __construct(private Capabilities $capabilities, private Leads $leads)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'forward'], 10, 1);

        if ($this->enabledForms() === []) {
            return;
        }

        // Fluent Forms fires both spellings depending on version.
        add_action('fluentform/submission_inserted', [$this, 'capture'], 20, 3);
        add_action('fluentform_submission_inserted', [$this, 'capture'], 20, 3);
    }

    /** @return array<int,int> */
    public function enabledForms(): array
    {
        $raw = (string) Settings::get('ff_forms', '');

        return array_values(array_filter(array_map('intval', preg_split('/[^0-9]+/', $raw) ?: [])));
    }

    /**
     * @param array<string,mixed>|object $formData
     * @param object|array<string,mixed>  $form
     */
    public function capture(int $insertId, $formData, $form): void
    {
        $formId = (int) (is_object($form) ? ($form->id ?? 0) : ($form['id'] ?? 0));
        if (!in_array($formId, $this->enabledForms(), true)) {
            return;
        }

        static $seen = [];
        if (isset($seen[$insertId])) {
            return; // both hook spellings fired
        }
        $seen[$insertId] = true;

        $data = is_object($formData) ? (array) $formData : (array) $formData;
        $payload = [
            'form_id'   => $formId,
            'entry_id'  => $insertId,
            'fields'    => $this->map($data),
            'page_url'  => (string) ($data['__ff_page_url'] ?? ($_SERVER['HTTP_REFERER'] ?? '')),
            'referer'   => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
            'agent'     => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ];

        wp_schedule_single_event(time() + 5, self::HOOK, [$payload]);
        Log::add('info', 'fluent submission queued', ['step' => 'ff_capture', 'count' => $formId]);
    }

    /** @param array<string,mixed> $payload */
    public function forward(array $payload): void
    {
        if (!$this->capabilities->grants('leads')) {
            Log::add('warning', 'fluent forward skipped (leads not granted)', ['step' => 'ff_forward']);

            return;
        }

        $fields = (array) ($payload['fields'] ?? []);
        $contact = [
            'firstName'   => (string) ($fields['firstName'] ?? ''),
            'lastName'    => (string) ($fields['lastName'] ?? ''),
            'email'       => (string) ($fields['email'] ?? ''),
            'phone'       => (string) preg_replace('/\D+/', '', (string) ($fields['phone'] ?? '')),
            'city'        => (string) ($fields['city'] ?? ''),
            'countryCode' => '',
        ];

        // A submission with no way to reach the patient is not a lead.
        if ($contact['email'] === '' && $contact['phone'] === '') {
            Log::add('warning', 'fluent forward skipped (no contact)', ['step' => 'ff_forward']);

            return;
        }

        $message = trim((string) ($fields['message'] ?? ''));
        $subject = trim((string) ($fields['subject'] ?? ''));
        if ($subject !== '') {
            $message = $subject . "\n\n" . $message;
        }

        $attribution = [
            'contact_page' => (string) ($payload['page_url'] ?? ''),
            'referer'      => (string) ($payload['referer'] ?? ''),
            'landing'      => '',
            'external_id'  => 'ff-' . (int) ($payload['form_id'] ?? 0) . '-' . (int) ($payload['entry_id'] ?? 0),
        ];

        $row = [
            'type'          => 'lead',
            'service_name'  => sprintf(__('Fluent Forms #%d', 'easybusy-connect'), (int) ($payload['form_id'] ?? 0)),
            'patient_name'  => trim($contact['firstName'] . ' ' . $contact['lastName']),
            'patient_email' => $contact['email'],
            'patient_phone' => $contact['phone'],
            'message'       => $message,
            'attribution'   => $attribution,
            'draft_token'   => '',
        ];

        if (Settings::isDryRun()) {
            Submissions::record($row + ['status' => 'dry_run', 'dry_run' => true]);
            Log::add('info', 'fluent forward assembled (dry run)', ['step' => 'ff_forward', 'dry_run' => true]);

            return;
        }

        $lead = $this->leads->create($contact, $message, $attribution);
        if (is_wp_error($lead)) {
            Submissions::record($row + ['status' => 'failed', 'error_code' => $lead->get_error_code()]);

            return;
        }

        Submissions::record($row + ['status' => 'sent', 'easybusy_id' => $lead['leadId'] ?? null]);
    }

    /**
     * Fluent Forms field names are per-form. The common shapes on this site are
     * handled, and anything unknown is folded into the message so no information
     * is silently dropped.
     *
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function map(array $data): array
    {
        $names = $data['names'] ?? [];
        $names = is_array($names) ? $names : [];

        $flat = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $flat[(string) $key] = (string) $value;
            }
        }

        $first = (string) ($names['first_name'] ?? $flat['first_name'] ?? '');
        $last = (string) ($names['last_name'] ?? $flat['last_name'] ?? '');
        if ($first === '' && isset($flat['name'])) {
            $parts = preg_split('/\s+/', trim($flat['name']), 2) ?: [];
            $first = (string) ($parts[0] ?? '');
            $last = (string) ($parts[1] ?? '');
        }

        $known = ['names', 'first_name', 'last_name', 'middle_name', 'email', 'phone', 'message', 'subject', 'dropdown', 'city', 'gdpr', '__ff_page_url'];
        $extra = [];
        foreach ($flat as $key => $value) {
            if (!in_array($key, $known, true) && $value !== '' && !str_starts_with($key, '_')) {
                $extra[] = $key . ': ' . $value;
            }
        }

        $message = (string) ($flat['message'] ?? '');
        if ($extra !== []) {
            $message = trim($message . "\n\n" . implode("\n", $extra));
        }

        return [
            'firstName' => $first,
            'lastName'  => $last,
            'email'     => (string) ($flat['email'] ?? ''),
            'phone'     => (string) ($flat['phone'] ?? ''),
            'city'      => (string) ($flat['city'] ?? ''),
            'subject'   => (string) ($flat['dropdown'] ?? $flat['subject'] ?? ''),
            'message'   => $message,
        ];
    }
}
