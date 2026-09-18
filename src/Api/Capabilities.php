<?php

declare(strict_types=1);

namespace EasyBusyConnect\Api;

use EasyBusyConnect\Support\Log;

/**
 * The API key's scope is discovered, never assumed: the current key is a test
 * key whose groups will be widened later. Every feature gates on this map, so a
 * widened scope lights up features without a code change, and a denied group
 * degrades instead of erroring.
 *
 * Probing must never create data. The Leads group is therefore probed with
 * GET /lead: the access filter runs before method matching, so 403 means the
 * group is denied while 405 means it is granted. POSTing an empty lead would
 * create a junk record, since every lead field is optional.
 */
final class Capabilities
{
    public const OPTION = 'ebc_capabilities';

    public function __construct(private Client $client)
    {
    }

    /** @return array<string,mixed> */
    public function get(bool $refresh = false): array
    {
        if (!$refresh) {
            $stored = get_option(self::OPTION, []);
            if (is_array($stored) && $stored !== []) {
                return $stored;
            }
        }

        return $this->probe();
    }

    /** @return array<string,mixed> */
    public function probe(): array
    {
        $map = [
            'probed_at' => gmdate('c'),
            'vendor_up' => false,
            'key_valid' => false,
            'groups'    => [
                'company'        => false,
                'simple_booking' => false,
                'leads'          => false,
                'price_list'     => false,
                'patient_app'    => false,
            ],
            'features'  => [],
            'config'    => [],
            'languages' => [],
            'probes'    => [],
            // Groups a live call proved denied after the probe said otherwise;
            // a manual re-probe deliberately clears them and starts over.
            'denied'    => [],
        ];

        $ping = $this->client->probeStatus('GET', '/ping', false);
        $map['vendor_up'] = $ping === 200;
        $map['probes']['GET /ping'] = is_wp_error($ping) ? $ping->get_error_code() : $ping;

        $features = $this->client->get('/company/features');
        $map['probes']['GET /company/features'] = $this->describe($features);
        if (!is_wp_error($features)) {
            $map['key_valid'] = true;
            $map['groups']['company'] = true;
            $map['features'] = array_values(array_filter((array) $features, 'is_string'));
        } elseif ($features->get_error_code() === 'ebc_forbidden') {
            $map['key_valid'] = true; // the key authenticated, the group is denied
        }

        $config = $this->client->get('/company/config');
        $map['probes']['GET /company/config'] = $this->describe($config);
        if (!is_wp_error($config)) {
            $map['key_valid'] = true;
            $map['groups']['company'] = true;
            foreach ((array) $config as $row) {
                if (is_array($row)) {
                    $map['config'] = array_merge($map['config'], $row);
                }
            }
        }

        $languages = $this->client->get('/company/languages');
        $map['probes']['GET /company/languages'] = $this->describe($languages);
        if (!is_wp_error($languages)) {
            foreach ((array) $languages as $row) {
                if (!is_array($row) || !isset($row['code'])) {
                    continue;
                }
                $map['languages'][(string) $row['code']] = [
                    'name'    => (string) ($row['name'] ?? $row['code']),
                    'default' => (bool) ($row['isDefault'] ?? false),
                ];
            }
        }

        $services = $this->client->get('/simple-booking/bookable-services');
        $map['probes']['GET /simple-booking/bookable-services'] = $this->describe($services);
        $map['groups']['simple_booking'] = !is_wp_error($services);

        // Optimistic by necessity: the only honest test of POST /lead is a POST
        // /lead, and every lead field is optional, so a probe would create a
        // junk record in the clinic's CRM. GET /lead answering 405 instead of
        // 403 means the *path* exists; a real POST that comes back 403 demotes
        // the group through deny() (verified live 2026-09-18 — this key is
        // denied on POST while GET still answers 405).
        $leadStatus = $this->client->probeStatus('GET', '/lead');
        $map['probes']['GET /lead'] = is_wp_error($leadStatus) ? $leadStatus->get_error_code() : $leadStatus;
        $map['groups']['leads'] = $leadStatus === 405;

        $priceList = $this->client->get('/price-list');
        $map['probes']['GET /price-list'] = $this->describe($priceList);
        $map['groups']['price_list'] = !is_wp_error($priceList);

        $patient = $this->client->probeStatus('GET', '/patient/ebc-capability-probe');
        $map['probes']['GET /patient/{id}'] = is_wp_error($patient) ? $patient->get_error_code() : $patient;
        $map['groups']['patient_app'] = is_int($patient) && $patient !== 403 && $patient !== 401;

        update_option(self::OPTION, $map, false);
        Log::add('info', 'capability probe', [
            'count' => count(array_filter($map['groups'])),
            'mode'  => $map['groups']['simple_booking'] ? 'booking' : 'lead',
        ]);

        return $map;
    }

