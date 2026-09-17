<?php

declare(strict_types=1);

namespace EasyBusyConnect;

use EasyBusyConnect\Support\Log;

/**
 * E-mail on submission: the clinic needs to know a request arrived even if
 * nobody is watching EasyBusy, and the patient needs an acknowledgement that is
 * explicitly *not* a confirmation (EasyBusy returns status NEW — staff confirm).
 *
 * Subjects and bodies are editable in Settings → Email with {{placeholders}},
 * so wording changes never need a developer. Dry-run submissions never send
 * mail; they only log.
 */
final class Notify
{
    /** Placeholders offered in the admin UI, in the order they are listed. */
    public const PLACEHOLDERS = [
        '{{clinic}}'      => 'Site/clinic name',
        '{{reference}}'   => 'EasyBusy reference id',
        '{{service}}'     => 'Service name',
        '{{doctor}}'      => 'Specialist name',
        '{{start}}'       => 'Requested date and time',
        '{{duration}}'    => 'Appointment length in minutes',
        '{{patient}}'     => 'Patient name',
        '{{email}}'       => 'Patient e-mail',
        '{{phone}}'       => 'Patient phone',
        '{{message}}'     => 'Patient message',
        '{{type}}'        => 'booking or lead',
        '{{admin_url}}'   => 'Link to the Entries screen',
    ];

    /** Last wp_mail() failure, so a dropped notification is never silent. */
    public const ERROR_OPTION = 'ebc_mail_error';

    /** @return array<string,string> */
    public static function defaults(): array
    {
        return [
            'notify_admin_subject' => __('[{{clinic}}] New appointment request: {{service}}', 'easybusy-connect'),
            'notify_admin_body'    => implode("\n", [
                __('A new request arrived from the website.', 'easybusy-connect'),
                '',
                __('Service: {{service}}', 'easybusy-connect'),
                __('Specialist: {{doctor}}', 'easybusy-connect'),
                __('Requested time: {{start}} ({{duration}} min)', 'easybusy-connect'),
                '',
                __('Patient: {{patient}}', 'easybusy-connect'),
                __('E-mail: {{email}}', 'easybusy-connect'),
                __('Phone: {{phone}}', 'easybusy-connect'),
                '',
                __('Message:', 'easybusy-connect'),
                '{{message}}',
                '',
                __('EasyBusy reference: {{reference}}', 'easybusy-connect'),
                __('Local record: {{admin_url}}', 'easybusy-connect'),
            ]),
            'notify_patient_subject' => __('{{clinic}} — we received your request', 'easybusy-connect'),
            'notify_patient_body'    => implode("\n", [
                __('Hello {{patient}},', 'easybusy-connect'),
                '',
                __('We have received your appointment request. This is not a confirmed appointment yet — our team will contact you to confirm it.', 'easybusy-connect'),
                '',
                __('Service: {{service}}', 'easybusy-connect'),
                __('Requested time: {{start}}', 'easybusy-connect'),
                '',
                '{{clinic}}',
            ]),
        ];
    }

    public static function template(string $key): string
    {
        $stored = trim((string) Settings::get($key, ''));

        return $stored !== '' ? $stored : (self::defaults()[$key] ?? '');
    }

    /**
     * @param array<string,mixed> $submission Row data as recorded locally.
     */
    public function dispatch(array $submission): void
    {
        if (!empty($submission['dry_run'])) {
            Log::add('info', 'notification skipped (dry run)', ['dry_run' => true]);

            return;
        }

        $vars = $this->variables($submission);

        $this->send(
            $this->recipients(),
            self::template('notify_admin_subject'),
            self::template('notify_admin_body'),
            $vars,
            'clinic'
        );

        if ((bool) Settings::get('notify_patient', true)) {
            $email = (string) ($submission['patient_email'] ?? '');
            if ($email !== '' && is_email($email)) {
                $this->send([$email], self::template('notify_patient_subject'), self::template('notify_patient_body'), $vars, 'patient');
            }
        }
    }

    /**
     * Sends one preview of both templates with sample data, so wording can be
     * checked without waiting for a real booking.
     *
     * @return array{sent:int,to:array<int,string>}
     */
    public function sendTest(): array
    {
        $vars = $this->variables([
            'service_name'  => __('Sample service', 'easybusy-connect'),
            'doctor_name'   => __('Sample specialist', 'easybusy-connect'),
            'start_at'      => current_time('mysql'),
            'duration_min'  => 30,
            'patient_name'  => __('Sample Patient', 'easybusy-connect'),
            'patient_email' => (string) get_option('admin_email'),
            'patient_phone' => '385900000000',
            'message'       => __('This is a test message.', 'easybusy-connect'),
            'easybusy_id'   => '000000',
            'type'          => 'booking',
        ]);

        $recipients = $this->recipients();
        $sent = 0;

        if ($this->send($recipients, '[TEST] ' . self::template('notify_admin_subject'), self::template('notify_admin_body'), $vars, 'test-clinic')) {
            $sent++;
        }
        if ($this->send($recipients, '[TEST] ' . self::template('notify_patient_subject'), self::template('notify_patient_body'), $vars, 'test-patient')) {
            $sent++;
        }

        return ['sent' => $sent, 'to' => $recipients];
    }

