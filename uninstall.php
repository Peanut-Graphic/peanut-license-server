<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Peanut License Server Uninstall
 *
 * Fired when the plugin is uninstalled.
 *
 * @package Peanut_License_Server
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check user permissions
if (!current_user_can('activate_plugins')) {
    exit;
}

// Make sure we're uninstalling the right plugin
if (plugin_basename(__FILE__) !== 'peanut-license-server/uninstall.php') {
    exit;
}

global $wpdb;

// Option: Set to true to remove all data on uninstall
$remove_data = get_option('peanut_license_server_remove_data_on_uninstall', false);

if ($remove_data) {
    // Drop custom tables
    $tables = [
        $wpdb->prefix . 'peanut_licenses',
        $wpdb->prefix . 'peanut_activations',
        $wpdb->prefix . 'peanut_update_logs',
        $wpdb->prefix . 'peanut_validation_logs',
        $wpdb->prefix . 'peanut_webhook_logs',
        $wpdb->prefix . 'peanut_audit_trail',
        $wpdb->prefix . 'peanut_license_restrictions',
        $wpdb->prefix . 'peanut_gdpr_requests',
        $wpdb->prefix . 'peanut_bundles',
        $wpdb->prefix . 'peanut_bundle_products',
        $wpdb->prefix . 'peanut_license_bundles',
        $wpdb->prefix . 'peanut_affiliates',
        $wpdb->prefix . 'peanut_referrals',
        $wpdb->prefix . 'peanut_affiliate_payouts',
    ];

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

    // Delete options
    $options = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'peanut_%'"
    );

    foreach ($options as $option) {
        delete_option($option);
    }

    // Delete transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_peanut_%' OR option_name LIKE '_transient_timeout_peanut_%'"
    );
}

// Always clean up these options
delete_option('peanut_license_server_db_version');
delete_option('peanut_webhook_endpoints');

// Clear any scheduled events
wp_clear_scheduled_hook('peanut_license_cleanup');
wp_clear_scheduled_hook('peanut_process_expired_licenses');
wp_clear_scheduled_hook('peanut_license_key_sweep');
