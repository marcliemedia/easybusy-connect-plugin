<?php

declare(strict_types=1);

namespace EasyBusyConnect;

use EasyBusyConnect\Admin\EntriesPage;
use EasyBusyConnect\Admin\Menu;
use EasyBusyConnect\Admin\SettingsPage;
use EasyBusyConnect\Api\Booking;
use EasyBusyConnect\Api\Capabilities;
use EasyBusyConnect\Api\Catalog;
use EasyBusyConnect\Api\Client;
use EasyBusyConnect\Api\Leads;
use EasyBusyConnect\Api\Slots;
use EasyBusyConnect\Cli\Commands;
use EasyBusyConnect\Form\Definition;
use EasyBusyConnect\Integrations\FluentForms;
use EasyBusyConnect\Form\Submit;
use EasyBusyConnect\Frontend\Assets;
use EasyBusyConnect\Frontend\BricksElement;
use EasyBusyConnect\Frontend\Shortcode;
use EasyBusyConnect\Frontend\ThankYou;
use EasyBusyConnect\Rest\Controller;
use EasyBusyConnect\Store\Submissions;
use EasyBusyConnect\Support\Uploads;

/**
 * Wiring. Objects are plain constructor-injected services; there is no container
 * framework because there are a dozen of them.
 */
final class Plugin
{
    /** @var array<string,object> */
    private static array $services = [];

    public static function boot(): void
    {
        Submissions::maybeUpgrade();
        self::loadTranslations();

        $rest = self::get(Controller::class);
        add_action('rest_api_init', [$rest, 'register']);

        add_shortcode('easybusy_booking', [self::get(Shortcode::class), 'render']);
        add_shortcode('easybusy_thank_you', [self::get(ThankYou::class), 'render']);

        add_action('init', [BricksElement::class, 'register']);

        self::get(FluentForms::class)->register();

        // Retention purge: health-adjacent contact data must not accumulate.
        add_action('ebc_daily_maintenance', [Submissions::class, 'purge']);
        add_action('ebc_daily_maintenance', static function (): void {
            Uploads::purgeOlderThan(24);
        });
        if (!wp_next_scheduled('ebc_daily_maintenance')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'ebc_daily_maintenance');
        }

        if (is_admin()) {
            $menu = self::get(Menu::class);
            add_action('admin_menu', [$menu, 'register']);
            add_action('wp_dashboard_setup', [$menu, 'registerDashboardWidget']);
            add_filter('plugin_action_links_' . plugin_basename(EBC_FILE), [$menu, 'actionLinks']);
            add_action('admin_enqueue_scripts', [$menu, 'enqueueAssets']);

            $settings = self::get(SettingsPage::class);
            add_action('admin_post_ebc_save', [$settings, 'handleSave']);
            add_action('admin_post_ebc_probe', [$settings, 'handleProbe']);
            add_action('admin_post_ebc_purge', [$settings, 'handlePurge']);
            add_action('admin_post_ebc_test_email', [$settings, 'handleTestEmail']);
            add_action('admin_notices', [$settings, 'renderNotices']);

            $entries = self::get(EntriesPage::class);
            add_action('admin_post_ebc_purge_entries', [$entries, 'handlePurge']);
            add_action('admin_post_ebc_export_entries', [$entries, 'handleExport']);
        }

        if (defined('WP_CLI') && constant('WP_CLI')) {
            Commands::register(self::get(Commands::class));
        }
    }

    /**
     * The site locale is en_US while the clinic and its patients are Croatian,
     * so the plugin's own locale follows the configured UI language.
     *
     * WordPress 7.1 loads plugin translations just in time using
     * determine_locale() and does **not** apply the `plugin_locale` filter on
     * that path, so the site's en_US wins and an hr .mo is never read. The file
     * is therefore loaded explicitly (verified: `load_textdomain()` with the
     * resolved path returns the Croatian strings). The filter is kept for code
     * that goes through the normal loader.
     */
    private static function loadTranslations(): void
    {
        $locale = static function (): string {
            $wanted = trim((string) Settings::get('ui_locale', ''));

            return $wanted !== '' ? $wanted : determine_locale();
        };

        add_filter('plugin_locale', static function (string $current, string $domain) use ($locale): string {
            return $domain === 'easybusy-connect' ? $locale() : $current;
        }, 10, 2);

        $load = static function () use ($locale): void {
            $mo = EBC_DIR . '/languages/easybusy-connect-' . $locale() . '.mo';
            if (is_readable($mo)) {
                // Registered under the *active* locale on purpose: WordPress 7.1
                // looks translations up by determine_locale() (en_US here), so a
                // file registered as "hr" is loaded but never consulted.
                load_textdomain('easybusy-connect', $mo, determine_locale());

                return;
            }
            load_plugin_textdomain('easybusy-connect', false, dirname(plugin_basename(EBC_FILE)) . '/languages');
        };

        // Loading before `init` triggers WordPress's early-textdomain warning.
        did_action('init') ? $load() : add_action('init', $load, 1);
    }

    public static function activate(): void
    {
        Submissions::install();

        // Probe once so the admin screen is meaningful immediately; a failure here
        // must not block activation.
        if (Settings::apiKey() !== '') {
            self::get(Capabilities::class)->probe();
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('ebc_daily_maintenance');
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public static function get(string $class): object
    {
        if (isset(self::$services[$class])) {
            /** @var T */
            return self::$services[$class];
        }

        $service = match ($class) {
            Client::class       => new Client(),
            Capabilities::class => new Capabilities(self::get(Client::class)),
            Catalog::class      => new Catalog(self::get(Client::class), self::get(Capabilities::class)),
            Slots::class        => new Slots(self::get(Client::class), self::get(Capabilities::class)),
            Booking::class      => new Booking(self::get(Client::class)),
            Leads::class        => new Leads(self::get(Client::class)),
            Definition::class   => new Definition(self::get(Capabilities::class)),
            Notify::class       => new Notify(),
            EntriesPage::class  => new EntriesPage(),
            FluentForms::class  => new FluentForms(self::get(Capabilities::class), self::get(Leads::class)),
            Menu::class         => new Menu(
                self::get(EntriesPage::class),
                self::get(SettingsPage::class),
                self::get(Capabilities::class)
            ),
            Submit::class       => new Submit(
                self::get(Capabilities::class),
                self::get(Definition::class),
                self::get(Catalog::class),
                self::get(Slots::class),
                self::get(Booking::class),
                self::get(Leads::class),
                self::get(Notify::class)
            ),
            Controller::class   => new Controller(
                self::get(Capabilities::class),
                self::get(Definition::class),
                self::get(Catalog::class),
                self::get(Slots::class),
                self::get(Submit::class)
            ),
            Assets::class       => new Assets(),
            Shortcode::class    => new Shortcode(self::get(Assets::class), self::get(Definition::class), self::get(Capabilities::class)),
            ThankYou::class     => new ThankYou(self::get(Assets::class)),
            SettingsPage::class => new SettingsPage(self::get(Capabilities::class), self::get(Catalog::class), self::get(Slots::class)),
            Commands::class     => new Commands(
                self::get(Capabilities::class),
                self::get(Catalog::class),
                self::get(Slots::class),
                self::get(Definition::class)
            ),
            default             => throw new \InvalidArgumentException('Unknown service ' . $class),
        };

        self::$services[$class] = $service;

        /** @var T */
        return $service;
    }
}
