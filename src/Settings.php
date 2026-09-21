<?php

declare(strict_types=1);

namespace EasyBusyConnect;

/**
 * Single source of truth for configuration. The API key is read from a
 * wp-config.php constant first: .wpress migrations carry the database but not
 * wp-config.php, and an option-stored secret leaks into exports and backups.
 */
final class Settings
{
    public const OPTION = 'ebc_settings';

    private const DEFAULTS = [
        'api_base'        => 'https://api-b2b.easybusy.software',
        'api_key'         => '',
        'mode'            => 'auto',   // auto | booking | lead
        'dry_run'         => true,     // no write ever leaves the site while true
        'slot_horizon'    => 60,       // days searched forward
        'language_map'    => [],       // wp locale => easybusy code
        'consent_text'    => '',
        'consent_url'     => '',
        'thank_you_page'  => 0,       // page holding [easybusy_thank_you]
        'require_oib'     => false,
        'collect_address' => false,
        // EasyBusy rejects a booking whose patientInfo.address.countryCode is
        // null, so the form always sends one; this is the preselected value.
        'default_country' => 'HR',
        // What an Entries row keeps of the person: minimal | full | none.
        'store_mode'      => 'minimal',
        'retention_days'  => 90,       // entries older than this are purged daily
        'notify_emails'   => '',       // falls back to admin_email
        'notify_patient'  => true,
        'email_from_name'    => '',
        'email_from_address' => '',
        'notify_admin_subject'   => '',
        'notify_admin_body'      => '',
        'notify_patient_subject' => '',
        'notify_patient_body'    => '',
        'attachments'     => true,     // X-ray upload (needs the Leads group)
        'attachment_max_files' => 3,
        'attachment_max_mb'    => 10,
        'ff_forms'        => '',       // Fluent Forms ids forwarded as leads
        'ui_locale'       => 'hr',     // plugin locale; site locale is en_US
        'delete_data_on_uninstall' => false,
        'ttl_company'     => 43200,
        'ttl_slots'       => 60,
    ];

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = get_option(self::OPTION, []);
            $stored = is_array($stored) ? $stored : [];

            // 0.9.4 and older stored a boolean. Honour what the admin chose
            // instead of silently changing how much is written.
            if (!isset($stored['store_mode']) && array_key_exists('store_contact', $stored)) {
                $stored['store_mode'] = empty($stored['store_contact']) ? 'none' : 'full';
            }
            unset($stored['store_contact']);

            self::$cache = array_merge(self::DEFAULTS, $stored);
        }

        return self::$cache;
    }

    /**
     * How much of the person an Entries row may keep.
     *
     * minimal — initials, e-mail domain, masked phone, message length only
     * full    — the clinic's working copy: name, e-mail, phone, message
     * none    — nothing about the person at all
     */
    public static function storeMode(): string
    {
        $mode = (string) self::get('store_mode', 'minimal');

        return in_array($mode, ['minimal', 'full', 'none'], true) ? $mode : 'minimal';
    }

    /**
     * Preselected country of the booking form, guaranteed to be a real ISO
     * 3166-1 alpha-2 code: it ends up in a payload EasyBusy refuses when null
     * and does not validate when wrong.
     */
    public static function defaultCountry(): string
    {
        $code = \EasyBusyConnect\Support\Countries::normalise((string) self::get('default_country', ''));

        return $code !== '' ? $code : \EasyBusyConnect\Support\Countries::FALLBACK;
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        return self::all()[$key] ?? $fallback;
    }

    /** @param array<string,mixed> $values */
    public static function save(array $values): void
    {
        $merged = array_merge(self::all(), $values);
        update_option(self::OPTION, $merged);
        self::$cache = $merged;
    }

    public static function apiBase(): string
    {
        $base = defined('EASYBUSY_API_BASE') ? (string) constant('EASYBUSY_API_BASE') : (string) self::get('api_base');

        return rtrim($base, '/');
    }

    public static function apiKey(): string
    {
        if (defined('EASYBUSY_API_KEY')) {
            return trim((string) constant('EASYBUSY_API_KEY'));
        }

        return trim((string) self::get('api_key'));
    }

    /** Where the key came from, for the admin screen. Never returns the value. */
    public static function apiKeySource(): string
    {
        if (defined('EASYBUSY_API_KEY') && trim((string) constant('EASYBUSY_API_KEY')) !== '') {
            return 'wp-config';
        }
        if (trim((string) self::get('api_key')) !== '') {
            return 'option';
        }

        return 'missing';
    }

    public static function isDryRun(): bool
    {
        if (defined('EASYBUSY_FORCE_DRY_RUN') && constant('EASYBUSY_FORCE_DRY_RUN')) {
            return true;
        }

        return (bool) self::get('dry_run');
    }
}
