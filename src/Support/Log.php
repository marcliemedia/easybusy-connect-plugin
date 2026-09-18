<?php

declare(strict_types=1);

namespace EasyBusyConnect\Support;

/**
 * Ring buffer of the last N API interactions. Deliberately stores no patient
 * data: a dental clinic's submissions are health-adjacent, so only endpoint,
 * status and error taxonomy are kept.
 */
final class Log
{
    public const OPTION = 'ebc_log';
    /** How many entries are retained; the admin table pages through them. */
    public const LIMIT = 200;

    private const SAFE_KEYS = [
        'method', 'path', 'status', 'code', 'error_type', 'duration_ms', 'attempt',
        'service_id', 'doctor_id', 'slot_id', 'appointment_id', 'lead_id',
        'language', 'count', 'mode', 'dry_run', 'step', 'cache', 'group',
        // wp_mail()'s own failure text — no patient data, and the one thing you
        // need when notifications stop arriving.
        'reason',
    ];

    /** @param array<string,mixed> $context */
    public static function add(string $level, string $message, array $context = []): void
    {
        $entries = get_option(self::OPTION, []);
        if (!is_array($entries)) {
            $entries = [];
        }

        $entries[] = [
            'at'      => gmdate('c'),
            'level'   => $level,
            'message' => $message,
            'context' => self::scrub($context),
        ];

        if (count($entries) > self::LIMIT) {
            $entries = array_slice($entries, -self::LIMIT);
        }

        update_option(self::OPTION, $entries, false);
    }

    /** @return array<int,array<string,mixed>> */
    public static function tail(int $lines = 50): array
    {
        $entries = get_option(self::OPTION, []);
        if (!is_array($entries)) {
            return [];
        }

        return array_slice($entries, -max(1, $lines));
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,scalar>
     */
    private static function scrub(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (!in_array($key, self::SAFE_KEYS, true)) {
                continue;
            }
            if (is_bool($value)) {
                $safe[$key] = $value ? 'yes' : 'no';
                continue;
            }
            if (is_scalar($value)) {
                $safe[$key] = is_string($value) ? substr($value, 0, 200) : $value;
            }
        }

        return $safe;
    }
}
