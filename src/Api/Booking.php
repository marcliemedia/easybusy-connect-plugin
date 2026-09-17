<?php

declare(strict_types=1);

namespace EasyBusyConnect\Api;

use EasyBusyConnect\Support\Log;
use EasyBusyConnect\Support\Tz;

/**
 * Appointment requests through the "simple booking" group (external systems
 * that hold no patient identity — a website is exactly that).
 *
 * The vendor documentation is inconsistent about the path: the heading says
 * POST /simple-booking/request-slots/{slotId} while its own curl example uses
 * the singular /request-slot/{slotId}. Both are attempted, plural first, and
 * the variant that answers is remembered.
 */
final class Booking
{
    private const PATHS = ['/simple-booking/request-slots/%d', '/simple-booking/request-slot/%d'];
    private const OPTION_PATH = 'ebc_booking_path';

    public function __construct(private Client $client)
    {
    }

    /**
     * @param array<string,mixed> $patientInfo
     * @return array<string,mixed>|\WP_Error
     */
    public function request(int $slotId, int $serviceId, array $patientInfo, string $message, ?string $startTimestamp, string $language): array|\WP_Error
    {
        $body = [
            'serviceId'   => $serviceId,
            'message'     => $message,
            'patientInfo' => $patientInfo,
        ];
        $query = array_filter([
            'startTimestamp' => $startTimestamp,
            'languageCode'   => $language !== '' ? $language : null,
        ]);

        $order = $this->pathOrder();
        $lastError = null;

        foreach ($order as $template) {
            $response = $this->client->post(sprintf($template, $slotId), $body, $query);
            if (!is_wp_error($response)) {
                update_option(self::OPTION_PATH, $template, false);

                return $this->shape(is_array($response) ? $response : []);
            }

            $lastError = $response;
            // Only a routing miss justifies trying the other spelling.
            if (!in_array($response->get_error_code(), ['ebc_not_found', 'ebc_method_not_allowed'], true)) {
                break;
            }
        }

        Log::add('error', 'booking failed', [
            'slot_id'    => $slotId,
            'service_id' => $serviceId,
            'code'       => $lastError?->get_error_code(),
        ]);

        return $lastError ?? new \WP_Error('ebc_booking_failed', __('The appointment could not be requested.', 'easybusy-connect'));
    }

    /** @return array<int,string> */
    private function pathOrder(): array
    {
        $known = (string) get_option(self::OPTION_PATH, '');
        if ($known !== '' && in_array($known, self::PATHS, true)) {
            return array_merge([$known], array_values(array_diff(self::PATHS, [$known])));
        }

        return self::PATHS;
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function shape(array $response): array
    {
        $start = Tz::parse((string) ($response['start'] ?? ''));
        $end = Tz::parse((string) ($response['end'] ?? ''));
        $doctor = is_array($response['doctor'] ?? null) ? $response['doctor'] : [];
        $service = is_array($response['service'] ?? null) ? $response['service'] : [];

        $appointment = [
            'appointmentId' => isset($response['appointmentId']) ? (int) $response['appointmentId'] : null,
            // NEW means "request received", not a confirmed appointment.
            'status'        => (string) ($response['status'] ?? ''),
            'start'         => $start ? Tz::api($start) : null,
            'startLabel'    => $start ? $start->format('d.m.Y H:i') : null,
            'end'           => $end ? Tz::api($end) : null,
            'durationMin'   => isset($response['size']) ? (int) $response['size'] : null,
            'serviceName'   => (string) ($service['name'] ?? ''),
            'doctorName'    => trim(sprintf('%s %s', (string) ($doctor['firstName'] ?? ''), (string) ($doctor['lastName'] ?? ''))),
        ];

        Log::add('info', 'booking created', [
            'appointment_id' => $appointment['appointmentId'],
            'slot_id'        => null,
            'status'         => $appointment['status'],
        ]);

        return $appointment;
    }
}
