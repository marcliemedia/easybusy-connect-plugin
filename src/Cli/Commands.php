<?php

declare(strict_types=1);

namespace EasyBusyConnect\Cli;

use EasyBusyConnect\Api\Capabilities;
use EasyBusyConnect\Api\Catalog;
use EasyBusyConnect\Api\Slots;
use EasyBusyConnect\Form\Definition;
use EasyBusyConnect\Settings;
use EasyBusyConnect\Store\Submissions;
use EasyBusyConnect\Support\Tz;

/**
 * `wp easybusy …` — the acceptance harness for every phase. `check` is the one
 * command that proves the integration end to end without writing anything.
 */
final class Commands
{
    public function __construct(
        private Capabilities $capabilities,
        private Catalog $catalog,
        private Slots $slots,
        private Definition $definition
    ) {
    }

    public static function register(self $commands): void
    {
        \WP_CLI::add_command('easybusy check', [$commands, 'check']);
        \WP_CLI::add_command('easybusy slots', [$commands, 'slotList']);
        \WP_CLI::add_command('easybusy purge-cache', [$commands, 'purge']);
        \WP_CLI::add_command('easybusy entries', [$commands, 'entries']);
        \WP_CLI::add_command('easybusy purge-entries', [$commands, 'purgeEntries']);
    }

    /**
     * Probes the API and prints the capability matrix plus live counts.
     *
     * ## OPTIONS
     *
     * [--refresh]
     * : Re-probe instead of using the cached map.
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public function check(array $args, array $assoc): void
    {
        $map = $this->capabilities->get(isset($assoc['refresh']));

        \WP_CLI::log('API base        : ' . Settings::apiBase());
        \WP_CLI::log('Key source      : ' . Settings::apiKeySource());
        \WP_CLI::log('Vendor reachable: ' . (!empty($map['vendor_up']) ? 'yes' : 'no'));
        \WP_CLI::log('Key valid       : ' . (!empty($map['key_valid']) ? 'yes' : 'no'));
        \WP_CLI::log('Write mode      : ' . (Settings::isDryRun() ? 'dry run' : 'LIVE'));
        \WP_CLI::log('Timezone        : ' . Tz::zone()->getName() . (Tz::hasFixedOffset() ? '  <-- fixed offset, no DST' : ''));
        \WP_CLI::log('Grid            : ' . $this->capabilities->gridMinutes() . ' min');
        \WP_CLI::log('Languages       : ' . implode(', ', array_keys($this->capabilities->languages())));
        \WP_CLI::log('Features        : ' . implode(', ', (array) ($map['features'] ?? [])));

        foreach ((array) ($map['groups'] ?? []) as $group => $granted) {
            \WP_CLI::log(sprintf('Group %-15s: %s', (string) $group, $granted ? 'granted' : 'denied'));
        }

        foreach ((array) ($map['probes'] ?? []) as $endpoint => $status) {
            \WP_CLI::log(sprintf('  %-45s %s', (string) $endpoint, (string) $status));
        }

        \WP_CLI::log('Steps           : ' . implode(' → ', array_column($this->definition->steps(), 'id')));

        $language = $this->capabilities->resolveLanguage();
        $services = $this->catalog->services($language);
        if (is_wp_error($services)) {
            \WP_CLI::warning('services: ' . $services->get_error_message());

            return;
        }

        \WP_CLI::log(sprintf('Services (%s)   : %d', $language, count($services)));
        $totalSlots = 0;
        foreach ($services as $service) {
            $slots = $this->slots->available($service['serviceId'], null, 30, $language);
            $count = is_wp_error($slots) ? 0 : count($slots);
            $totalSlots += $count;
            \WP_CLI::log(sprintf(
                '  #%d %-40s %8s  doctors:%d  slots(30d):%d',
                $service['serviceId'],
                $service['name'],
                $service['price'] !== null ? $service['price'] . ' ' . $service['currency'] : '-',
                count($service['doctors']),
                $count
            ));
        }

        if ($totalSlots === 0 && $this->definition->bookingEnabled()) {
            \WP_CLI::warning('No free slots in the next 30 days — the clinic may not have defined any in EasyBusy.');
        }

        \WP_CLI::success('EasyBusy check complete.');
    }

    /**
     * Prints the slot table exactly as the form sees it.
     *
     * ## OPTIONS
     *
     * --service=<id>
     * : EasyBusy serviceId.
     *
     * [--doctor=<id>]
     * : Restrict to one doctor (ignored unless the clinic supports it).
     *
     * [--days=<n>]
     * : Days to search. Default 14.
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public function slotList(array $args, array $assoc): void
    {
        $serviceId = (int) ($assoc['service'] ?? 0);
        if ($serviceId <= 0) {
            \WP_CLI::error('--service=<id> is required.');
        }

        $grouped = $this->slots->grouped(
            $serviceId,
            isset($assoc['doctor']) ? (int) $assoc['doctor'] : null,
            (int) ($assoc['days'] ?? 14)
        );

        if (is_wp_error($grouped)) {
            \WP_CLI::error($grouped->get_error_message());
        }

        foreach ($grouped['days'] as $day) {
            \WP_CLI::log($day['label']);
            foreach ($day['slots'] as $slot) {
                \WP_CLI::log(sprintf(
                    '  slot %-9d %s  %d min  %s  starts: %s',
                    $slot['slotId'],
                    $slot['time'],
                    $slot['durationMin'],
                    $slot['doctorName'] !== '' ? $slot['doctorName'] : '-',
                    implode(', ', array_column($slot['startOptions'], 'label'))
                ));
            }
        }

        \WP_CLI::success(sprintf('%d slots.', $grouped['total']));
    }

    public function purge(): void
    {
        $this->catalog->flush();
        $this->slots->flush();
        \WP_CLI::success('EasyBusy caches purged.');
    }

    /**
     * Lists locally recorded submissions.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Rows to show. Default 20.
     *
     * [--status=<status>]
     * : Filter by status (dry_run, sent, failed, NEW …).
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public function entries(array $args, array $assoc): void
    {
        $result = Submissions::query([
            'per_page' => (int) ($assoc['limit'] ?? 20),
            'status'   => (string) ($assoc['status'] ?? ''),
        ]);

        \WP_CLI::log(sprintf('%d entries stored.', $result['total']));
        foreach ($result['rows'] as $row) {
            \WP_CLI::log(sprintf(
                '#%-5d %-19s %-8s %-8s ref:%-8s %-24s %-16s %s',
                (int) $row['id'],
                (string) $row['created_at'],
                (string) $row['type'],
                (string) $row['status'],
                (string) ($row['easybusy_id'] ?: '-'),
                (string) $row['service_name'],
                (string) ($row['start_at'] ?: '-'),
                trim(implode(' ', array_filter([
                    (string) ($row['patient_name'] ?: ($row['patient_initials'] ?? '')),
                    (string) ($row['patient_phone'] ?: ($row['phone_hint'] ?? '')),
                ])))
            ));
        }
    }

    public function purgeEntries(): void
    {
        $deleted = Submissions::purge();
        \WP_CLI::success(sprintf('%d entries past retention deleted.', $deleted));
    }
}
