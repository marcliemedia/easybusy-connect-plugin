<?php

declare(strict_types=1);

namespace EasyBusyConnect\Frontend;

use EasyBusyConnect\Api\Capabilities;
use EasyBusyConnect\Form\Definition;

/**
 * [easybusy_booking] — the only markup the server renders is the mount point and
 * a no-JavaScript fallback; the steps themselves are built client-side from the
 * REST proxy so nothing bookable is ever baked into cached HTML.
 */
final class Shortcode
{
    public function __construct(
        private Assets $assets,
        private Definition $definition,
        private Capabilities $capabilities
    ) {
    }

    /** @param array<string,mixed>|string $atts */
    public function render($atts = []): string
    {
        $atts = shortcode_atts([
            'service' => '',
            'doctor'  => '',
            'lang'    => '',
            'title'   => '',
            // The "how it works" block normally lives in the page itself (a
            // Bricks section) so the site owner can style it; intro="yes"
            // renders it inside the form instead.
            'intro'   => 'no',
        ], is_array($atts) ? $atts : [], 'easybusy_booking');

        $this->assets->enqueue();

        $clinicPhoneNotice = __('JavaScript is required to book online. Please call the clinic or use the contact form.', 'easybusy-connect');

        $attributes = [
            'class'            => 'ebc-form',
            'data-ebc-service' => (string) absint($atts['service']),
            'data-ebc-doctor'  => (string) absint($atts['doctor']),
            'data-ebc-intro'   => in_array(strtolower((string) $atts['intro']), ['1', 'yes', 'true'], true) ? '1' : '0',
            'data-ebc-lang'    => sanitize_text_field((string) $atts['lang']),
            'data-ebc-booking' => $this->definition->bookingEnabled() ? '1' : '0',
            'data-ebc-grid'    => (string) $this->capabilities->gridMinutes(),
        ];

        $rendered = '';
        foreach ($attributes as $name => $value) {
            $rendered .= sprintf(' %s="%s"', $name, esc_attr($value));
        }

        $heading = trim((string) $atts['title']) !== ''
            ? sprintf('<h2 class="ebc-form__title">%s</h2>', esc_html((string) $atts['title']))
            : '';

        return sprintf(
            '<div%1$s>%2$s<div class="ebc-form__mount" aria-live="polite"><p class="ebc-form__loading">%3$s</p></div><noscript><p class="ebc-form__noscript">%4$s</p></noscript></div>',
            $rendered,
            $heading,
            esc_html__('Loading…', 'easybusy-connect'),
            esc_html($clinicPhoneNotice)
        );
    }
}
