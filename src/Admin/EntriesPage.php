<?php

declare(strict_types=1);

namespace EasyBusyConnect\Admin;

use EasyBusyConnect\Settings;
use EasyBusyConnect\Store\Submissions;

/**
 * EasyBusy → Entries. The clinic's own view of what the website sent: headline
 * numbers, filters, one row per submission and an expandable detail strip with
 * the technical facts (EasyBusy id, slot, attribution, error).
 *
 * What a row shows of the person depends on `store_mode`; in the default
 * `minimal` mode the identifying columns were never written, so the list works
 * with initials, an e-mail domain and a masked phone. Uses the plugin's own
 * shell instead of WP_List_Table so the whole admin area looks like one product.
 */
final class EntriesPage
{
    private const PER_PAGE = 20;

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $status = sanitize_text_field((string) ($_GET['status'] ?? ''));
        $type = sanitize_text_field((string) ($_GET['type'] ?? ''));
        $search = sanitize_text_field((string) ($_GET['s'] ?? ''));
        $since = (int) ($_GET['since'] ?? 0);

        $args = [
            'page'       => $page,
            'per_page'   => self::PER_PAGE,
            'status'     => $status,
            'type'       => $type,
            'search'     => $search,
            'since_days' => $since,
        ];

        $result = Submissions::query($args);
        $stats = Submissions::stats();
        $counts = Submissions::counts();
        $returning = Submissions::returningHashes();
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        // ?paged=99 on a two-page list would otherwise render an empty table.
        if ($page > $pages) {
            $page = $pages;
            $args['page'] = $page;
            $result = Submissions::query($args);
        }

        $mode = Settings::storeMode();

        echo '<div class="ebc-admin">';
        Shell::header(
            __('Bookings & inquiries', 'easybusy-connect'),
            $stats['last'] !== null
                ? sprintf(
                    /* translators: %s: human time difference */
                    __('Last request %s ago', 'easybusy-connect'),
                    human_time_diff(strtotime((string) $stats['last']), current_time('timestamp'))
                )
                : __('No requests have arrived yet', 'easybusy-connect'),
            $this->pills($stats, $mode)
        );
        Shell::flash();
        $this->renderNotes($mode);

        echo '<div class="ebc-panel is-active" data-ebc-panel="entries">';
        $this->renderTiles($stats);

        Shell::cardOpen(__('Requests', 'easybusy-connect'));
        $this->renderToolbar($status, $type, $search, $since, $counts, $args);
        $this->renderTable($result['rows'], $returning, $search !== '' || $status !== '' || $type !== '' || $since > 0);
        $this->renderPagination($page, $pages, (int) $result['total'], $args);
        Shell::cardClose();

