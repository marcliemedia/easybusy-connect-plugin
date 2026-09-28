<?php

declare(strict_types=1);

namespace EasyBusyConnect\Admin;

use EasyBusyConnect\Api\Capabilities;
use EasyBusyConnect\Api\Catalog;
use EasyBusyConnect\Api\Slots;
use EasyBusyConnect\Notify;
use EasyBusyConnect\Settings;
use EasyBusyConnect\Support\Countries;
use EasyBusyConnect\Support\Log;
use EasyBusyConnect\Support\Tz;

/**
 * EasyBusy → Settings.
 *
 * Deliberately not built from `form-table` / `.wrap`: the screen uses the
 * plugin's own card + tab shell (see assets/css/admin.css) so a clinic admin
 * gets one coherent product surface instead of a wall of WordPress rows.
 * Tabs are real URLs (`&tab=…`) so a link can point at one, with JavaScript
 * switching them instantly when it is available.
 */
final class SettingsPage
{
    public const TABS = ['connection', 'form', 'email', 'privacy', 'advanced'];

    /** Same ceiling as Entries: no table on these screens exceeds 20 rows. */
    private const LOG_PER_PAGE = 20;

    public function __construct(
        private Capabilities $capabilities,
        private Catalog $catalog,
        private Slots $slots
    ) {
    }

    /** @return array<string,string> */
    private function tabLabels(): array
    {
        return [
            'connection' => __('Connection', 'easybusy-connect'),
            'form'       => __('Booking form', 'easybusy-connect'),
            'email'      => __('Email', 'easybusy-connect'),
            'privacy'    => __('Privacy & data', 'easybusy-connect'),
            'advanced'   => __('Advanced', 'easybusy-connect'),
        ];
    }

