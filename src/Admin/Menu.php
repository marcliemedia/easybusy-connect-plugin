<?php

declare(strict_types=1);

namespace EasyBusyConnect\Admin;

use EasyBusyConnect\Api\Capabilities;
use EasyBusyConnect\Settings;
use EasyBusyConnect\Store\Submissions;

/**
 * Top-level "EasyBusy" menu — what an integration plugin is expected to look
 * like: one owner menu, Entries first (that is what staff open daily), Settings
 * second, plus a dashboard widget and Plugins-screen action links.
 */
final class Menu
{
    public const SLUG = 'easybusy';
    public const SETTINGS_SLUG = 'easybusy-settings';

    public function __construct(
        private EntriesPage $entries,
        private SettingsPage $settings,
        private Capabilities $capabilities
    ) {
    }

    public static function url(string $slug = self::SLUG, array $args = []): string
    {
        return add_query_arg(array_merge(['page' => $slug], $args), admin_url('admin.php'));
    }

    public function register(): void
    {
        add_menu_page(
            __('EasyBusy', 'easybusy-connect'),
            $this->menuLabel(),
            'manage_options',
            self::SLUG,
            [$this->entries, 'render'],
            'dashicons-calendar-alt',
            58
        );

        add_submenu_page(
            self::SLUG,
            __('Bookings & inquiries', 'easybusy-connect'),
            __('Entries', 'easybusy-connect'),
            'manage_options',
            self::SLUG,
            [$this->entries, 'render']
        );

        add_submenu_page(
            self::SLUG,
            __('EasyBusy settings', 'easybusy-connect'),
            __('Settings', 'easybusy-connect'),
            'manage_options',
            self::SETTINGS_SLUG,
            [$this->settings, 'render']
        );
    }

    /** Failed sends are the only thing worth a bubble: they need a human. */
    private function menuLabel(): string
    {
        $failed = (int) (Submissions::counts()['failed'] ?? 0);
        $label = __('EasyBusy', 'easybusy-connect');

        if ($failed > 0) {
            $label .= sprintf(
                ' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
                $failed
            );
        }

        return $label;
    }

    /**
     * Admin assets load only on this plugin's screens — the modern shell must
     * not restyle the rest of wp-admin.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if (!str_contains($hookSuffix, self::SLUG)) {
            return;
        }

        $base = plugins_url('assets', EBC_FILE);
        wp_enqueue_style('easybusy-connect-admin', $base . '/css/admin.css', [], EBC_VERSION);
        wp_enqueue_script('easybusy-connect-admin', $base . '/js/admin.js', [], EBC_VERSION, true);
    }

    /** @param array<int,string> $links @return array<int,string> */
    public function actionLinks(array $links): array
    {
        return array_merge([
            sprintf('<a href="%s">%s</a>', esc_url(self::url(self::SETTINGS_SLUG)), esc_html__('Settings', 'easybusy-connect')),
            sprintf('<a href="%s">%s</a>', esc_url(self::url()), esc_html__('Entries', 'easybusy-connect')),
        ], $links);
    }

    public function registerDashboardWidget(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget('ebc_dashboard', __('EasyBusy', 'easybusy-connect'), [$this, 'renderDashboardWidget']);
    }

    public function renderDashboardWidget(): void
    {
        $map = $this->capabilities->get();
        $recent = Submissions::query(['per_page' => 5]);
        $counts = Submissions::counts();

        $state = empty($map['vendor_up'])
            ? __('EasyBusy unreachable', 'easybusy-connect')
            : (empty($map['key_valid'])
                ? __('API key rejected', 'easybusy-connect')
                : __('Connected', 'easybusy-connect'));

        printf(
            '<p><strong>%s</strong>%s</p>',
            esc_html($state),
            Settings::isDryRun()
                ? ' — <em>' . esc_html__('test mode, nothing is sent', 'easybusy-connect') . '</em>'
                : ''
        );

        printf(
            '<p>%s</p>',
            esc_html(sprintf(
                /* translators: 1: total entries, 2: failed entries */
                __('%1$d entries recorded, %2$d failed.', 'easybusy-connect'),
                array_sum($counts),
                (int) ($counts['failed'] ?? 0)
            ))
        );

        if ($recent['rows'] === []) {
            printf('<p>%s</p>', esc_html__('No submissions yet.', 'easybusy-connect'));
        } else {
            echo '<ul style="margin:0 0 10px">';
            foreach ($recent['rows'] as $row) {
                // Whatever the storage level kept: a name, initials, or nothing.
                $who = (string) $row['patient_name'] !== ''
                    ? (string) $row['patient_name']
                    : (string) ($row['patient_initials'] ?? '');
                printf(
                    '<li>%s — %s <code>%s</code>%s</li>',
                    esc_html((string) $row['created_at']),
                    esc_html((string) ($row['service_name'] ?: $row['type'])),
                    esc_html((string) $row['status']),
                    $who !== '' ? ' · ' . esc_html($who) : ''
                );
            }
            echo '</ul>';
        }

        printf(
            '<p><a class="button" href="%s">%s</a> <a class="button" href="%s">%s</a></p>',
            esc_url(self::url()),
            esc_html__('All entries', 'easybusy-connect'),
            esc_url(self::url(self::SETTINGS_SLUG)),
            esc_html__('Settings', 'easybusy-connect')
        );
    }
}
