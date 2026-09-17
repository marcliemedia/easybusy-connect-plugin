<?php

declare(strict_types=1);

namespace EasyBusyConnect\Frontend;

use EasyBusyConnect\Form\Draft;
use EasyBusyConnect\Settings;
use EasyBusyConnect\Support\Log;

/**
 * `[easybusy_thank_you]` — the confirmation page the form redirects to.
 *
 * A separate URL exists so Google Ads / GA4 conversions can be measured on a
 * page view instead of a JavaScript event, which is what agencies and tag
 * managers expect. The details are read from the draft transient using the token
 * in the URL; nothing sensitive is put in the query string itself.
 */
final class ThankYou
{
    public const QUERY_VAR = 'ebc';

    public function __construct(private Assets $assets)
    {
    }

    /** Permalink of the configured page, empty when redirecting is disabled. */
    public static function url(): string
    {
        $pageId = (int) Settings::get('thank_you_page', 0);
        if ($pageId <= 0) {
            return '';
        }

        $permalink = get_permalink($pageId);

        return is_string($permalink) ? $permalink : '';
    }

    /** @param array<string,mixed>|string $atts */
    public function render($atts = []): string
    {
        $this->assets->enqueue();

        // Personal data on screen: never let a page cache keep it.
        nocache_headers();
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        $token = isset($_GET[self::QUERY_VAR]) ? sanitize_text_field((string) $_GET[self::QUERY_VAR]) : '';
        $draft = $token !== '' ? Draft::load($token) : null;
        $result = $draft instanceof Draft && is_array($draft->get('result')) ? $draft->get('result') : null;

        if ($result === null) {
            return $this->renderFallback();
        }

        $summary = $this->summarise($draft, $result);

        $rows = '';
        foreach ($summary['rows'] as $label => $value) {
            if ($value === '') {
                continue;
            }
            $rows .= sprintf(
                '<dt>%s</dt><dd>%s</dd>',
                esc_html((string) $label),
                esc_html((string) $value)
            );
        }

        $dry = !empty($result['dry_run'])
            ? sprintf(
                '<p class="ebc-notice ebc-notice--dry">%s</p>',
                esc_html__('Test mode: the request was assembled and validated, but nothing was sent to EasyBusy.', 'easybusy-connect')
            )
            : '';

        Log::add('info', 'thank-you page viewed', [
            'mode'  => (string) ($result['mode'] ?? ''),
            'dry_run' => !empty($result['dry_run']),
        ]);

        return sprintf(
            '<div class="ebc-form ebc-thanks">%1$s<h2 class="ebc-thanks__title">%2$s</h2><p class="ebc-notice">%3$s</p>%4$s<dl class="ebc-result">%5$s</dl><p class="ebc-meta">%6$s</p>%7$s</div>',
            $this->trackingScript($summary),
            esc_html__('Thank you — your request has been received', 'easybusy-connect'),
            esc_html__('This is a request, not a confirmed appointment. The clinic will contact you to confirm.', 'easybusy-connect'),
            $dry,
            $rows,
            esc_html__('Please keep the reference number for any questions.', 'easybusy-connect'),
            $this->backLink()
        );
    }

    private function renderFallback(): string
    {
        return sprintf(
            '<div class="ebc-form ebc-thanks"><h2 class="ebc-thanks__title">%1$s</h2><p class="ebc-notice">%2$s</p><p class="ebc-meta">%3$s</p>%4$s</div>',
            esc_html__('Thank you — your request has been received', 'easybusy-connect'),
            esc_html__('This is a request, not a confirmed appointment. The clinic will contact you to confirm.', 'easybusy-connect'),
            esc_html__('The details of this request are no longer available in your browser session. The clinic has them and will be in touch.', 'easybusy-connect'),
            $this->backLink()
        );
    }

    private function backLink(): string
    {
        return sprintf(
            '<p class="ebc-thanks__back"><a class="ebc-button ebc-button--ghost" href="%s">%s</a></p>',
            esc_url(home_url('/')),
            esc_html__('Back to the homepage', 'easybusy-connect')
        );
    }

    /**
     * @param array<string,mixed> $result
     * @return array{rows:array<string,string>,data:array<string,mixed>}
     */
    private function summarise(Draft $draft, array $result): array
    {
        $service = is_array($draft->get('service')) ? $draft->get('service') : [];
        $snapshot = is_array($draft->get('slot_snapshot')) ? $draft->get('slot_snapshot') : [];
        $appointment = is_array($result['appointment'] ?? null) ? $result['appointment'] : [];
        $lead = is_array($result['lead'] ?? null) ? $result['lead'] : [];
        $start = \EasyBusyConnect\Support\Tz::parse((string) $draft->get('start', ''));
        $reference = $appointment['appointmentId'] ?? $lead['leadId'] ?? null;

        $rows = [
            __('Reference number', 'easybusy-connect') => $reference !== null ? (string) $reference : __('will be assigned by the clinic', 'easybusy-connect'),
            __('Service', 'easybusy-connect')          => (string) ($service['name'] ?? ''),
            __('Specialist', 'easybusy-connect')       => (string) ($snapshot['doctorName'] ?? ''),
            __('Appointment', 'easybusy-connect')      => $start !== null
                ? $start->format('d.m.Y. H:i') . (isset($snapshot['requiredSlotSize']) ? ' · ' . sprintf(__('%d min', 'easybusy-connect'), (int) $snapshot['requiredSlotSize']) : '')
                : (string) ($draft->get('preferred_time', '')),
            __('Your message', 'easybusy-connect')     => (string) $draft->get('message', ''),
        ];

        return [
            'rows' => $rows,
            'data' => [
                'event'        => 'easybusy_booking_submitted',
                'ebc_type'     => (string) ($result['mode'] ?? 'booking'),
                'ebc_dry_run'  => !empty($result['dry_run']),
                'ebc_reference' => $reference,
                'ebc_service_id' => isset($service['serviceId']) ? (int) $service['serviceId'] : null,
                'ebc_service'  => (string) ($service['name'] ?? ''),
                'value'        => isset($service['price']) ? (float) $service['price'] : null,
                'currency'     => (string) ($service['currency'] ?? ''),
            ],
        ];
    }

    /**
     * Pushes the conversion into `dataLayer` before GTM loads, so a Google Ads /
     * GA4 tag can fire on this page view. No patient data is exposed — only the
     * reference, the service and its price.
     *
     * @param array{data:array<string,mixed>} $summary
     */
    private function trackingScript(array $summary): string
    {
        $payload = wp_json_encode(array_filter(
            $summary['data'],
            static fn ($value): bool => $value !== null && $value !== ''
        ));

        if (!is_string($payload)) {
            return '';
        }

        return sprintf(
            '<script>window.dataLayer=window.dataLayer||[];window.dataLayer.push(%s);</script>',
            $payload
        );
    }
}
