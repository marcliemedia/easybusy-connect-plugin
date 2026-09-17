<?php

declare(strict_types=1);

namespace EasyBusyConnect\Api;

use EasyBusyConnect\Settings;

/**
 * Bookable services and doctors. `bookable-services` already carries price and
 * currency, so step 1 of the form shows prices even though the price-list group
 * is denied to the current key.
 */
final class Catalog
{
    public function __construct(private Client $client, private Capabilities $capabilities)
    {
    }

    /**
     * @return array<int,array<string,mixed>>|\WP_Error
     */
    public function services(string $language): array|\WP_Error
    {
        return $this->cached('services', $language, '/simple-booking/bookable-services', static function (array $rows): array {
            $services = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['serviceId'])) {
                    continue;
                }
                $services[] = [
                    'serviceId' => (int) $row['serviceId'],
                    'category'  => isset($row['category']) && $row['category'] !== null ? (string) $row['category'] : '',
                    'name'      => (string) ($row['name'] ?? ''),
                    'price'     => isset($row['price']) ? (float) $row['price'] : null,
                    'currency'  => (string) ($row['currency'] ?? ''),
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

        return $this->cached('doctors', $language, '/simple-booking/bookable-doctors', static function (array $rows): array {
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

    public function flush(): void
    {
        foreach (['services', 'doctors'] as $kind) {
            foreach (array_keys($this->capabilities->languages()) as $language) {
                delete_transient(self::key($kind, (string) $language));
            }
            delete_transient(self::key($kind, ''));
        }
    }

    /**
     * @param callable(array<int,mixed>):array<int,array<string,mixed>> $shape
     * @return array<int,array<string,mixed>>|\WP_Error
     */
    private function cached(string $kind, string $language, string $path, callable $shape): array|\WP_Error
    {
        $key = self::key($kind, $language);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->client->get($path, ['languageCode' => $language]);
        if (is_wp_error($rows)) {
            return $rows;
        }

        $shaped = $shape(is_array($rows) ? $rows : []);
        set_transient($key, $shaped, (int) Settings::get('ttl_catalog', 3600));

        return $shaped;
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
            'name'     => implode(' ', $parts),
            'titles'   => implode(', ', $titles),
        ];
    }
}
