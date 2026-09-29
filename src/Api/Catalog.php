<?php

declare(strict_types=1);

namespace EasyBusyConnect\Api;

use EasyBusyConnect\Settings;
use EasyBusyConnect\Support\Log;
use EasyBusyConnect\Support\Text;

/**
 * Bookable services and doctors. `bookable-services` already carries price and
 * currency, so step 1 shows prices without touching the price-list group, and
 * every label passes through Support\Text on the way out because the clinic
 * types them by hand (SHOUTED names, stray line breaks, trailing spaces).
 *
 * Every read goes to EasyBusy live. The clinic enables a service or assigns a
 * specialist in their system and expects the next visitor to see it, so a
 * read-through cache here serves a catalogue that no longer matches the clinic
 * — up to 0.10.0 it cached for an hour and hid both a newly enabled service and
 * a newly assigned specialist for that long, with no request reaching the
 * vendor. Within a single request the answer is memoised, and the last
 * successful answer is retained only as a fallback for a failed call, so an
 * outage degrades to the previous catalogue instead of an empty step 1.
 */
final class Catalog
{
    /**
     * How long the last successful answer stays usable. This is not a cache:
     * it is read only after the live call fails.
     */
    private const FALLBACK_TTL = DAY_IN_SECONDS;

    /** @var array<string,array<int,array<string,mixed>>> Per-request memo. */
    private array $memo = [];

    public function __construct(private Client $client, private Capabilities $capabilities)
    {
    }

    /**
     * The catalogue the website offers: every bookable service the key returns,
     * minus the ones the clinic hid in Settings → Booking form. The EasyBusy
     * account is shared with a second clinic, so its services arrive here too
     * and must not appear on this site.
     *
     * @return array<int,array<string,mixed>>|\WP_Error
     */
    public function services(string $language): array|\WP_Error
    {
        $services = $this->all($language);
        if (is_wp_error($services)) {
            return $services;
        }

        $hidden = Settings::hiddenServices();
        if ($hidden === []) {
            return $services;
        }

        return array_values(array_filter(
            $services,
            static fn (array $service): bool => !in_array($service['serviceId'], $hidden, true)
        ));
    }

    /**
     * Everything the key returns, hidden ones included — the admin screen needs
     * the full list to offer the checkboxes.
     *
     * @return array<int,array<string,mixed>>|\WP_Error
     */
    public function all(string $language): array|\WP_Error
    {
        $freeMax = Settings::freePriceMax();

        return $this->live('services', $language, '/simple-booking/bookable-services', static function (array $rows) use ($freeMax): array {
            $services = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['serviceId'])) {
                    continue;
                }
                $price = isset($row['price']) ? (float) $row['price'] : null;
                $services[] = [
                    'serviceId' => (int) $row['serviceId'],
                    'category'  => isset($row['category']) && $row['category'] !== null ? Text::label((string) $row['category']) : '',
                    'name'      => Text::label((string) ($row['name'] ?? '')),
                    'price'     => $price,
                    // EasyBusy cannot store 0, so a free consultation is entered
                    // as a token amount (0.01 €). Showing that number reads as a
                    // mistake; the form prints "free of charge" instead.
                    'free'      => $price !== null && $price <= $freeMax,
                    'currency'  => Text::clean((string) ($row['currency'] ?? '')),
                    'doctors'   => self::doctorList($row['doctors'] ?? []),
                ];
            }

