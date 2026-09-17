<?php

declare(strict_types=1);

namespace EasyBusyConnect\Frontend;

use EasyBusyConnect\Plugin;

/**
 * Bricks Builder element so the form can be dropped into Bricks templates
 * (this site's layout lives in Bricks post meta, not post_content). Registered
 * only when Bricks is the active theme.
 */
final class BricksElement
{
    public static function register(): void
    {
        if (!class_exists('\Bricks\Elements') || !class_exists('\Bricks\Element')) {
            return;
        }

        require_once EBC_DIR . '/src/Frontend/bricks-element-class.php';

        if (class_exists('\EasyBusyConnect\Frontend\BricksBookingElement')) {
            \Bricks\Elements::register_element(
                EBC_DIR . '/src/Frontend/bricks-element-class.php',
                'easybusy-booking',
                '\EasyBusyConnect\Frontend\BricksBookingElement'
            );
        }
    }

    /** Shared renderer used by both the Bricks element and the shortcode. */
    public static function markup(string $service, string $doctor, string $title): string
    {
        return Plugin::get(Shortcode::class)->render([
            'service' => $service,
            'doctor'  => $doctor,
            'title'   => $title,
        ]);
    }
}