    public function renderNotices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (Settings::apiKeySource() === 'missing') {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__('EasyBusy Connect has no API key. Define EASYBUSY_API_KEY in wp-config.php.', 'easybusy-connect')
            );
        }

        // A fixed UTC offset cannot observe DST, so every timestamp sent to
        // EasyBusy would be an hour off for half the year.
        if (Tz::hasFixedOffset()) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__('This site uses a fixed UTC offset instead of a named timezone. Set Settings → General → Timezone to a city (e.g. Zagreb) before taking bookings, otherwise daylight saving shifts every appointment by an hour.', 'easybusy-connect')
            );
        }
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = Settings::all();
        $map = $this->capabilities->get();
        $active = isset($_GET['tab']) && in_array((string) $_GET['tab'], self::TABS, true)
            ? (string) $_GET['tab']
            : 'connection';

        echo '<div class="ebc-admin">';
        Shell::header(
            __('EasyBusy Connect by Marclie', 'easybusy-connect'),
            __('Booking form, submissions and clinic notifications.', 'easybusy-connect'),
            $this->statusPills($map)
        );
        Shell::flash();
        $this->renderTabs($active);

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ebc-admin__form">';
        wp_nonce_field('ebc_save');
        echo '<input type="hidden" name="action" value="ebc_save">';
        printf('<input type="hidden" name="tab" value="%s">', esc_attr($active));

        $this->renderPanel('connection', $active, fn () => $this->panelConnection($settings, $map));
        $this->renderPanel('form', $active, fn () => $this->panelForm($settings));
        $this->renderPanel('email', $active, fn () => $this->panelEmail($settings));
        $this->renderPanel('privacy', $active, fn () => $this->panelPrivacy($settings));
        $this->renderPanel('advanced', $active, fn () => $this->panelAdvanced($settings));

        printf(
            '<div class="ebc-admin__actions"><button type="submit" class="ebc-btn ebc-btn--primary">%s</button><span class="ebc-admin__hint">%s</span></div>',
            esc_html__('Save changes', 'easybusy-connect'),
            esc_html__('Settings apply immediately; caches are refreshed on save.', 'easybusy-connect')
        );
        echo '</form>';
        Shell::credit();
        echo '</div>';
    }

    private function renderTabs(string $active): void
    {
        echo '<nav class="ebc-tabs" role="tablist">';
        foreach ($this->tabLabels() as $slug => $label) {
            printf(
                '<a class="ebc-tabs__tab%1$s" role="tab" aria-selected="%2$s" href="%3$s" data-ebc-tab="%4$s">%5$s</a>',
                $slug === $active ? ' is-active' : '',
                $slug === $active ? 'true' : 'false',
                esc_url(Menu::url(Menu::SETTINGS_SLUG, ['tab' => $slug])),
                esc_attr($slug),
                esc_html($label)
            );
        }
        echo '</nav>';
    }

    private function renderPanel(string $slug, string $active, callable $body): void
    {
        printf(
            '<section class="ebc-panel%s" data-ebc-panel="%s">',
            $slug === $active ? ' is-active' : '',
            esc_attr($slug)
        );
        $body();
        echo '</section>';
    }

    /* ---------------- panels ---------------- */

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $map
     */
    private function panelConnection(array $settings, array $map): void
    {
        Shell::cardOpen(__('API connection', 'easybusy-connect'), __('Measured against the live EasyBusy API — never assumed.', 'easybusy-connect'));

        echo '<div class="ebc-grid ebc-grid--2">';
        Shell::stat(__('API key source', 'easybusy-connect'), Settings::apiKeySource());
        Shell::stat(__('Vendor reachable', 'easybusy-connect'), !empty($map['vendor_up']) ? __('yes', 'easybusy-connect') : __('no', 'easybusy-connect'));
        Shell::stat(__('Key authenticates', 'easybusy-connect'), !empty($map['key_valid']) ? __('yes', 'easybusy-connect') : __('no', 'easybusy-connect'));
        Shell::stat(__('Clinic languages', 'easybusy-connect'), implode(', ', array_keys((array) ($map['languages'] ?? []))) ?: '—');
        Shell::stat(__('Scheduling grid', 'easybusy-connect'), $this->capabilities->gridMinutes() . ' min');
        Shell::stat(__('Last probe', 'easybusy-connect'), (string) ($map['probed_at'] ?? '—'));
        echo '</div>';

        echo '<h4 class="ebc-subhead">' . esc_html__('Granted endpoint groups', 'easybusy-connect') . '</h4>';
        echo '<div class="ebc-chips">';
        foreach ((array) ($map['groups'] ?? []) as $group => $granted) {
            printf(
                '<span class="ebc-chip%s">%s</span>',
                $granted ? ' is-ok' : ' is-off',
                esc_html((string) $group)
            );
        }
        echo '</div>';

        // A group the probe reported as reachable but a real call refused. Without
        // this the screen contradicts itself: a green leads chip next to a form
        // that refuses attachments.
        foreach ((array) ($map['denied'] ?? []) as $group => $denial) {
            printf(
                '<p class="ebc-help">%s</p>',
                esc_html(sprintf(
                    /* translators: 1: endpoint group, 2: date, 3: vendor message */
                    __('“%1$s” answered as available but the vendor refused a live call on %2$s: %3$s Ask EasyBusy to add the group to the key, then re-probe.', 'easybusy-connect'),
                    (string) $group,
                    (string) ($denial['at'] ?? ''),
                    (string) ($denial['reason'] ?? '')
                ))
            );
        }

        if (!empty($map['probes'])) {
            echo '<details class="ebc-details"><summary>' . esc_html__('Probe results', 'easybusy-connect') . '</summary><pre>';
            foreach ((array) $map['probes'] as $endpoint => $status) {
                printf("%s → %s\n", esc_html((string) $endpoint), esc_html((string) $status));
            }
            echo '</pre></details>';
        }

        $this->actionButton('ebc_probe', __('Re-probe capabilities', 'easybusy-connect'));
        Shell::cardClose();

        Shell::cardOpen(__('API key', 'easybusy-connect'), __('Swap the EasyBusy key here when the vendor issues a new one.', 'easybusy-connect'));
        Shell::stat(__('Currently used key', 'easybusy-connect'), $this->maskedKey());
        Shell::stat(__('Loaded from', 'easybusy-connect'), $this->keySourceLabel());

        if (Settings::apiKeySource() === 'wp-config') {
            printf(
                '<p class="ebc-help ebc-help--warn">%s</p>',
                esc_html__('A key is defined in code (wp-config.php or an mu-plugin). That definition always wins — remove EASYBUSY_API_KEY there before a key entered here can take effect.', 'easybusy-connect')
            );
        }

        Shell::text(
            'api_key',
            __('New API key', 'easybusy-connect'),
            '',
            __('Paste the key from EasyBusy support. Leave empty to keep the current one. Stored in the database, so prefer a wp-config.php constant for production.', 'easybusy-connect'),
            'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX'
        );
        $this->actionButton('ebc_probe', __('Test this key now', 'easybusy-connect'));
        Shell::cardClose();

        Shell::cardOpen(__('Write mode', 'easybusy-connect'), __('Dry run assembles and validates submissions without sending anything to EasyBusy.', 'easybusy-connect'));
        Shell::toggle('dry_run', __('Dry run (test mode)', 'easybusy-connect'), (bool) $settings['dry_run'], __('Entries are still recorded locally, and no e-mail is sent.', 'easybusy-connect'));
        Shell::select('mode', __('Submission mode', 'easybusy-connect'), [
            'auto'    => __('Auto — book when the API allows it', 'easybusy-connect'),
            'booking' => __('Booking only', 'easybusy-connect'),
            'lead'    => __('Inquiry (lead) only', 'easybusy-connect'),
        ], (string) $settings['mode']);
        Shell::cardClose();
    }

    /** Never prints the key: first and last four characters only. */
    private function maskedKey(): string
    {
        $key = Settings::apiKey();
        if ($key === '') {
            return __('not configured', 'easybusy-connect');
        }
        if (strlen($key) <= 10) {
            return str_repeat('•', strlen($key));
        }

        return substr($key, 0, 4) . str_repeat('•', 8) . substr($key, -4);
    }

    private function keySourceLabel(): string
    {
        return match (Settings::apiKeySource()) {
            'wp-config' => __('code constant (wp-config.php / mu-plugin)', 'easybusy-connect'),
            'option'    => __('this settings screen (database)', 'easybusy-connect'),
            default     => __('nowhere — the integration is inactive', 'easybusy-connect'),
        };
    }

    /** @param array<string,mixed> $settings */
    private function panelForm(array $settings): void
    {
        Shell::cardOpen(__('Steps and fields', 'easybusy-connect'), __('What the patient is asked for. Fewer fields convert better.', 'easybusy-connect'));
        Shell::number('slot_horizon', __('Slot search horizon (days)', 'easybusy-connect'), (int) $settings['slot_horizon']);
        Shell::toggle('require_oib', __('Require OIB', 'easybusy-connect'), (bool) $settings['require_oib'], __('Lets EasyBusy match the patient record automatically; adds one required field.', 'easybusy-connect'));
        Shell::toggle('collect_address', __('Collect address', 'easybusy-connect'), (bool) $settings['collect_address']);
        Shell::select(
            'default_country',
            __('Default country', 'easybusy-connect'),
            Countries::options(determine_locale()),
            Settings::defaultCountry(),
            __('Preselected in the form. EasyBusy refuses a booking without a country, so one is always sent.', 'easybusy-connect')
        );
        Shell::toggle('attachments', __('Allow attachments', 'easybusy-connect'), (bool) $settings['attachments'], __('X-ray or PDF. Files reach EasyBusy with a lead — the booking API has no upload endpoint.', 'easybusy-connect'));
        Shell::number('attachment_max_files', __('Max files per submission', 'easybusy-connect'), (int) $settings['attachment_max_files']);
        Shell::number('attachment_max_mb', __('Max file size (MB)', 'easybusy-connect'), (int) $settings['attachment_max_mb']);
        Shell::text('ui_locale', __('Form language (WordPress locale)', 'easybusy-connect'), (string) $settings['ui_locale'], __('e.g. hr — the form speaks this language even if the site is en_US.', 'easybusy-connect'));
        Shell::cardClose();

        Shell::cardOpen(__('Consent and confirmation', 'easybusy-connect'), __('Wording shown next to the consent checkbox, and where the patient lands afterwards.', 'easybusy-connect'));
        Shell::textarea('consent_text', __('Consent text', 'easybusy-connect'), (string) $settings['consent_text'], 3);
        Shell::text('consent_url', __('Consent link URL', 'easybusy-connect'), (string) $settings['consent_url']);
        Shell::pages('thank_you_page', __('Thank-you page', 'easybusy-connect'), (int) $settings['thank_you_page'], __('Must contain [easybusy_thank_you]. A separate URL lets Google Ads / GA4 measure the conversion on a page view.', 'easybusy-connect'));
        Shell::cardClose();

        Shell::cardOpen(__('Fluent Forms bridge', 'easybusy-connect'), __('Forward existing form submissions to EasyBusy as leads.', 'easybusy-connect'));
        Shell::text('ff_forms', __('Form ids to forward', 'easybusy-connect'), (string) $settings['ff_forms'], __('Comma separated, e.g. 4. Leave empty to disable.', 'easybusy-connect'));
        Shell::cardClose();
    }

    /** @param array<string,mixed> $settings */
    private function panelEmail(array $settings): void
    {
        $mailError = Notify::lastError();
        if ($mailError !== null) {
            printf(
                '<div class="ebc-flash ebc-flash--error" role="alert">%s</div>',
                esc_html(sprintf(
                    /* translators: 1: reason, 2: date */
                    __('WordPress could not send the last e-mail: "%1$s" (%2$s). PHP mail() on this host is unreliable — install and configure an SMTP plugin, otherwise clinic and patient notifications will be dropped silently once test mode is off.', 'easybusy-connect'),
                    $mailError['reason'],
                    $mailError['at']
                ))
            );
        }

        Shell::cardOpen(__('Recipients and sender', 'easybusy-connect'), __('Who hears about a new request, and what the patient sees as the sender.', 'easybusy-connect'));
        Shell::text('notify_emails', __('Notify these e-mails', 'easybusy-connect'), (string) $settings['notify_emails'], __('Comma separated. Empty falls back to the WordPress admin e-mail.', 'easybusy-connect'));
        Shell::text('email_from_name', __('From name', 'easybusy-connect'), (string) $settings['email_from_name'], __('Optional. Empty uses the site name.', 'easybusy-connect'));
        Shell::text('email_from_address', __('From address', 'easybusy-connect'), (string) $settings['email_from_address'], __('Optional. Must be a valid address your SMTP is allowed to send from.', 'easybusy-connect'));
        Shell::cardClose();

        Shell::cardOpen(__('Clinic notification', 'easybusy-connect'), __('Sent to the recipients above for every real submission.', 'easybusy-connect'));
        $this->placeholderHelp();
        Shell::text('notify_admin_subject', __('Subject', 'easybusy-connect'), (string) $settings['notify_admin_subject'], '', Notify::template('notify_admin_subject'));
        Shell::textarea('notify_admin_body', __('Body', 'easybusy-connect'), (string) $settings['notify_admin_body'], 12, __('Leave empty to use the default template shown as placeholder.', 'easybusy-connect'), Notify::template('notify_admin_body'));
        Shell::cardClose();

        Shell::cardOpen(__('Patient acknowledgement', 'easybusy-connect'), __('Deliberately worded as "request received", never as a confirmed appointment.', 'easybusy-connect'));
        Shell::toggle('notify_patient', __('E-mail the patient', 'easybusy-connect'), (bool) $settings['notify_patient']);
        Shell::text('notify_patient_subject', __('Subject', 'easybusy-connect'), (string) $settings['notify_patient_subject'], '', Notify::template('notify_patient_subject'));
        Shell::textarea('notify_patient_body', __('Body', 'easybusy-connect'), (string) $settings['notify_patient_body'], 10, '', Notify::template('notify_patient_body'));
        Shell::cardClose();

        Shell::cardOpen(__('Test', 'easybusy-connect'), __('Sends both templates with sample data to the recipients above.', 'easybusy-connect'));
        echo '<p class="ebc-admin__hint">' . esc_html__('Save your changes first — the test uses the stored templates.', 'easybusy-connect') . '</p>';
        $this->actionButton('ebc_test_email', __('Send test e-mails', 'easybusy-connect'));
        Shell::cardClose();
    }

    /** @param array<string,mixed> $settings */
    private function panelPrivacy(array $settings): void
    {
        Shell::cardOpen(
            __('What an entry keeps about the person', 'easybusy-connect'),
            __('Submissions are health-adjacent data. Entries exist so the clinic can review leads and diagnose a failed send — not as a second patient database.', 'easybusy-connect')
        );
        Shell::select('store_mode', __('Storage level', 'easybusy-connect'), [
            'minimal' => __('Minimal — initials, e-mail provider, masked phone (recommended)', 'easybusy-connect'),
            'full'    => __('Full — name, e-mail, phone and message', 'easybusy-connect'),
            'none'    => __('None — nothing about the person', 'easybusy-connect'),
        ], Settings::storeMode());

        echo '<div class="ebc-matrix">';
        foreach ([
            [
                __('Minimal', 'easybusy-connect'),
                __('Kept: initials ("A. H."), e-mail provider ("@gmail.com"), masked phone ("+385 ••• 33"), message length, and a salted one-way hash that flags a returning visitor. Enough to review, count and deduplicate leads.', 'easybusy-connect'),
                __('Never written: full name, e-mail address, phone number, message text.', 'easybusy-connect'),
            ],
            [
                __('Full', 'easybusy-connect'),
                __('Kept: everything above plus the real name, e-mail, phone and the message the patient typed. Only choose this if the clinic works the list from WordPress.', 'easybusy-connect'),
                __('Consider a shorter retention window and review who has admin access.', 'easybusy-connect'),
            ],
            [
                __('None', 'easybusy-connect'),
                __('Kept: the request only — service, specialist, requested time, status, EasyBusy reference.', 'easybusy-connect'),
                __('Nothing links a row to a person, so returning visitors cannot be detected.', 'easybusy-connect'),
            ],
        ] as [$title, $kept, $note]) {
            printf(
                '<div class="ebc-matrix__item"><h4>%s</h4><p>%s</p><p class="ebc-help">%s</p></div>',
                esc_html($title),
                esc_html($kept),
                esc_html($note)
            );
        }
        echo '</div>';

        printf(
            '<p class="ebc-help ebc-help--warn">%s</p>',
            esc_html__('Masking happens when the row is written, so changing this only affects future requests — existing rows keep whatever the previous level stored. OIB is never stored in any level.', 'easybusy-connect')
        );
        Shell::cardClose();

        Shell::cardOpen(__('Retention', 'easybusy-connect'));
        Shell::number('retention_days', __('Entry retention (days)', 'easybusy-connect'), (int) $settings['retention_days'], __('0 keeps entries forever. Older rows and abandoned uploads are purged daily.', 'easybusy-connect'));
        Shell::toggle('delete_data_on_uninstall', __('Delete all plugin data on uninstall', 'easybusy-connect'), (bool) $settings['delete_data_on_uninstall'], __('Drops the entries table and settings when the plugin is deleted.', 'easybusy-connect'));
        Shell::cardClose();
    }

    /** @param array<string,mixed> $settings */
    private function panelAdvanced(array $settings): void
    {
        Shell::cardOpen(__('Caching', 'easybusy-connect'), __('Services and specialists are always read live from EasyBusy, so a change in the clinic shows up on the next visit. Only slot lists are cached, for seconds: a cached slot id is a dead booking.', 'easybusy-connect'));
        Shell::number('ttl_slots', __('Slot cache (seconds)', 'easybusy-connect'), (int) $settings['ttl_slots']);
        $this->actionButton('ebc_purge', __('Purge EasyBusy cache', 'easybusy-connect'));
        Shell::cardClose();

        Shell::cardOpen(__('API activity', 'easybusy-connect'), __('Calls to EasyBusy, newest first. No patient data is logged.', 'easybusy-connect'));
        $entries = array_reverse(Log::tail(Log::LIMIT));
        $total = count($entries);

        if ($total === 0) {
            echo '<p class="ebc-admin__hint">' . esc_html__('Nothing logged yet.', 'easybusy-connect') . '</p>';
        } else {
            $pages = (int) ceil($total / self::LOG_PER_PAGE);
            $page = min(max(1, (int) ($_GET['log_page'] ?? 1)), $pages);
            $slice = array_slice($entries, ($page - 1) * self::LOG_PER_PAGE, self::LOG_PER_PAGE);

            echo '<div class="ebc-table-wrap"><table class="ebc-table"><thead><tr>';
            printf('<th>%s</th><th>%s</th><th>%s</th><th>%s</th>',
                esc_html__('When', 'easybusy-connect'),
                esc_html__('Level', 'easybusy-connect'),
                esc_html__('Message', 'easybusy-connect'),
                esc_html__('Context', 'easybusy-connect')
            );
            echo '</tr></thead><tbody>';
            foreach ($slice as $entry) {
                printf(
                    '<tr><td class="ebc-table__mono">%s</td><td><span class="ebc-chip ebc-chip--%s">%s</span></td><td>%s</td><td class="ebc-table__mono">%s</td></tr>',
                    esc_html((string) $entry['at']),
                    esc_attr((string) $entry['level']),
                    esc_html((string) $entry['level']),
                    esc_html((string) $entry['message']),
                    esc_html((string) (wp_json_encode($entry['context']) ?: ''))
                );
            }
            echo '</tbody></table></div>';

            Shell::pagination(
                $page,
                $pages,
                $total,
                self::LOG_PER_PAGE,
                Menu::url(Menu::SETTINGS_SLUG, ['tab' => 'advanced']),
                'log_page'
            );
        }
        Shell::cardClose();
    }

    private function placeholderHelp(): void
    {
        echo '<div class="ebc-placeholders"><span class="ebc-placeholders__label">' . esc_html__('Placeholders', 'easybusy-connect') . '</span>';
        foreach (Notify::PLACEHOLDERS as $token => $description) {
            printf(
                '<button type="button" class="ebc-placeholders__token" data-ebc-insert="%1$s" title="%2$s">%1$s</button>',
                esc_attr($token),
                esc_attr($description)
            );
        }
        echo '</div>';
    }

    /**
     * Rendered as a nonce'd link, never a nested <form>: the action buttons live
     * inside the settings form, and a nested form is dropped by the HTML parser,
     * which made its hidden `action` field override the save action (so "Save
     * changes" silently ran the last inline action instead).
     */
    private function actionButton(string $action, string $label): void
    {
        printf(
            '<a class="ebc-btn" href="%s">%s</a>',
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . $action), $action)),
            esc_html($label)
        );
    }

    /** @param array<string,mixed> $map */
    private function statusPills(array $map): string
    {
        $pills = [];
        $pills[] = Shell::pill(
            !empty($map['key_valid']) ? __('Connected', 'easybusy-connect') : __('Not connected', 'easybusy-connect'),
            !empty($map['key_valid']) ? 'ok' : 'error'
        );
        $pills[] = Shell::pill(
            Settings::isDryRun() ? __('Test mode', 'easybusy-connect') : __('Live', 'easybusy-connect'),
            Settings::isDryRun() ? 'warn' : 'ok'
        );
        $pills[] = Shell::pill('v' . EBC_VERSION, 'muted');

        return implode('', $pills);
    }

    /* ---------------- handlers ---------------- */

    public function handleSave(): void
    {
        $this->guard('ebc_save');

        $values = [
            'dry_run'              => !empty($_POST['dry_run']),
            'mode'                 => in_array($_POST['mode'] ?? '', ['auto', 'booking', 'lead'], true) ? (string) $_POST['mode'] : 'auto',
            'slot_horizon'         => max(1, min(365, (int) ($_POST['slot_horizon'] ?? 60))),
            'require_oib'          => !empty($_POST['require_oib']),
            'collect_address'      => !empty($_POST['collect_address']),
            'default_country'      => Countries::normalise((string) ($_POST['default_country'] ?? '')) ?: Countries::FALLBACK,
            'attachments'          => !empty($_POST['attachments']),
            'attachment_max_files' => max(1, min(10, (int) ($_POST['attachment_max_files'] ?? 3))),
            'attachment_max_mb'    => max(1, min(50, (int) ($_POST['attachment_max_mb'] ?? 10))),
            'consent_text'         => sanitize_textarea_field((string) ($_POST['consent_text'] ?? '')),
            'consent_url'          => esc_url_raw((string) ($_POST['consent_url'] ?? '')),
            'thank_you_page'       => max(0, (int) ($_POST['thank_you_page'] ?? 0)),
            'ff_forms'             => preg_replace('/[^0-9,\s]/', '', (string) ($_POST['ff_forms'] ?? '')) ?? '',
            'ui_locale'            => preg_replace('/[^a-zA-Z_]/', '', (string) ($_POST['ui_locale'] ?? '')) ?? '',
            'notify_emails'        => sanitize_text_field((string) ($_POST['notify_emails'] ?? '')),
            'notify_patient'       => !empty($_POST['notify_patient']),
            'email_from_name'      => sanitize_text_field((string) ($_POST['email_from_name'] ?? '')),
            'email_from_address'   => sanitize_email((string) ($_POST['email_from_address'] ?? '')),
            'notify_admin_subject' => sanitize_text_field((string) ($_POST['notify_admin_subject'] ?? '')),
            'notify_admin_body'    => sanitize_textarea_field((string) ($_POST['notify_admin_body'] ?? '')),
            'notify_patient_subject' => sanitize_text_field((string) ($_POST['notify_patient_subject'] ?? '')),
            'notify_patient_body'  => sanitize_textarea_field((string) ($_POST['notify_patient_body'] ?? '')),
            'store_mode'           => in_array((string) ($_POST['store_mode'] ?? ''), ['minimal', 'full', 'none'], true)
                ? (string) $_POST['store_mode']
                : 'minimal',
            'retention_days'       => max(0, min(3650, (int) ($_POST['retention_days'] ?? 90))),
            'delete_data_on_uninstall' => !empty($_POST['delete_data_on_uninstall']),
            'ttl_slots'            => max(15, min(900, (int) ($_POST['ttl_slots'] ?? 60))),
        ];

        $key = trim((string) ($_POST['api_key'] ?? ''));
        if ($key !== '') {
            $values['api_key'] = $key;
        }

        Settings::save($values);
        $this->catalog->flush();

        $this->done('saved', (string) ($_POST['tab'] ?? 'connection'));
    }

    public function handleProbe(): void
    {
        $this->guard('ebc_probe');
        $this->capabilities->probe();
        $this->done('probed', 'connection');
    }

    public function handlePurge(): void
    {
        $this->guard('ebc_purge');
        $this->catalog->flush();
        $this->slots->flush();
        delete_option(Capabilities::OPTION);
        $this->done('purged', 'advanced');
    }

    public function handleTestEmail(): void
    {
        $this->guard('ebc_test_email');
        $result = \EasyBusyConnect\Plugin::get(Notify::class)->sendTest();
        $this->done($result['sent'] > 0 ? 'mailed' : 'mail-failed', 'email');
    }

    private function guard(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'easybusy-connect'));
        }
        check_admin_referer($action);
    }

    private function done(string $flash, string $tab = 'connection'): void
    {
        wp_safe_redirect(Menu::url(Menu::SETTINGS_SLUG, [
            'tab'       => in_array($tab, self::TABS, true) ? $tab : 'connection',
            'ebc-flash' => $flash,
        ]));
        exit;
    }
}
