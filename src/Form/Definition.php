<?php

declare(strict_types=1);

namespace EasyBusyConnect\Form;

use EasyBusyConnect\Api\Capabilities;
use EasyBusyConnect\Settings;
use EasyBusyConnect\Support\Countries;

/**
 * The step graph is derived from the live capability map, not hardcoded:
 * no doctor step unless the clinic supports booking by doctor, and no
 * date/time step unless the simple-booking group is granted (then the form
 * degrades to an inquiry that the clinic calls back).
 */
final class Definition
{
    public function __construct(private Capabilities $capabilities)
    {
    }

    public function bookingEnabled(): bool
    {
        $mode = (string) Settings::get('mode', 'auto');
        if ($mode === 'lead') {
            return false;
        }

        return $this->capabilities->grants('simple_booking');
    }

    /**
     * Three steps, deliberately: choose → when → who you are. The specialist
     * picker lives inside step 1 and the free-text description inside step 3,
     * because every extra step costs completions on a booking form.
     *
     * @return array<int,array{id:string,title:string,hint:string}>
     */
    public function steps(): array
    {
        $steps = [[
            'id'    => 'service',
            'title' => __('Choose a service', 'easybusy-connect'),
            'hint'  => __('Pick what you need; prices come straight from the clinic.', 'easybusy-connect'),
        ]];

        if ($this->bookingEnabled()) {
            $steps[] = [
                'id'    => 'schedule',
                'title' => __('Choose a date and time', 'easybusy-connect'),
                'hint'  => __('Only genuinely free slots are shown.', 'easybusy-connect'),
            ];
        }

        $steps[] = [
            'id'    => 'contact',
            'title' => __('Your details', 'easybusy-connect'),
            'hint'  => __('Tell us briefly what troubles you and how to reach you.', 'easybusy-connect'),
        ];

        return $steps;
    }

    /** Shown above the form so a first-time visitor knows what this page does. */
    public function intro(): array
    {
        $steps = [];
        foreach ($this->steps() as $step) {
            $steps[] = ['title' => $step['title'], 'hint' => $step['hint']];
        }

        return [
            'heading' => __('Book an appointment online', 'easybusy-connect'),
            'lead'    => __('Three short steps, about a minute. You are sending a request — the clinic confirms the exact time with you by phone or e-mail.', 'easybusy-connect'),
            'steps'   => $steps,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{fields:array<string,string>,errors:array<string,string>}
     */
    public function validateContact(array $input): array
    {
        $value = static function (array $input, string $key, int $max = 120): string {
            $raw = isset($input[$key]) ? (string) $input[$key] : '';

            return trim(mb_substr(sanitize_text_field($raw), 0, $max));
        };

        $fields = [
            'firstName'    => $value($input, 'firstName', 60),
            'lastName'     => $value($input, 'lastName', 60),
            'email'        => sanitize_email((string) ($input['email'] ?? '')),
            'phone'        => $this->normalisePhone((string) ($input['phone'] ?? '')),
            'personalId'   => preg_replace('/\D+/', '', (string) ($input['personalId'] ?? '')) ?? '',
            // A booking with a null country is rejected by EasyBusy, so an
            // unknown or missing code degrades to the configured default
            // instead of costing the patient their appointment.
            'countryCode'  => Countries::normalise((string) ($input['countryCode'] ?? '')) ?: Settings::defaultCountry(),
            'streetName'   => $value($input, 'streetName', 80),
            'streetNumber' => $value($input, 'streetNumber', 20),
            'postalCode'   => $value($input, 'postalCode', 12),
            'city'         => $value($input, 'city', 60),
            'consent'      => !empty($input['consent']) ? 'yes' : '',
        ];

        $errors = [];
        if ($fields['firstName'] === '') {
            $errors['firstName'] = __('Please enter your first name.', 'easybusy-connect');
        }
        if ($fields['lastName'] === '') {
            $errors['lastName'] = __('Please enter your last name.', 'easybusy-connect');
        }
        if ($fields['email'] === '' || !is_email($fields['email'])) {
            $errors['email'] = __('Please enter a valid e-mail address.', 'easybusy-connect');
        }
        if (strlen($fields['phone']) < 8) {
            $errors['phone'] = __('Please enter a phone number including the country code.', 'easybusy-connect');
        }
        if ($fields['consent'] === '') {
            $errors['consent'] = __('Please confirm you agree to the processing of your data.', 'easybusy-connect');
        }

        // countryCode is never empty (it falls back to the clinic's default), so
        // OIB no longer needs a companion check: EasyBusy can always auto-match
        // the patient record when an OIB is given.
        if ((bool) Settings::get('require_oib', false) && $fields['personalId'] === '') {
            $errors['personalId'] = __('Please enter your personal identification number (OIB).', 'easybusy-connect');
        }

        return ['fields' => $fields, 'errors' => $errors];
    }

    /** Vendor examples send digits only, without the leading plus. */
    private function normalisePhone(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }

    public function sanitiseMessage(string $message): string
    {
        return trim(mb_substr(wp_strip_all_tags($message), 0, 2000));
    }
}
