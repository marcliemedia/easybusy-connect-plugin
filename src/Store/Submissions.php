<?php

declare(strict_types=1);

namespace EasyBusyConnect\Store;

use EasyBusyConnect\Settings;

/**
 * Local record of every submission, so the clinic can see what the website sent
 * even when EasyBusy is unreachable and so a failed send can be diagnosed.
 *
 * This is health-adjacent data, so what a row keeps of the person depends on
 * `store_mode` (Settings → Privacy & data):
 *
 *   minimal (default) — initials, e-mail domain, masked phone, message length.
 *                       Enough to review the lead, count it, spot a returning
 *                       visitor and match it against EasyBusy; not enough to
 *                       identify or contact the patient from this table.
 *   full              — the clinic's working copy: name, e-mail, phone, message.
 *   none              — nothing about the person at all.
 *
 * OIB is never stored in any mode, and rows are purged after the retention
 * window. The masking happens on write, so the database never holds what the
 * current mode excludes.
 */
final class Submissions
{
    public const DB_VERSION = '2';
    public const VERSION_OPTION = 'ebc_db_version';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ebc_submissions';
    }

    public static function install(): void
    {
        global $wpdb;

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            type varchar(16) NOT NULL DEFAULT 'booking',
            status varchar(24) NOT NULL DEFAULT 'new',
            dry_run tinyint(1) NOT NULL DEFAULT 0,
            easybusy_id bigint(20) unsigned DEFAULT NULL,
            service_id bigint(20) unsigned DEFAULT NULL,
            service_name varchar(191) NOT NULL DEFAULT '',
            doctor_id bigint(20) unsigned DEFAULT NULL,
            doctor_name varchar(191) NOT NULL DEFAULT '',
            slot_id bigint(20) unsigned DEFAULT NULL,
            start_at datetime DEFAULT NULL,
            duration_min smallint(5) unsigned DEFAULT NULL,
            language varchar(8) NOT NULL DEFAULT '',
            patient_name varchar(191) NOT NULL DEFAULT '',
            patient_email varchar(191) NOT NULL DEFAULT '',
            patient_phone varchar(40) NOT NULL DEFAULT '',
            message text NULL,
            store_mode varchar(8) NOT NULL DEFAULT 'minimal',
            patient_initials varchar(16) NOT NULL DEFAULT '',
            email_domain varchar(64) NOT NULL DEFAULT '',
            phone_hint varchar(32) NOT NULL DEFAULT '',
            contact_hash char(64) NOT NULL DEFAULT '',
            message_len smallint(5) unsigned DEFAULT NULL,
            consent_at datetime DEFAULT NULL,
            error_code varchar(64) NOT NULL DEFAULT '',
            attribution text NULL,
            draft_token char(32) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY status (status),
            KEY easybusy_id (easybusy_id),
            KEY draft_token (draft_token),
            KEY contact_hash (contact_hash)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option(self::VERSION_OPTION, self::DB_VERSION, false);
    }

    /** Runs on every load; a no-op once the stored version matches. */
    public static function maybeUpgrade(): void
    {
        if ((string) get_option(self::VERSION_OPTION, '') !== self::DB_VERSION) {
            self::install();
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return int Inserted id, 0 on failure.
     */
    public static function record(array $row): int
    {
        global $wpdb;

        $mode = Settings::storeMode();
        $full = $mode === 'full';
        $any = $mode !== 'none';

        $name = trim((string) ($row['patient_name'] ?? ''));
        $email = trim((string) ($row['patient_email'] ?? ''));
        $phone = trim((string) ($row['patient_phone'] ?? ''));
        $message = (string) ($row['message'] ?? '');

        $data = [
            'created_at'    => current_time('mysql'),
            'type'          => substr((string) ($row['type'] ?? 'booking'), 0, 16),
            'status'        => substr((string) ($row['status'] ?? 'new'), 0, 24),
            'dry_run'       => !empty($row['dry_run']) ? 1 : 0,
            'easybusy_id'   => isset($row['easybusy_id']) ? (int) $row['easybusy_id'] : null,
            'service_id'    => isset($row['service_id']) ? (int) $row['service_id'] : null,
            'service_name'  => substr((string) ($row['service_name'] ?? ''), 0, 191),
            'doctor_id'     => isset($row['doctor_id']) ? (int) $row['doctor_id'] : null,
            'doctor_name'   => substr((string) ($row['doctor_name'] ?? ''), 0, 191),
            'slot_id'       => isset($row['slot_id']) ? (int) $row['slot_id'] : null,
            'start_at'      => $row['start_at'] ?? null,
            'duration_min'  => isset($row['duration_min']) ? (int) $row['duration_min'] : null,
            'language'      => substr((string) ($row['language'] ?? ''), 0, 8),
            'store_mode'    => $mode,
            // Identifiable columns: written only in `full` mode. OIB never.
            'patient_name'  => $full ? substr($name, 0, 191) : '',
            'patient_email' => $full ? substr($email, 0, 191) : '',
            'patient_phone' => $full ? substr($phone, 0, 40) : '',
            // The message is what hurts to keep — it is the health complaint.
            'message'       => $full ? $message : null,
            // Minimal mode: reviewable, not identifiable, not contactable.
            'patient_initials' => $any ? self::initials($name) : '',
            'email_domain'     => $any ? self::emailDomain($email) : '',
            'phone_hint'       => $any ? self::phoneHint($phone) : '',
            'contact_hash'     => $any ? self::contactHash($email, $phone) : '',
            'message_len'      => $message === '' ? null : min(65535, mb_strlen($message)),
            'consent_at'    => $row['consent_at'] ?? null,
            'error_code'    => substr((string) ($row['error_code'] ?? ''), 0, 64),
            'attribution'   => isset($row['attribution']) ? (string) wp_json_encode($row['attribution']) : null,
            'draft_token'   => substr((string) ($row['draft_token'] ?? ''), 0, 32),
        ];

        $inserted = $wpdb->insert(self::table(), $data);

        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    /** "Ana Marija Horvat" → "A. M. H." — order of magnitude, not identity. */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $out = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $out .= mb_strtoupper(mb_substr($part, 0, 1)) . '. ';
            if (mb_strlen($out) >= 12) {
                break;
            }
        }

        return trim($out);
    }

    /** Keeps only the provider: "ana@gmail.com" → "@gmail.com". */
    public static function emailDomain(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : substr('@' . strtolower(substr($email, $at + 1)), 0, 64);
    }

    /**
     * Country prefix and the last two digits, so staff can tell a Croatian
     * mobile from a foreign landline: "+385 91 111 2233" → "+385 ••• 33".
     */
    public static function phoneHint(string $phone): string
    {
        $digits = self::phoneDigits($phone);
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) <= 5) {
            return str_repeat('•', strlen($digits));
        }

        // A leading zero means a local number; otherwise it is a country code.
        $prefix = str_starts_with($digits, '0') ? '' : '+';

        return $prefix . substr($digits, 0, 3) . ' ••• ' . substr($digits, -2);
    }

    /** "00385 91/111-2233" and "+385911112233" are the same number. */
    private static function phoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
    }

    /**
     * Salted one-way id for "have we seen this person before?". Not reversible
     * and site-specific, so it cannot be matched against another database.
     */
    public static function contactHash(string $email, string $phone): string
    {
        $email = strtolower(trim($email));
        $digits = self::phoneDigits($phone);
        $subject = $email !== '' ? 'e:' . $email : ($digits !== '' ? 'p:' . $digits : '');

        return $subject === '' ? '' : hash_hmac('sha256', $subject, wp_salt('ebc_contact'));
    }


    /**
     * @param array<string,mixed> $args
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public static function query(array $args = []): array
    {
        global $wpdb;

        $perPage = max(1, min(200, (int) ($args['per_page'] ?? 20)));
        $page = max(1, (int) ($args['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if (!empty($args['type'])) {
            $where[] = 'type = %s';
            $params[] = (string) $args['type'];
        }
        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = (string) $args['status'];
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            // Works in every mode: what is searchable depends on what was kept.
            $where[] = '(service_name LIKE %s OR doctor_name LIKE %s OR easybusy_id LIKE %s'
                . ' OR patient_name LIKE %s OR patient_email LIKE %s OR patient_phone LIKE %s'
                . ' OR patient_initials LIKE %s OR email_domain LIKE %s OR phone_hint LIKE %s)';
            $params = array_merge($params, array_fill(0, 9, $like));
        }
        if (!empty($args['since_days'])) {
            $where[] = 'created_at >= %s';
            $params[] = gmdate('Y-m-d H:i:s', time() - ((int) $args['since_days'] * DAY_IN_SECONDS));
        }

        $clause = implode(' AND ', $where);
        $table = self::table();

        $countSql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
        $total = (int) $wpdb->get_var($params === [] ? $countSql : $wpdb->prepare($countSql, $params));

        $rowsSql = "SELECT * FROM {$table} WHERE {$clause} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($rowsSql, array_merge($params, [$perPage, $offset])), ARRAY_A);

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /** @return array<string,int> */
    public static function counts(): array
    {
        global $wpdb;

        $table = self::table();
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A);
        $counts = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /** Deletes rows older than the retention window; 0 days disables purging. */
    public static function purge(?int $days = null): int
    {
        global $wpdb;

        $days = $days ?? (int) Settings::get('retention_days', 90);
        if ($days <= 0) {
            return 0;
        }

        $table = self::table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));
    }

    public static function drop(): void
    {
        global $wpdb;

        $table = self::table();
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        delete_option(self::VERSION_OPTION);
    }
    /**
     * One pass over the table for the headline numbers. Small table by design
     * (retention window), so this stays cheap.
     *
     * @return array{total:int,week:int,month:int,failed:int,dry_run:int,people:int,returning:int,last:?string}
     */
    public static function stats(): array
    {
        global $wpdb;

        $table = self::table();
        $week = gmdate('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS));
        $month = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(created_at >= %s) AS week,
                        SUM(created_at >= %s) AS month,
                        SUM(status = 'failed') AS failed,
                        SUM(dry_run = 1) AS dry_run,
                        COUNT(DISTINCT NULLIF(contact_hash, '')) AS people,
                        MAX(created_at) AS last
                   FROM {$table}",
                $week,
                $month
            ),
            ARRAY_A
        );

        $returning = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT contact_hash FROM {$table}
                 WHERE contact_hash <> '' GROUP BY contact_hash HAVING COUNT(*) > 1
             ) AS repeated"
        );

        return [
            'total'     => (int) ($row['total'] ?? 0),
            'week'      => (int) ($row['week'] ?? 0),
            'month'     => (int) ($row['month'] ?? 0),
            'failed'    => (int) ($row['failed'] ?? 0),
            'dry_run'   => (int) ($row['dry_run'] ?? 0),
            'people'    => (int) ($row['people'] ?? 0),
            'returning' => $returning,
            'last'      => $row['last'] ?? null,
        ];
    }

    /**
     * Contact hashes that appear more than once, so the list can flag a
     * returning visitor without knowing who they are.
     *
     * @return array<string,int>
     */
    public static function returningHashes(): array
    {
        global $wpdb;

        $table = self::table();
        $rows = $wpdb->get_results(
            "SELECT contact_hash, COUNT(*) AS total FROM {$table}
              WHERE contact_hash <> '' GROUP BY contact_hash HAVING COUNT(*) > 1",
            ARRAY_A
        );

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['contact_hash']] = (int) $row['total'];
        }

        return $out;
    }

    /**
     * Rows for the CSV export, oldest first, without paging.
     *
     * @param array<string,mixed> $args
     * @return array<int,array<string,mixed>>
     */
    public static function exportRows(array $args = []): array
    {
        $out = [];
        $page = 1;
        do {
            $result = self::query(array_merge($args, ['page' => $page, 'per_page' => 200]));
            $out = array_merge($out, $result['rows']);
            $page++;
        } while (count($out) < $result['total'] && $page < 100);

        return array_reverse($out);
    }

}
