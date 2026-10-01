<?php

declare(strict_types=1);

namespace EasyBusyConnect\Api;

use EasyBusyConnect\Settings;
use EasyBusyConnect\Support\Log;

/**
 * The API key's scope is discovered, never assumed: the current key is a test
 * key whose groups will be widened later. Every feature gates on this map, so a
 * widened scope lights up features without a code change, and a denied group
 * degrades instead of erroring.
 *
 * Probing must never create data. The Leads group is therefore probed with an
 * attachment upload against lead id 0: the access filter answers 403 for a key
 * without the group, while a key that holds it gets 400/404 because that lead
 * does not exist. GET /lead is useless as a probe — it answers 405 for a denied
 * key too — and POSTing an empty lead would create a junk record, since every
 * lead field is optional.
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

        // The only honest test of the Leads group is a write to it, so the
        // probe writes to a lead that cannot exist: id 0 never resolves, so a
        // granted key answers 200/400/404 without creating anything, while a
        // denied key is stopped by the access filter with 403. GET /lead answers
        // 405 for both and must not be used (it reported a denied key as granted
        // until 2026-09-28). The group has its own key — the booking key is
        // denied on /lead — so the probe is signed with that one; without it
        // there is no lead channel at all. A live 403 on a real call still
        // demotes the group through deny().
        $leadKey = Settings::leadApiKey();
        if ($leadKey === '') {
            $map['probes']['POST /lead/{id}/upload'] = 'no lead key';
            $map['groups']['leads'] = false;
        } else {
            $boundary = 'ebc' . bin2hex(random_bytes(12));
            $probeBody = "--{$boundary}\r\n"
                . 'Content-Disposition: form-data; name="file"; filename="probe.txt"' . "\r\n"
                . "Content-Type: text/plain\r\n\r\n"
                . "ebc-capability-probe\r\n"
                . "--{$boundary}--\r\n";
            $leadStatus = $this->client->withKey($leadKey)->probeRawStatus(
                'POST',
                '/lead/0/upload',
                $probeBody,
                'multipart/form-data; boundary=' . $boundary
            );
            $map['probes']['POST /lead/{id}/upload'] = is_wp_error($leadStatus) ? $leadStatus->get_error_code() : $leadStatus;
            $map['groups']['leads'] = is_int($leadStatus) && $leadStatus !== 403 && $leadStatus !== 401;
        }

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
     * Records a denial the site actually hit. The probe writes to lead id 0 and
     * believes the answer, but a group can still be revoked between two probes,
     * so a live 403 demotes it immediately — otherwise the form would keep
     * offering attachments and keep falling back to a lead that can never be
     * created.
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
     *
     * The catalogue must follow the language the **form** speaks, not the
     * WordPress admin locale. This site runs `en_US` with the form set to `hr`;
     * once the clinic added English to its EasyBusy account, `en` became a
     * valid code and step 1 went empty, because only the Croatian catalogue is
     * filled in. `ui_locale` is therefore asked before the site locale.
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

        $ui = (string) \EasyBusyConnect\Settings::get('ui_locale', '');
        if ($ui !== '') {
            $candidates[] = strtolower(substr($ui, 0, 2));
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
