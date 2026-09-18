<?php

declare(strict_types=1);

namespace EasyBusyConnect\Rest;

use EasyBusyConnect\Api\Capabilities;
use EasyBusyConnect\Api\Catalog;
use EasyBusyConnect\Api\Slots;
use EasyBusyConnect\Form\Definition;
use EasyBusyConnect\Form\Draft;
use EasyBusyConnect\Form\Submit;
use EasyBusyConnect\Settings;
use EasyBusyConnect\Support\Countries;
use EasyBusyConnect\Support\RateLimit;
use EasyBusyConnect\Support\Tz;
use EasyBusyConnect\Support\Uploads;

/**
 * Public proxy between the form and EasyBusy. The API key never reaches the
 * browser, and responses are whitelisted (the vendor sends doctor e-mail
 * addresses, which are not forwarded).
 *
 * Endpoints are unauthenticated by necessity — visitors are anonymous — so every
 * route is rate limited and every write is bound to a server-side draft token.
 * Responses are marked no-store because SiteGround caching would otherwise serve
 * dead slot ids.
 */
final class Controller
{
    public const NS = 'easybusy/v1';

    public function __construct(
        private Capabilities $capabilities,
        private Definition $definition,
        private Catalog $catalog,
        private Slots $slots,
        private Submit $submit
    ) {
    }

    public function register(): void
    {
        $open = static fn (): bool => true;

        register_rest_route(self::NS, '/session', [
            'methods'             => 'POST',
            'permission_callback' => $open,
            'callback'            => [$this, 'session'],
        ]);

        register_rest_route(self::NS, '/services', [
            'methods'             => 'GET',
            'permission_callback' => $open,
            'callback'            => [$this, 'services'],
        ]);

        register_rest_route(self::NS, '/doctors', [
            'methods'             => 'GET',
            'permission_callback' => $open,
            'callback'            => [$this, 'doctors'],
        ]);

        register_rest_route(self::NS, '/slots', [
            'methods'             => 'GET',
            'permission_callback' => $open,
            'callback'            => [$this, 'slotList'],
        ]);

        register_rest_route(self::NS, '/draft', [
            'methods'             => 'POST',
            'permission_callback' => $open,
            'callback'            => [$this, 'draft'],
        ]);

        register_rest_route(self::NS, '/submit', [
            'methods'             => 'POST',
            'permission_callback' => $open,
            'callback'            => [$this, 'submitForm'],
        ]);

        register_rest_route(self::NS, '/upload', [
            'methods'             => 'POST',
            'permission_callback' => $open,
            'callback'            => [$this, 'upload'],
        ]);
    }

    public function session(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!RateLimit::hit('session', 20, 600)) {
            return $this->tooMany();
        }

        $language = $this->capabilities->resolveLanguage((string) $request->get_param('lang'));
        $draft = Draft::create($language);

        $attribution = (array) ($request->get_param('attribution') ?? []);
        $draft->merge(['attribution' => [
            'contact_page' => esc_url_raw((string) ($attribution['contact_page'] ?? '')),
            'referer'      => esc_url_raw((string) ($attribution['referer'] ?? '')),
            'landing'      => esc_url_raw((string) ($attribution['landing'] ?? '')),
        ]]);

