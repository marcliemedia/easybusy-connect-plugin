<?php

declare(strict_types=1);

namespace EasyBusyConnect\Api;

use EasyBusyConnect\Settings;
use EasyBusyConnect\Support\Tz;

/**
 * Free slots for step 3. Cached for seconds only: a stale slotId produces a
 * failed booking after the patient has filled in every step.
 */
final class Slots
{
    public function __construct(private Client $client, private Capabilities $capabilities)
    {
    }

    /**
     * @return array<int,array<string,mixed>>|\WP_Error Raw slot rows, normalised.
     */
    public function available(int $serviceId, ?int $doctorId = null, ?int $days = null, ?string $language = null): array|\WP_Error
    {
        $language = $language ?? $this->capabilities->resolveLanguage();
        $days = $days ?? (int) Settings::get('slot_horizon', 60);
        [$from, $to] = Tz::searchWindow($days);

        $byDoctor = $this->capabilities->supportsBookingByDoctor() ? $doctorId : null;
        $key = sprintf('ebc_slots_%d_%d_%d_%s', $serviceId, (int) $byDoctor, $days, $language === '' ? 'default' : $language);

        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->client->get('/simple-booking/available-slots', [
            'languageCode' => $language,
            'from'         => Tz::api($from),
            'to'           => Tz::api($to),
            'serviceId'    => $serviceId,
            'doctorId'     => $byDoctor,
        ]);
        if (is_wp_error($rows)) {
            return $rows;
        }

        $slots = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row) || !isset($row['slotId'])) {
                continue;
            }
            $start = Tz::parse((string) ($row['slotStart'] ?? ''));
            $end = Tz::parse((string) ($row['slotEnd'] ?? ''));
            if ($start === null || $end === null) {
                continue;
            }

            $doctor = is_array($row['doctor'] ?? null) ? $row['doctor'] : [];
            $service = is_array($row['service'] ?? null) ? $row['service'] : [];

            $slots[] = [
                'slotId'           => (int) $row['slotId'],
                'start'            => Tz::api($start),
                'end'              => Tz::api($end),
                'date'             => $start->format('Y-m-d'),
                'time'             => $start->format('H:i'),
                'slotSize'         => (int) ($row['slotSize'] ?? 0),
                'requiredSlotSize' => (int) ($row['requiredSlotSize'] ?? 0),
                'serviceId'        => (int) ($service['serviceId'] ?? $serviceId),
                'doctorId'         => isset($doctor['doctorId']) ? (int) $doctor['doctorId'] : null,
                'doctorName'       => trim(sprintf(
                    '%s %s %s',
                    (string) ($doctor['prefix'] ?? ''),
                    (string) ($doctor['firstName'] ?? ''),
                    (string) ($doctor['lastName'] ?? '')
                )),
            ];
        }

        usort($slots, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
        set_transient($key, $slots, (int) Settings::get('ttl_slots', 60));

        return $slots;
    }

    /**
     * Step 3 view model: slots grouped by day, each with the start times a
     * patient may actually pick. The offered duration is requiredSlotSize —
     * slotSize is the container, and a 120-minute slot can hold a 30-minute
     * appointment plus a remainder the clinic re-splits.
     *
     * @return array{days:array<int,array<string,mixed>>,total:int}|\WP_Error
     */
    public function grouped(int $serviceId, ?int $doctorId = null, ?int $days = null, ?string $language = null): array|\WP_Error
    {
        $slots = $this->available($serviceId, $doctorId, $days, $language);
        if (is_wp_error($slots)) {
            return $slots;
        }

        $grid = $this->capabilities->gridMinutes();
        $byDate = [];

        foreach ($slots as $slot) {
            $start = Tz::parse($slot['start']);
            $end = Tz::parse($slot['end']);
            if ($start === null || $end === null) {
                continue;
            }

            $required = $slot['requiredSlotSize'] > 0 ? $slot['requiredSlotSize'] : $slot['slotSize'];
            $byDate[$slot['date']] ??= ['date' => $slot['date'], 'label' => $start->format('D, d.m.Y'), 'slots' => []];
            $byDate[$slot['date']]['slots'][] = [
                'slotId'       => $slot['slotId'],
                'time'         => $slot['time'],
                'durationMin'  => $required,
                'doctorId'     => $slot['doctorId'],
                'doctorName'   => $slot['doctorName'],
                'startOptions' => Tz::startOptions($start, $end, $required, $grid),
            ];
        }

        ksort($byDate);

        return ['days' => array_values($byDate), 'total' => count($slots)];
    }

    /**
     * Re-reads the live slot list to confirm a chosen slot still exists and
     * belongs to the chosen service/doctor. Run immediately before submitting:
     * the booking payload is rebuilt server-side from trusted data only.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function verify(int $slotId, int $serviceId, ?int $doctorId, ?string $language = null): array|\WP_Error
    {
        $slots = $this->available($serviceId, $doctorId, null, $language);
        if (is_wp_error($slots)) {
            return $slots;
        }

        foreach ($slots as $slot) {
            if ($slot['slotId'] === $slotId) {
                return $slot;
            }
        }

        return new \WP_Error('ebc_slot_gone', __('That appointment time has just been taken. Please pick another one.', 'easybusy-connect'), ['status' => 409]);
    }

    public function flush(): void
    {
        global $wpdb;

        if (isset($wpdb) && method_exists($wpdb, 'query')) {
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_ebc\_slots\_%' OR option_name LIKE '\_transient\_timeout\_ebc\_slots\_%'");
        }
    }
}
