<?php

declare(strict_types=1);

namespace EasyBusyConnect\Support;

/**
 * Transient-backed per-visitor counters. The public REST proxy is unauthenticated
 * by necessity, so reads and submits are both bounded.
 */
final class RateLimit
{
    public static function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        $key = 'ebc_rl_' . $bucket . '_' . self::fingerprint();
        $count = (int) get_transient($key);

        if ($count >= $limit) {
            return false;
        }

        set_transient($key, $count + 1, $windowSeconds);

        return true;
    }

    /** Hashed so no raw IP is stored anywhere. */
    private static function fingerprint(): string
    {
        return substr(hash('sha256', self::clientIp() . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
    }

    /** SiteGround fronts the site, so the first X-Forwarded-For hop is the visitor. */
    public static function clientIp(): string
    {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
    }
}
