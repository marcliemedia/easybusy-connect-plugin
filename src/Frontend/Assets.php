<?php

declare(strict_types=1);

namespace EasyBusyConnect\Frontend;

/**
 * Assets load only on pages that actually render the form, so the rest of the
 * Bricks site pays nothing for it.
 */
final class Assets
{
    private bool $enqueued = false;

    public function enqueue(): void
    {
        if ($this->enqueued) {
            return;
        }
        $this->enqueued = true;

        $base = plugins_url('assets', EBC_FILE);

        wp_enqueue_style('easybusy-connect', $base . '/css/form.css', [], EBC_VERSION);
        wp_enqueue_script('easybusy-connect', $base . '/js/form.js', [], EBC_VERSION, true);

        wp_localize_script('easybusy-connect', 'ebcRuntime', [
            'rest' => rest_url('easybusy/v1'),
            'i18n' => $this->strings(),
        ]);

        // Slot ids go stale in seconds; never let a page cache serve them.
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
    }

    /**
     * Marks the booking and thank-you pages uncacheable at the HTTP level.
     *
     * Runs on template_redirect — headers must go out before the theme prints
     * anything, so the enqueue path (which happens mid-content) is far too late.
     * Detection reads the Bricks layout as well as post_content, because Bricks
     * keeps the shortcode in `_bricks_page_content_2` and never in the post.
     */
    public static function sendNoStoreHeaders(): void
    {
        if (is_admin() || headers_sent()) {
            return;
        }

        $post = get_queried_object();
        if (!$post instanceof \WP_Post) {
            return;
        }

        $haystack = $post->post_content;
        $bricks = get_post_meta($post->ID, '_bricks_page_content_2', true);
        if ($bricks !== '' && $bricks !== false) {
            $haystack .= is_scalar($bricks) ? (string) $bricks : (string) wp_json_encode($bricks);
        }

        // Shortcodes and the Bricks element slug; the thank-you page carries
        // personal data and must never be cached either.
        $matched = false;
        foreach (['easybusy_booking', 'easybusy-booking', 'easybusy_thank_you'] as $marker) {
            if (str_contains($haystack, $marker)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            return;
        }

        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
    }

    /** @return array<string,string> */
    private function strings(): array
    {
        return [
            'loading'          => __('Loading…', 'easybusy-connect'),
            'next'             => __('Continue', 'easybusy-connect'),
            'back'             => __('Back', 'easybusy-connect'),
            'submit'           => __('Send request', 'easybusy-connect'),
            'sending'          => __('Sending…', 'easybusy-connect'),
            'service'          => __('Service', 'easybusy-connect'),
            'anyDoctor'        => __('No preference', 'easybusy-connect'),
            'doctor'           => __('Specialist', 'easybusy-connect'),
            'messageLabel'     => __('What would you like to tell the clinic?', 'easybusy-connect'),
            'messagePlaceholder' => __('Briefly describe your issue or the reason for the appointment.', 'easybusy-connect'),
            'preferredTime'    => __('Preferred time (optional)', 'easybusy-connect'),
            'noSlots'          => __('No free appointments in the selected period. Leave your details and the clinic will contact you.', 'easybusy-connect'),
            'noSlotsClosed'    => __('There are no free appointments for this service yet. The clinic publishes its online schedule in its booking system — please get in touch and we will arrange a time with you.', 'easybusy-connect'),
            'contactClinic'    => __('Contact the clinic', 'easybusy-connect'),
            'free'             => __('Free of charge', 'easybusy-connect'),
            'duration'         => __('%d min', 'easybusy-connect'),
            'firstName'        => __('First name', 'easybusy-connect'),
            'lastName'         => __('Last name', 'easybusy-connect'),
            'email'            => __('E-mail', 'easybusy-connect'),
            'phone'            => __('Phone (with country code)', 'easybusy-connect'),
            'personalId'       => __('OIB (personal identification number)', 'easybusy-connect'),
            'country'          => __('Country', 'easybusy-connect'),
            'streetName'       => __('Street', 'easybusy-connect'),
            'streetNumber'     => __('Number', 'easybusy-connect'),
            'postalCode'       => __('Postal code', 'easybusy-connect'),
            'city'             => __('City', 'easybusy-connect'),
            'consentDefault'   => __('I agree to the processing of my personal data for the purpose of this appointment request.', 'easybusy-connect'),
            'consentLink'      => __('Privacy policy', 'easybusy-connect'),
            'optional'         => __('optional', 'easybusy-connect'),
            'summaryTitle'     => __('Your selection', 'easybusy-connect'),
            'requestReceived'  => __('Request received', 'easybusy-connect'),
            'notConfirmed'     => __('This is a request, not a confirmed appointment. The clinic will contact you to confirm.', 'easybusy-connect'),
            'appointmentId'    => __('Reference number', 'easybusy-connect'),
            'dryRunNotice'     => __('Test mode: the request was assembled and validated, but nothing was sent to EasyBusy.', 'easybusy-connect'),
            'genericError'     => __('Something went wrong. Please try again.', 'easybusy-connect'),
            'restart'          => __('Start over', 'easybusy-connect'),
            'chooseDay'        => __('Choose a day', 'easybusy-connect'),
            'chooseTime'       => __('Choose a time', 'easybusy-connect'),
            'step'             => __('Step %1$d of %2$d', 'easybusy-connect'),
            'yourDetails'      => __('Your details', 'easybusy-connect'),
            'appointment'      => __('Appointment', 'easybusy-connect'),
            'attachmentsLabel' => __('Attach an X-ray or document (max %1$d files, %2$d MB each)', 'easybusy-connect'),
            'uploading'        => __('Uploading…', 'easybusy-connect'),
            'prevMonth'        => __('Previous month', 'easybusy-connect'),
            'nextMonth'        => __('Next month', 'easybusy-connect'),
            'legendFree'       => __('Free appointments', 'easybusy-connect'),
            'legendSelected'   => __('Selected day', 'easybusy-connect'),
            'slotsAvailable'   => __('%d free times', 'easybusy-connect'),
            'errorTitle'       => __('The request was not sent', 'easybusy-connect'),
            'errorHint'        => __('Please try again, or call the clinic if the problem persists.', 'easybusy-connect'),
            'fixFields'        => __('Please correct the highlighted fields.', 'easybusy-connect'),
        ];
    }
}