        return $this->respond([
            'token'  => $draft->token,
            'steps'  => $this->definition->steps(),
            'intro'  => $this->definition->intro(),
            'config' => [
                'language'        => $language,
                'languages'       => $this->capabilities->languages(),
                'gridMinutes'     => $this->capabilities->gridMinutes(),
                'bookingEnabled'  => $this->definition->bookingEnabled(),
                'doctorStep'      => $this->capabilities->supportsBookingByDoctor() && $this->definition->bookingEnabled(),
                'dryRun'          => Settings::isDryRun(),
                'requireOib'      => (bool) Settings::get('require_oib', false),
                'collectAddress'  => (bool) Settings::get('collect_address', false),
                // EasyBusy refuses a booking without a country, so the picker is
                // never optional and always opens on a valid code.
                'defaultCountry'  => Settings::defaultCountry(),
                'countries'       => Countries::options($language),
                'attachments'     => (bool) Settings::get('attachments', true) && $this->capabilities->grants('leads'),
                'maxFiles'        => Uploads::maxFiles(),
                'thankYouUrl'     => \EasyBusyConnect\Frontend\ThankYou::url(),
                'maxFileMb'       => (int) Settings::get('attachment_max_mb', 10),
                'consent'         => [
                    'text' => (string) Settings::get('consent_text', ''),
                    'url'  => (string) Settings::get('consent_url', ''),
                ],
            ],
        ]);
    }

    public function services(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!RateLimit::hit('read', 60, 60)) {
            return $this->tooMany();
        }

        $language = $this->language($request);
        $services = $this->catalog->services($language);
        if (is_wp_error($services)) {
            return $services;
        }

        $groups = [];
        foreach ($services as $service) {
            $category = $service['category'] !== '' ? $service['category'] : __('Other services', 'easybusy-connect');
            $groups[$category][] = [
                'serviceId' => $service['serviceId'],
                'name'      => $service['name'],
                'price'     => $service['price'],
                'currency'  => $service['currency'],
                'doctors'   => $service['doctors'],
            ];
        }

        $shaped = [];
        foreach ($groups as $category => $items) {
            $shaped[] = ['category' => (string) $category, 'services' => $items];
        }

        return $this->respond(['language' => $language, 'groups' => $shaped]);
    }

    public function doctors(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!RateLimit::hit('read', 60, 60)) {
            return $this->tooMany();
        }

        $language = $this->language($request);
        $serviceId = (int) $request->get_param('service');
        $doctors = $this->catalog->doctors($language);
        if (is_wp_error($doctors)) {
            return $doctors;
        }

        if ($serviceId > 0) {
            $doctors = array_values(array_filter(
                $doctors,
                static fn (array $doctor): bool => in_array($serviceId, $doctor['services'], true)
            ));
        }

        return $this->respond(['language' => $language, 'doctors' => $doctors]);
    }

    public function slotList(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!RateLimit::hit('read', 60, 60)) {
            return $this->tooMany();
        }

        $draft = $this->draftFrom($request);
        if (is_wp_error($draft)) {
            return $draft;
        }

        $serviceId = $draft->serviceId();
        if ($serviceId === null) {
            return new \WP_Error('ebc_no_service', __('Please choose a service first.', 'easybusy-connect'), ['status' => 400]);
        }

        $grouped = $this->slots->grouped($serviceId, $draft->doctorId(), null, $draft->language());
        if (is_wp_error($grouped)) {
            return $grouped;
        }

        return $this->respond($grouped + ['horizonDays' => (int) Settings::get('slot_horizon', 60)]);
    }

    public function draft(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!RateLimit::hit('draft', 120, 600)) {
            return $this->tooMany();
        }

        $draft = $this->draftFrom($request);
        if (is_wp_error($draft)) {
            return $draft;
        }

        $step = (string) $request->get_param('step');
        $language = $draft->language();

        switch ($step) {
            case 'service':
                $serviceId = (int) $request->get_param('serviceId');
                $service = $this->catalog->service($serviceId, $language);
                if (is_wp_error($service)) {
                    return $service;
                }
                if ($service === null) {
                    return new \WP_Error('ebc_unknown_service', __('That service is not bookable.', 'easybusy-connect'), ['status' => 400]);
                }
                // Changing the service invalidates the doctor and slot chosen for the old one.
                $draft->merge(['service' => $service, 'doctor' => null, 'slot' => null, 'start' => null]);
                break;

            case 'doctor':
                $doctorId = (int) $request->get_param('doctorId');
                $serviceId = $draft->serviceId();
                if ($serviceId === null) {
                    return new \WP_Error('ebc_no_service', __('Please choose a service first.', 'easybusy-connect'), ['status' => 400]);
                }
                if ($doctorId === 0) {
                    $draft->merge(['doctor' => null, 'slot' => null, 'start' => null]);
                    break;
                }
                if (!$this->catalog->doctorPerformsService($doctorId, $serviceId, $language)) {
                    return new \WP_Error('ebc_doctor_mismatch', __('That specialist does not perform the selected service.', 'easybusy-connect'), ['status' => 400]);
                }
                $draft->merge(['doctor' => ['doctorId' => $doctorId], 'slot' => null, 'start' => null]);
                break;

            case 'message':
                $draft->merge(['message' => $this->definition->sanitiseMessage((string) $request->get_param('message'))]);
                break;

            case 'schedule':
                $serviceId = $draft->serviceId();
                if ($serviceId === null) {
                    return new \WP_Error('ebc_no_service', __('Please choose a service first.', 'easybusy-connect'), ['status' => 400]);
                }
                $slotId = (int) $request->get_param('slotId');
                $slot = $this->slots->verify($slotId, $serviceId, $draft->doctorId(), $language);
                if (is_wp_error($slot)) {
                    return $slot;
                }
                $start = $this->validateStart($slot, (string) $request->get_param('start'));
                if (is_wp_error($start)) {
                    return $start;
                }
                $draft->merge(['slot' => $slotId, 'start' => $start, 'slot_snapshot' => $slot]);
                break;

            case 'preferred_time':
                $draft->merge(['preferred_time' => sanitize_text_field((string) $request->get_param('preferred_time'))]);
                break;

            default:
                return new \WP_Error('ebc_unknown_step', __('Unknown form step.', 'easybusy-connect'), ['status' => 400]);
        }

        return $this->respond(['summary' => $this->summary($draft)]);
    }

    public function submitForm(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!RateLimit::hit('submit', 3, 600)) {
            return $this->tooMany();
        }

        $draft = $this->draftFrom($request);
        if (is_wp_error($draft)) {
            return $draft;
        }

        // Bots fill hidden fields and submit instantly; humans do neither.
        if (trim((string) $request->get_param('website')) !== '') {
            return new \WP_Error('ebc_spam', __('Submission rejected.', 'easybusy-connect'), ['status' => 400]);
        }
        $created = (int) $draft->get('created', 0);
        if ($created > 0 && (time() - $created) < 3) {
            return new \WP_Error('ebc_too_fast', __('Please take a moment to review your details.', 'easybusy-connect'), ['status' => 400]);
        }

        $result = $this->submit->run($draft, (array) ($request->get_param('contact') ?? []));
        if (is_wp_error($result)) {
            return $result;
        }

        return $this->respond(['result' => $result, 'summary' => $this->summary($draft)]);
    }

    /**
     * Stages one attachment against the draft. Nothing is sent to EasyBusy here
     * — files travel with the lead created at submit time, because the booking
     * API has no upload endpoint.
     */
    public function upload(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!RateLimit::hit('upload', 10, 600)) {
            return $this->tooMany();
        }

        if (!(bool) Settings::get('attachments', true) || !$this->capabilities->grants('leads')) {
            return new \WP_Error('ebc_uploads_disabled', __('Attachments are not accepted.', 'easybusy-connect'), ['status' => 403]);
        }

        $draft = $this->draftFrom($request);
        if (is_wp_error($draft)) {
            return $draft;
        }

        $files = is_array($draft->get('files')) ? $draft->get('files') : [];
        if (count($files) >= Uploads::maxFiles()) {
            return new \WP_Error('ebc_too_many_files', sprintf(
                /* translators: %d: maximum number of files */
                __('You can attach at most %d files.', 'easybusy-connect'),
                Uploads::maxFiles()
            ), ['status' => 400]);
        }

        $params = $request->get_file_params();
        $file = $params['file'] ?? null;
        if (!is_array($file)) {
            return new \WP_Error('ebc_no_file', __('No file was received.', 'easybusy-connect'), ['status' => 400]);
        }

        $stored = Uploads::store($file);
        if (is_wp_error($stored)) {
            return $stored;
        }

        $files[] = $stored;
        $draft->merge(['files' => $files]);

        return $this->respond([
            'files' => array_map(
                static fn (array $entry): array => ['name' => $entry['name'], 'size' => $entry['size']],
                $files
            ),
            'remaining' => Uploads::maxFiles() - count($files),
        ]);
    }

    /**
     * Language for a read request: the draft's language when a token is
     * supplied (so the wording cannot switch mid-flow), otherwise whatever the
     * page asked for, whitelisted against the clinic's languages.
     */
    private function language(\WP_REST_Request $request): string
    {
        $token = (string) $request->get_param('token');
        if ($token !== '') {
            $draft = Draft::load($token);
            if ($draft !== null && $draft->language() !== '') {
                return $draft->language();
            }
        }

        return $this->capabilities->resolveLanguage((string) $request->get_param('lang'));
    }

    /** @return Draft|\WP_Error */
    private function draftFrom(\WP_REST_Request $request): Draft|\WP_Error
    {
        $draft = Draft::load((string) $request->get_param('token'));

        return $draft ?? new \WP_Error('ebc_draft_expired', __('Your session expired. Please start again.', 'easybusy-connect'), ['status' => 409]);
    }

    /**
     * A start time must sit on the clinic's grid inside the chosen slot, with
     * room for the full appointment; anything else is a tampered request.
     *
     * @param array<string,mixed> $slot
     * @return string|\WP_Error
     */
    private function validateStart(array $slot, string $requested): string|\WP_Error
    {
        if ($requested === '') {
            return (string) $slot['start'];
        }

        $start = Tz::parse((string) $slot['start']);
        $end = Tz::parse((string) $slot['end']);
        if ($start === null || $end === null) {
            return (string) $slot['start'];
        }

        $required = (int) ($slot['requiredSlotSize'] ?: $slot['slotSize']);
        $allowed = array_column(Tz::startOptions($start, $end, $required, $this->capabilities->gridMinutes()), 'value');
        $normalised = Tz::parse($requested);
        $candidate = $normalised ? Tz::api($normalised) : '';

        if ($candidate === '' || !in_array($candidate, $allowed, true)) {
            return new \WP_Error('ebc_bad_start', __('That start time is not available.', 'easybusy-connect'), ['status' => 400]);
        }

        return $candidate;
    }

    /** @return array<string,mixed> */
    private function summary(Draft $draft): array
    {
        $service = $draft->get('service');
        $snapshot = $draft->get('slot_snapshot');

        return [
            'service'  => is_array($service) ? [
                'serviceId' => $service['serviceId'],
                'name'      => $service['name'],
                'price'     => $service['price'],
                'currency'  => $service['currency'],
            ] : null,
            'doctorId' => $draft->doctorId(),
            'message'  => (string) $draft->get('message', ''),
            // The chosen start can sit later than the slot's own start when a
            // long slot was split on the scheduling grid; show what was picked.
            'slot'     => is_array($snapshot) ? [
                'slotId'      => $snapshot['slotId'],
                'date'        => $snapshot['date'],
                'time'        => $this->chosenTime($draft, $snapshot),
                'durationMin' => $snapshot['requiredSlotSize'],
                'doctorName'  => $snapshot['doctorName'],
            ] : null,
            'start'    => $draft->get('start'),
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function chosenTime(Draft $draft, array $snapshot): string
    {
        $start = Tz::parse((string) $draft->get('start', ''));

        return $start !== null ? $start->format('H:i') : (string) $snapshot['time'];
    }

    /** @param array<string,mixed> $payload */
    private function respond(array $payload): \WP_REST_Response
    {
        $response = new \WP_REST_Response($payload, 200);
        $response->header('Cache-Control', 'no-store, max-age=0');

        return $response;
    }

    private function tooMany(): \WP_Error
    {
        return new \WP_Error('ebc_rate_limited', __('Too many requests. Please try again in a few minutes.', 'easybusy-connect'), ['status' => 429]);
    }
}