    public function supportsBookingByDoctor(): bool
    {
        $map = $this->get();

        return in_array('SUPPORTS_BOOKING_BY_DOCTOR', (array) ($map['features'] ?? []), true);
    }

    public function grants(string $group): bool
    {
        $map = $this->get();

        return (bool) ($map['groups'][$group] ?? false);
    }

    /**
     * Records a denial the site actually hit, because probing cannot always see
     * one: GET /lead answers 405 (the vendor authorises after binding the
     * request body), so the Leads group looks granted until a real POST /lead
     * comes back 403 — which is exactly what happens on this key. Without this,
     * the form would keep offering attachments and keep falling back to a lead
     * that can never be created.
     */
    public function deny(string $group, string $reason): void
    {
        $map = $this->get();
        if (($map['groups'][$group] ?? false) === false) {
            return;
        }

        $map['groups'][$group] = false;
        $map['denied'][$group] = ['at' => gmdate('c'), 'reason' => $reason];
        update_option(self::OPTION, $map, false);

        Log::add('warning', 'capability denied in use', ['group' => $group, 'reason' => $reason]);
    }

    public function gridMinutes(): int
    {
        $map = $this->get();
        $minutes = (int) ($map['config']['SCHEDULE_SMALLEST_INTERVAL_IN_MINUTES'] ?? 15);

        return $minutes > 0 ? $minutes : 15;
    }

    /** @return array<string,array{name:string,default:bool}> */
    public function languages(): array
    {
        $map = $this->get();
        $languages = $map['languages'] ?? [];

        return is_array($languages) ? $languages : [];
    }

    public function defaultLanguage(): string
    {
        foreach ($this->languages() as $code => $meta) {
            if (!empty($meta['default'])) {
                return (string) $code;
            }
        }

        $codes = array_keys($this->languages());

        return $codes === [] ? '' : (string) $codes[0];
    }

    /**
     * An unsupported languageCode does NOT error: the vendor answers 200 with an
     * empty data array (verified with languageCode=de), which is indistinguishable
     * from "the clinic has no services". So the code is whitelisted here first.
     */
    public function resolveLanguage(?string $requested = null): string
    {
        $available = $this->languages();
        if ($available === []) {
            return '';
        }

        $candidates = [];
        if ($requested !== null && $requested !== '') {
            $candidates[] = strtolower(substr($requested, 0, 2));
        }

        $map = (array) \EasyBusyConnect\Settings::get('language_map', []);
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        if (isset($map[$locale])) {
            $candidates[] = strtolower((string) $map[$locale]);
        }
        $candidates[] = strtolower(substr((string) $locale, 0, 2));

        foreach ($candidates as $candidate) {
            if (isset($available[$candidate])) {
                return $candidate;
            }
        }

        return $this->defaultLanguage();
    }

    private function describe(mixed $result): string|int
    {
        if (!is_wp_error($result)) {
            return 200;
        }
        $status = $result->get_error_data();

        return is_array($status) && isset($status['status']) ? (int) $status['status'] : $result->get_error_code();
    }
}
