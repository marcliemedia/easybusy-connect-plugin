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
            'duration'         => __('%d min', 'easybusy-connect'),
            'firstName'        => __('First name', 'easybusy-connect'),
            'lastName'         => __('Last name', 'easybusy-connect'),
            'email'            => __('E-mail', 'easybusy-connect'),
            'phone'            => __('Phone (with country code)', 'easybusy-connect'),
            'personalId'       => __('OIB (personal identification number)', 'easybusy-connect'),
            'countryCode'      => __('Country code', 'easybusy-connect'),
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