        Shell::cardOpen(
            __('Retention', 'easybusy-connect'),
            sprintf(
                /* translators: %d: retention days */
                __('Rows older than %d days are deleted by the daily cron. Health-adjacent data should not accumulate.', 'easybusy-connect'),
                (int) Settings::get('retention_days', 90)
            )
        );
        printf(
            '<a class="ebc-btn ebc-btn--danger" href="%s" data-ebc-confirm="%s">%s</a>',
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=ebc_purge_entries'), 'ebc_purge_entries')),
            esc_attr__('Delete every entry older than the retention window? This cannot be undone.', 'easybusy-connect'),
            esc_html__('Purge entries past retention now', 'easybusy-connect')
        );
        printf(
            '<p class="ebc-help">%s</p>',
            esc_html__('Deletes rows permanently — there is no undo and no export afterwards. Export the CSV first if the clinic still needs the numbers.', 'easybusy-connect')
        );
        Shell::cardClose();
        Shell::credit();
        echo '</div></div>';
    }

    /**
     * @param array<string,mixed> $stats
     */
    private function pills(array $stats, string $mode): string
    {
        $pills = [];
        if ((int) $stats['failed'] > 0) {
            $pills[] = Shell::pill(sprintf(__('%d failed', 'easybusy-connect'), (int) $stats['failed']), 'error');
        }
        if (Settings::isDryRun()) {
            $pills[] = Shell::pill(__('Test mode', 'easybusy-connect'), 'warn');
        }
        $pills[] = Shell::pill($this->modeLabel($mode), 'muted');

        return implode('', $pills);
    }

    private function modeLabel(string $mode): string
    {
        return match ($mode) {
            'full'  => __('Storing full contact', 'easybusy-connect'),
            'none'  => __('Storing no personal data', 'easybusy-connect'),
            default => __('Storing minimal data', 'easybusy-connect'),
        };
    }

    private function renderNotes(string $mode): void
    {
        if (Settings::isDryRun()) {
            printf(
                '<div class="ebc-flash ebc-flash--warn" role="status">%s</div>',
                esc_html__('Test mode is on: these requests were validated locally, but nothing was sent to EasyBusy and no e-mail went out.', 'easybusy-connect')
            );
        }

        $note = match ($mode) {
            'full'  => __('Full contact details are stored in this table. Keep the retention window short and the user list small.', 'easybusy-connect'),
            'none'  => __('Nothing about the person is stored: rows show the request only. Change this in Settings → Privacy & data.', 'easybusy-connect'),
            default => __('Privacy-first storage: rows keep initials, the e-mail provider and a masked phone number — enough to review and count leads, not to identify or contact anyone. The message text is never stored, only its length.', 'easybusy-connect'),
        };

        printf('<div class="ebc-flash ebc-flash--info" role="status">%s</div>', esc_html($note));
    }

    /** @param array<string,mixed> $stats */
    private function renderTiles(array $stats): void
    {
        $tiles = [
            [__('Requests total', 'easybusy-connect'), (string) (int) $stats['total'], ''],
            [__('Last 7 days', 'easybusy-connect'), (string) (int) $stats['week'], ''],
            [__('Last 30 days', 'easybusy-connect'), (string) (int) $stats['month'], ''],
            [__('People (deduplicated)', 'easybusy-connect'), (string) (int) $stats['people'], 'accent'],
            [__('Returning', 'easybusy-connect'), (string) (int) $stats['returning'], 'accent'],
            [__('Failed sends', 'easybusy-connect'), (string) (int) $stats['failed'], (int) $stats['failed'] > 0 ? 'error' : ''],
        ];

        echo '<div class="ebc-tiles">';
        foreach ($tiles as [$label, $value, $tone]) {
            printf(
                '<div class="ebc-tile%s"><span class="ebc-tile__value">%s</span><span class="ebc-tile__label">%s</span></div>',
                $tone !== '' ? ' ebc-tile--' . esc_attr($tone) : '',
                esc_html($value),
                esc_html($label)
            );
        }
        echo '</div>';
    }

    /**
     * @param array<string,int>   $counts
     * @param array<string,mixed> $args
     */
    private function renderToolbar(string $status, string $type, string $search, int $since, array $counts, array $args): void
    {
        $base = Menu::url();
        $keep = array_filter([
            'type'  => $type,
            's'     => $search,
            'since' => $since > 0 ? (string) $since : '',
        ]);

        echo '<div class="ebc-toolbar">';

        echo '<div class="ebc-chips" role="group" aria-label="' . esc_attr__('Filter by status', 'easybusy-connect') . '">';
        printf(
            '<a class="ebc-chip%s" href="%s">%s <b>%d</b></a>',
            $status === '' ? ' is-active' : '',
            esc_url(add_query_arg($keep, $base)),
            esc_html__('All', 'easybusy-connect'),
            array_sum($counts)
        );
        foreach ($counts as $known => $total) {
            printf(
                '<a class="ebc-chip%s" href="%s">%s <b>%d</b></a>',
                $status === (string) $known ? ' is-active' : '',
                esc_url(add_query_arg($keep + ['status' => (string) $known], $base)),
                esc_html($this->statusLabel((string) $known)),
                (int) $total
            );
        }
        echo '</div>';

        echo '<form method="get" class="ebc-filters">';
        printf('<input type="hidden" name="page" value="%s">', esc_attr(Menu::SLUG));
        printf('<input type="hidden" name="status" value="%s">', esc_attr($status));

        echo '<select class="ebc-input ebc-input--auto" name="type" aria-label="' . esc_attr__('Request type', 'easybusy-connect') . '">';
        foreach ([
            ''        => __('All types', 'easybusy-connect'),
            'booking' => __('Appointments', 'easybusy-connect'),
            'lead'    => __('Inquiries', 'easybusy-connect'),
        ] as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr((string) $value), selected($type, (string) $value, false), esc_html($label));
        }
        echo '</select>';

        echo '<select class="ebc-input ebc-input--auto" name="since" aria-label="' . esc_attr__('Time range', 'easybusy-connect') . '">';
        foreach ([
            0  => __('Any time', 'easybusy-connect'),
            1  => __('Today', 'easybusy-connect'),
            7  => __('Last 7 days', 'easybusy-connect'),
            30 => __('Last 30 days', 'easybusy-connect'),
        ] as $value => $label) {
            printf('<option value="%d"%s>%s</option>', $value, selected($since, $value, false), esc_html($label));
        }
        echo '</select>';

        printf(
            '<input class="ebc-input ebc-input--search" type="search" name="s" value="%s" placeholder="%s" aria-label="%s">',
            esc_attr($search),
            esc_attr__('Search service, specialist, reference…', 'easybusy-connect'),
            esc_attr__('Search entries', 'easybusy-connect')
        );
        printf('<button type="submit" class="ebc-btn ebc-btn--primary">%s</button>', esc_html__('Filter', 'easybusy-connect'));
        printf(
            '<a class="ebc-btn" href="%s">%s</a>',
            esc_url(wp_nonce_url(
                add_query_arg(
                    array_filter([
                        'action' => 'ebc_export_entries',
                        'status' => $status,
                        'type'   => $type,
                        's'      => $search,
                        'since'  => $since > 0 ? (string) $since : '',
                    ]),
                    admin_url('admin-post.php')
                ),
                'ebc_export_entries'
            )),
            esc_html__('Export CSV', 'easybusy-connect')
        );
        echo '</form></div>';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'sent'    => __('Sent', 'easybusy-connect'),
            'failed'  => __('Failed', 'easybusy-connect'),
            'dry_run' => __('Test', 'easybusy-connect'),
            'new'     => __('New', 'easybusy-connect'),
            default   => $status,
        };
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,int>              $returning
     */
    private function renderTable(array $rows, array $returning, bool $filtered): void
    {
        if ($rows === []) {
            printf(
                '<div class="ebc-empty"><span class="ebc-empty__mark" aria-hidden="true">📋</span><p class="ebc-empty__title">%s</p><p class="ebc-empty__hint">%s</p></div>',
                esc_html($filtered ? __('No requests match these filters.', 'easybusy-connect') : __('No requests yet.', 'easybusy-connect')),
                esc_html($filtered
                    ? __('Clear the filters to see everything that arrived.', 'easybusy-connect')
                    : __('Every submission from the booking form lands here — including failed ones, so nothing is lost when EasyBusy is unreachable.', 'easybusy-connect'))
            );

            return;
        }

        echo '<div class="ebc-table-wrap"><table class="ebc-table ebc-table--entries"><thead><tr>';
        foreach ([
            ['', 'ebc-table__col-toggle'],
            [__('Received', 'easybusy-connect'), ''],
            [__('Request', 'easybusy-connect'), ''],
            [__('Requested time', 'easybusy-connect'), ''],
            [__('Lead', 'easybusy-connect'), ''],
            [__('Status', 'easybusy-connect'), ''],
        ] as [$heading, $class]) {
            printf('<th%s>%s</th>', $class !== '' ? ' class="' . esc_attr($class) . '"' : '', esc_html($heading));
        }
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $this->renderRow($row, $returning);
        }

        echo '</tbody></table></div>';
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,int>   $returning
     */
    private function renderRow(array $row, array $returning): void
    {
        $id = (int) $row['id'];
        $created = (string) $row['created_at'];
        $timestamp = strtotime($created) ?: time();

        printf('<tr class="ebc-row" data-ebc-row="%d">', $id);

        printf(
            '<td class="ebc-table__col-toggle"><button type="button" class="ebc-row__toggle" data-ebc-toggle="%1$d" aria-expanded="false" aria-controls="ebc-detail-%1$d">' .
            '<span class="screen-reader-text">%2$s</span><span aria-hidden="true">›</span></button></td>',
            $id,
            esc_html__('Show details', 'easybusy-connect')
        );

        printf(
            '<td><span class="ebc-cell__main">%s</span><span class="ebc-cell__sub">%s</span></td>',
            esc_html(date_i18n('j M Y, H:i', $timestamp)),
            esc_html(sprintf(
                /* translators: %s: human time difference */
                __('%s ago', 'easybusy-connect'),
                human_time_diff($timestamp, current_time('timestamp'))
            ))
        );

        // "Appointment" already has a Croatian translation used by the form, and
        // this screen is English — pick admin-only wording.
        $typeLabel = (string) $row['type'] === 'lead'
            ? __('Inquiry (lead)', 'easybusy-connect')
            : __('Booking request', 'easybusy-connect');
        printf(
            '<td><span class="ebc-cell__main">%s</span><span class="ebc-cell__sub">%s%s</span></td>',
            esc_html((string) ($row['service_name'] ?: sprintf('#%d', (int) $row['service_id']))),
            esc_html($typeLabel),
            (string) $row['doctor_name'] !== '' ? ' · ' . esc_html((string) $row['doctor_name']) : ''
        );

        $start = (string) ($row['start_at'] ?? '');
        if ($start !== '') {
            $startTs = strtotime($start) ?: 0;
            printf(
                '<td><span class="ebc-cell__main">%s</span><span class="ebc-cell__sub">%s</span></td>',
                esc_html(date_i18n('j M Y, H:i', $startTs)),
                esc_html((int) $row['duration_min'] > 0 ? sprintf(__('%d min', 'easybusy-connect'), (int) $row['duration_min']) : '—')
            );
        } else {
            printf('<td><span class="ebc-cell__sub">%s</span></td>', esc_html__('no slot (inquiry)', 'easybusy-connect'));
        }

        printf('<td>%s</td>', $this->leadCell($row, $returning));
        printf('<td>%s</td>', $this->statusChip($row));
        echo '</tr>';

        $this->renderDetail($row);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,int>   $returning
     */
    private function leadCell(array $row, array $returning): string
    {
        $mode = (string) ($row['store_mode'] ?? 'minimal');

        if ($mode === 'full' && (string) $row['patient_name'] !== '') {
            $out = sprintf('<span class="ebc-cell__main">%s</span>', esc_html((string) $row['patient_name']));
            $contact = array_filter([(string) $row['patient_email'], (string) $row['patient_phone']]);
            if ($contact !== []) {
                $out .= sprintf('<span class="ebc-cell__sub">%s</span>', esc_html(implode(' · ', $contact)));
            }

            return $out . $this->returningBadge($row, $returning);
        }

        $initials = (string) ($row['patient_initials'] ?? '');
        $hint = array_filter([
            (string) ($row['email_domain'] ?? ''),
            (string) ($row['phone_hint'] ?? ''),
        ]);

        if ($initials === '' && $hint === []) {
            return sprintf('<span class="ebc-cell__sub">%s</span>', esc_html__('not stored', 'easybusy-connect'));
        }

        return sprintf(
            '<span class="ebc-cell__main"><span class="ebc-avatar" aria-hidden="true">%s</span>%s</span>%s%s',
            esc_html($initials !== '' ? mb_substr($initials, 0, 1) : '?'),
            esc_html($initials !== '' ? $initials : __('anonymous', 'easybusy-connect')),
            $hint === [] ? '' : sprintf('<span class="ebc-cell__sub">%s</span>', esc_html(implode(' · ', $hint))),
            $this->returningBadge($row, $returning)
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,int>   $returning
     */
    private function returningBadge(array $row, array $returning): string
    {
        $hash = (string) ($row['contact_hash'] ?? '');
        if ($hash === '' || !isset($returning[$hash])) {
            return '';
        }

        return sprintf(
            '<span class="ebc-badge ebc-badge--accent" title="%s">%s</span>',
            esc_attr__('The same e-mail or phone appears on more than one request', 'easybusy-connect'),
            esc_html(sprintf(__('returning · %d×', 'easybusy-connect'), (int) $returning[$hash]))
        );
    }

    /** @param array<string,mixed> $row */
    private function statusChip(array $row): string
    {
        $status = (string) $row['status'];
        $tone = match ($status) {
            'failed'  => 'error',
            'dry_run' => 'warn',
            'sent'    => 'ok',
            default   => 'muted',
        };

        $chip = sprintf(
            '<span class="ebc-badge ebc-badge--%s"><span class="ebc-badge__dot" aria-hidden="true"></span>%s</span>',
            esc_attr($tone),
            esc_html($this->statusLabel($status))
        );

        if ((string) $row['error_code'] !== '') {
            $chip .= sprintf('<span class="ebc-cell__sub ebc-cell__sub--mono">%s</span>', esc_html((string) $row['error_code']));
        } elseif ((int) $row['easybusy_id'] > 0) {
            $chip .= sprintf(
                '<span class="ebc-cell__sub ebc-cell__sub--mono">%s</span>',
                esc_html(sprintf(__('EB #%d', 'easybusy-connect'), (int) $row['easybusy_id']))
            );
        }

        return $chip;
    }

    /** @param array<string,mixed> $row */
    private function renderDetail(array $row): void
    {
        $id = (int) $row['id'];
        $attribution = json_decode((string) ($row['attribution'] ?? ''), true);
        $messageLen = $row['message_len'] === null ? 0 : (int) $row['message_len'];

        $facts = array_filter([
            __('EasyBusy reference', 'easybusy-connect') => (int) $row['easybusy_id'] > 0 ? '#' . (int) $row['easybusy_id'] : '',
            __('Slot id', 'easybusy-connect')            => (int) $row['slot_id'] > 0 ? (string) (int) $row['slot_id'] : '',
            __('Service id', 'easybusy-connect')         => (int) $row['service_id'] > 0 ? (string) (int) $row['service_id'] : '',
            __('Specialist', 'easybusy-connect')         => (string) $row['doctor_name'],
            __('Form language', 'easybusy-connect')      => (string) $row['language'],
            __('Consent given', 'easybusy-connect')      => (string) ($row['consent_at'] ?? ''),
            __('Message', 'easybusy-connect')            => (string) $row['message'] !== ''
                ? (string) $row['message']
                : ($messageLen > 0
                    ? sprintf(__('%d characters — text not stored', 'easybusy-connect'), $messageLen)
                    : __('none', 'easybusy-connect')),
            __('Error', 'easybusy-connect')              => (string) $row['error_code'],
            __('Draft token', 'easybusy-connect')        => (string) $row['draft_token'],
            __('Stored as', 'easybusy-connect')          => $this->modeLabel((string) ($row['store_mode'] ?? 'minimal')),
        ], static fn ($value): bool => (string) $value !== '');

        printf('<tr class="ebc-detail" id="ebc-detail-%d" hidden><td colspan="6"><div class="ebc-detail__grid">', $id);
        foreach ($facts as $label => $value) {
            printf(
                '<div class="ebc-detail__item"><span class="ebc-detail__label">%s</span><span class="ebc-detail__value">%s</span></div>',
                esc_html((string) $label),
                esc_html((string) $value)
            );
        }
        echo '</div>';

        if (is_array($attribution) && $attribution !== []) {
            echo '<div class="ebc-detail__grid ebc-detail__grid--attribution">';
            foreach ($attribution as $key => $value) {
                if (is_array($value) || (string) $value === '') {
                    continue;
                }
                printf(
                    '<div class="ebc-detail__item"><span class="ebc-detail__label">%s</span><span class="ebc-detail__value">%s</span></div>',
                    esc_html((string) $key),
                    esc_html(mb_strimwidth((string) $value, 0, 140, '…'))
                );
            }
            echo '</div>';
        }

        echo '</td></tr>';
    }

    /** @param array<string,mixed> $args */
    private function renderPagination(int $page, int $pages, int $total, array $args): void
    {
        Shell::pagination($page, $pages, $total, self::PER_PAGE, Menu::url(Menu::SLUG, array_filter([
            'status' => (string) $args['status'],
            'type'   => (string) $args['type'],
            's'      => (string) $args['search'],
            'since'  => (int) $args['since_days'] > 0 ? (string) (int) $args['since_days'] : '',
        ])));
    }

    public function handlePurge(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'easybusy-connect'));
        }
        check_admin_referer('ebc_purge_entries');

        Submissions::purge();
        wp_safe_redirect(Menu::url(Menu::SLUG, ['ebc-flash' => 'purged-entries']));
        exit;
    }

    /**
     * CSV of exactly what is stored — no column is reconstructed, so a minimal
     * export carries no identifying data either.
     */
    public function handleExport(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'easybusy-connect'));
        }
        check_admin_referer('ebc_export_entries');

        $rows = Submissions::exportRows([
            'status'     => sanitize_text_field((string) ($_GET['status'] ?? '')),
            'type'       => sanitize_text_field((string) ($_GET['type'] ?? '')),
            'search'     => sanitize_text_field((string) ($_GET['s'] ?? '')),
            'since_days' => (int) ($_GET['since'] ?? 0),
        ]);

        $columns = [
            'created_at', 'type', 'status', 'dry_run', 'easybusy_id', 'service_id', 'service_name',
            'doctor_name', 'start_at', 'duration_min', 'language', 'store_mode', 'patient_initials',
            'email_domain', 'phone_hint', 'message_len', 'consent_at', 'error_code',
        ];
        if (Settings::storeMode() === 'full') {
            array_push($columns, 'patient_name', 'patient_email', 'patient_phone');
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=easybusy-entries-' . gmdate('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excel needs the BOM to read UTF-8.
        fputcsv($out, $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = (string) ($row[$column] ?? '');
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }
}
