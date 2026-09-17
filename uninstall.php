<?php
/**
 * Removes the plugin's own data. Runs only on delete, never on deactivate, and
 * only when the site opted in — a clinic that deletes the plugin by accident
 * should not lose its entry history.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('ebc_settings', []);
$purge = is_array($settings) && !empty($settings['delete_data_on_uninstall']);

delete_option('ebc_capabilities');
delete_option('ebc_log');
delete_option('ebc_booking_path');
delete_option('ebc_mail_error');
wp_clear_scheduled_hook('ebc_daily_maintenance');

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_ebc\_%' OR option_name LIKE '\_transient\_timeout\_ebc\_%'");

if ($purge) {
    delete_option('ebc_settings');
    delete_option('ebc_db_version');
    $table = $wpdb->prefix . 'ebc_submissions';
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}