    /**
     * @param array<int,string>   $to
     * @param array<string,string> $vars
     */
    private function send(array $to, string $subject, string $body, array $vars, string $kind): bool
    {
        if ($to === []) {
            return false;
        }

        // wp_mail() only returns false; the reason arrives through this action.
        $reason = '';
        $capture = static function (\WP_Error $error) use (&$reason): void {
            $reason = $error->get_error_message();
        };
        add_action('wp_mail_failed', $capture);

        $sent = wp_mail(
            $to,
            self::fill($subject, $vars),
            self::fill($body, $vars),
            $this->headers()
        );

        remove_action('wp_mail_failed', $capture);

        Log::add($sent ? 'info' : 'warning', 'notification ' . $kind, array_filter([
            'count'  => count($to),
            'reason' => $sent ? '' : $reason,
        ]));

        // A silently dropped notification is worse than a failed booking, so the
        // last failure is kept where the admin screens can surface it.
        if ($sent) {
            delete_option(self::ERROR_OPTION);
        } else {
            update_option(self::ERROR_OPTION, [
                'at'     => current_time('mysql'),
                'kind'   => $kind,
                'reason' => $reason !== '' ? $reason : __('WordPress reported a failure without a reason.', 'easybusy-connect'),
            ], false);
        }

        return (bool) $sent;
    }

    /**
     * @return array{at:string,kind:string,reason:string}|null
     */
    public static function lastError(): ?array
    {
        $stored = get_option(self::ERROR_OPTION, null);

        return is_array($stored) && isset($stored['reason']) ? $stored : null;
    }

    /** @return array<int,string> */
    private function headers(): array
    {
        $name = trim((string) Settings::get('email_from_name', ''));
        $address = trim((string) Settings::get('email_from_address', ''));

        if ($address === '' || !is_email($address)) {
            return [];
        }
        if ($name === '') {
            $name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        }

        return [sprintf('From: %s <%s>', $name, $address)];
    }

    /**
     * @param array<string,mixed> $submission
     * @return array<string,string>
     */
    private function variables(array $submission): array
    {
        return [
            '{{clinic}}'    => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            '{{reference}}' => (string) ($submission['easybusy_id'] ?? '—'),
            '{{service}}'   => (string) ($submission['service_name'] ?? '—'),
            '{{doctor}}'    => (string) ($submission['doctor_name'] ?: __('no preference', 'easybusy-connect')),
            '{{start}}'     => $this->humanStart((string) ($submission['start_at'] ?? '')),
            '{{duration}}'  => (string) ($submission['duration_min'] ?? ''),
            '{{patient}}'   => (string) ($submission['patient_name'] ?? '—'),
            '{{email}}'     => (string) ($submission['patient_email'] ?? '—'),
            '{{phone}}'     => (string) ($submission['patient_phone'] ?? '—'),
            '{{message}}'   => (string) ($submission['message'] ?: '—'),
            '{{type}}'      => (string) ($submission['type'] ?? 'booking'),
            '{{admin_url}}' => \EasyBusyConnect\Admin\Menu::url(),
        ];
    }

    /**
     * "2026-09-19 11:30:00" reads like a database dump in a patient e-mail.
     * Formats with the site's locale/timezone instead.
     */
    private function humanStart(string $start): string
    {
        if (trim($start) === '') {
            return __('to be agreed', 'easybusy-connect');
        }

        $timestamp = strtotime($start);

        return $timestamp === false
            ? $start
            : date_i18n(
                sprintf('%s, %s', (string) get_option('date_format', 'j.n.Y.'), (string) get_option('time_format', 'H:i')),
                $timestamp
            );
    }

    /** @param array<string,string> $vars */
    public static function fill(string $template, array $vars): string
    {
        return str_replace(array_keys($vars), array_values($vars), $template);
    }

    /** @return array<int,string> */
    private function recipients(): array
    {
        $raw = (string) Settings::get('notify_emails', '');
        if (trim($raw) === '') {
            $raw = (string) get_option('admin_email', '');
        }

        $emails = array_filter(array_map('trim', preg_split('/[,\s]+/', $raw) ?: []), 'is_email');

        return array_values(array_unique($emails));
    }
}