            return $services;
        });
    }

    /**
     * Only meaningful when the clinic advertises SUPPORTS_BOOKING_BY_DOCTOR;
     * without it the endpoint returns nothing useful and doctorId is ignored.
     *
     * @return array<int,array<string,mixed>>|\WP_Error
     */
    public function doctors(string $language): array|\WP_Error
    {
        if (!$this->capabilities->supportsBookingByDoctor()) {
            return [];
        }

        return $this->live('doctors', $language, '/simple-booking/bookable-doctors', static function (array $rows): array {
            $doctors = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['doctorId'])) {
                    continue;
                }
                $doctor = self::doctor($row);
                $doctor['services'] = array_map(
                    static fn (array $service): int => (int) ($service['serviceId'] ?? 0),
                    array_filter((array) ($row['services'] ?? []), 'is_array')
                );
                $doctors[] = $doctor;
            }

            return $doctors;
        });
    }

    /** @return array<string,mixed>|null|\WP_Error */
    public function service(int $serviceId, string $language): array|null|\WP_Error
    {
        $services = $this->services($language);
        if (is_wp_error($services)) {
            return $services;
        }

        foreach ($services as $service) {
            if ($service['serviceId'] === $serviceId) {
                return $service;
            }
        }

        return null;
    }

    /** True when that doctor actually performs that service. */
    public function doctorPerformsService(int $doctorId, int $serviceId, string $language): bool
    {
        $service = $this->service($serviceId, $language);
        if (!is_array($service)) {
            return false;
        }

        foreach ($service['doctors'] as $doctor) {
            if ((int) $doctor['doctorId'] === $doctorId) {
                return true;
            }
        }

        return false;
    }

    /** Drops the fallback copies; a live read happens on the next request anyway. */
    public function flush(): void
    {
        $this->memo = [];

        foreach (['services', 'doctors'] as $kind) {
            foreach (array_keys($this->capabilities->languages()) as $language) {
                delete_transient(self::key($kind, (string) $language));
            }
            delete_transient(self::key($kind, ''));
        }
    }

    /**
     * One live call per request per kind/language, with the last good answer as
     * the only fallback.
     *
     * @param callable(array<int,mixed>):array<int,array<string,mixed>> $shape
     * @return array<int,array<string,mixed>>|\WP_Error
     */
    private function live(string $kind, string $language, string $path, callable $shape): array|\WP_Error
    {
        $memoKey = $kind . '|' . $language;
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $rows = $this->client->get($path, ['languageCode' => $language]);
        if (is_wp_error($rows)) {
            $fallback = get_transient(self::key($kind, $language));
            if (!is_array($fallback)) {
                return $rows;
            }

            Log::add('warning', 'catalog served from fallback', [
                'path'     => $path,
                'language' => $language,
                'cache'    => 'fallback',
                'code'     => $rows->get_error_code(),
            ]);

            return $this->memo[$memoKey] = $fallback;
        }

        $shaped = $shape(is_array($rows) ? $rows : []);
        set_transient(self::key($kind, $language), $shaped, self::FALLBACK_TTL);

        return $this->memo[$memoKey] = $shaped;
    }

    private static function key(string $kind, string $language): string
    {
        return 'ebc_' . $kind . '_' . ($language === '' ? 'default' : $language);
    }

    /** @param mixed $rows @return array<int,array<string,mixed>> */
    private static function doctorList(mixed $rows): array
    {
        $doctors = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) && isset($row['doctorId'])) {
                $doctors[] = self::doctor($row);
            }
        }

        return $doctors;
    }

    /**
     * Builds a display name from the parts the vendor sends, skipping the nulls
     * (doctorNumber and the postfixes are frequently null).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function doctor(array $row): array
    {
        $parts = array_filter([
            (string) ($row['prefix'] ?? ''),
            (string) ($row['firstName'] ?? ''),
            (string) ($row['lastName'] ?? ''),
        ], static fn (string $part): bool => trim($part) !== '');

        $titles = array_filter([
            (string) ($row['postfix'] ?? ''),
            (string) ($row['additionalPostfix'] ?? ''),
        ], static fn (string $part): bool => trim($part) !== '');

        return [
            'doctorId' => (int) $row['doctorId'],
            'name'     => Text::person(implode(' ', $parts)),
            'titles'   => Text::titles(implode(', ', $titles)),
        ];
    }
}
