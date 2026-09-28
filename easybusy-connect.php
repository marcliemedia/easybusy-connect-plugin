<?php
/**
 * Plugin Name:       EasyBusy Connect (by Marclie)
 * Description:       Multi-step appointment booking and lead capture synced to the EasyBusy clinic system over its B2B REST API. Built by Marclie; not affiliated with or endorsed by EasyBusy.
 * Version:           0.12.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Marclie
 * Author URI:        https://github.com/phu-dev-marclie
 * Plugin URI:        https://github.com/phu-dev-marclie/easybusy-connect-plugin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       easybusy-connect
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH') && !defined('EBC_HARNESS')) {
    exit;
}

define('EBC_VERSION', '0.12.4');
define('EBC_FILE', __FILE__);
define('EBC_DIR', __DIR__);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'EasyBusyConnect\\')) {
        return;
    }
    $relative = substr($class, strlen('EasyBusyConnect\\'));
    $path = EBC_DIR . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require $path;
    }
});

if (!defined('EBC_HARNESS')) {
    add_action('plugins_loaded', [EasyBusyConnect\Plugin::class, 'boot']);
    register_activation_hook(__FILE__, [EasyBusyConnect\Plugin::class, 'activate']);
    register_deactivation_hook(__FILE__, [EasyBusyConnect\Plugin::class, 'deactivate']);
}
