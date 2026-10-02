<?php
/**
 * WP-CLI Commands for Peanut License Server
 *
 * Run diagnostics and tests from the command line.
 *
 * @package Peanut_License_Server
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_CLI')) {
    return;
}

/**
 * Peanut License Server CLI Commands
 */
class Peanut_License_CLI {

    /**
     * Run all diagnostic tests
     *
     * ## EXAMPLES
     *
     *     wp peanut-license test
     *
     * @when after_wp_load
     */
    public function test($args, $assoc_args) {
        WP_CLI::log('');
        WP_CLI::log('=== Peanut License Server - Diagnostic Tests ===');
        WP_CLI::log('');

        $passed = 0;
        $failed = 0;
        $warnings = 0;

        // Test 1: Database tables exist
        WP_CLI::log('Testing database tables...');
        $table_results = $this->test_database_tables();
        foreach ($table_results as $table => $exists) {
            if ($exists) {
                WP_CLI::success("Table {$table} exists");
                $passed++;
            } else {
                WP_CLI::error("Table {$table} missing", false);
                $failed++;
            }
        }
        WP_CLI::log('');

        // Test 2: Required columns
        WP_CLI::log('Testing table columns...');
        $column_results = $this->test_table_columns();
        foreach ($column_results as $result) {
            if ($result['status'] === 'pass') {
                WP_CLI::success($result['message']);
                $passed++;
            } elseif ($result['status'] === 'warning') {
                WP_CLI::warning($result['message']);
                $warnings++;
            } else {
                WP_CLI::error($result['message'], false);
                $failed++;
            }
        }
        WP_CLI::log('');

        // Test 3: REST API endpoints
        WP_CLI::log('Testing REST API endpoints...');
        $api_results = $this->test_api_endpoints();
        foreach ($api_results as $result) {
            if ($result['status'] === 'pass') {
                WP_CLI::success($result['message']);
                $passed++;
            } else {
                WP_CLI::error($result['message'], false);
                $failed++;
            }
        }
        WP_CLI::log('');

        // Test 4: License operations
        WP_CLI::log('Testing license operations...');
        $license_results = $this->test_license_operations();
        foreach ($license_results as $result) {
            if ($result['status'] === 'pass') {
                WP_CLI::success($result['message']);
                $passed++;
            } elseif ($result['status'] === 'warning') {
                WP_CLI::warning($result['message']);
                $warnings++;
            } else {
                WP_CLI::error($result['message'], false);
                $failed++;
            }
        }
        WP_CLI::log('');

        // Summary
        WP_CLI::log('=== Test Summary ===');
        WP_CLI::log("Passed: {$passed}");
        WP_CLI::log("Warnings: {$warnings}");
        WP_CLI::log("Failed: {$failed}");

        if ($failed > 0) {
            WP_CLI::error('Some tests failed', false);
        } elseif ($warnings > 0) {
            WP_CLI::warning('Tests passed with warnings');
        } else {
            WP_CLI::success('All tests passed!');
        }
    }

    /**
     * Show license statistics
     *
     * ## EXAMPLES
     *
     *     wp peanut-license stats
     *
     * @when after_wp_load
     */
    public function stats($args, $assoc_args) {
        global $wpdb;

        $licenses_table = $wpdb->prefix . 'peanut_licenses';
        $activations_table = $wpdb->prefix . 'peanut_activations';

        $total_licenses = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$licenses_table}");
        $active_licenses = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$licenses_table} WHERE status = 'active'");
        $total_activations = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$activations_table} WHERE is_active = 1");

        $tier_breakdown = $wpdb->get_results(
            "SELECT tier, COUNT(*) as count FROM {$licenses_table} GROUP BY tier",
            ARRAY_A
        );

        WP_CLI::log('');
        WP_CLI::log('=== License Statistics ===');
        WP_CLI::log('');
        WP_CLI::log("Total Licenses: {$total_licenses}");
        WP_CLI::log("Active Licenses: {$active_licenses}");
        WP_CLI::log("Active Site Activations: {$total_activations}");
        WP_CLI::log('');
        WP_CLI::log('By Tier:');
        foreach ($tier_breakdown as $tier) {
            WP_CLI::log("  - {$tier['tier']}: {$tier['count']}");
        }
        WP_CLI::log('');
    }

    /**
     * Validate a specific license key
     *
     * ## OPTIONS
     *
     * <key>
     * : The license key to validate
     *
     * ## EXAMPLES
     *
     *     wp peanut-license validate PNUT-XXXX-XXXX-XXXX
     *
     * @when after_wp_load
     */
    public function validate($args, $assoc_args) {
        $key = $args[0];

        $license = Peanut_License_Manager::get_by_key($key);

        if (!$license) {
            WP_CLI::error("License key not found: {$key}");
            return;
        }

        WP_CLI::log('');
        WP_CLI::log('=== License Details ===');
        WP_CLI::log('');
        WP_CLI::log("Key: {$license->license_key}");
        WP_CLI::log("Email: {$license->email}");
        WP_CLI::log("Status: {$license->status}");
        WP_CLI::log("Tier: {$license->tier}");
        WP_CLI::log("Product: {$license->product_slug}");
        WP_CLI::log("Max Activations: {$license->max_activations}");
        WP_CLI::log("Created: {$license->created_at}");

        if ($license->expires_at) {
            WP_CLI::log("Expires: {$license->expires_at}");
        } else {
            WP_CLI::log("Expires: Never");
        }

        // Get activations
        $activations = Peanut_License_Manager::get_activations($license->id);
        $active_count = count(array_filter($activations, fn($a) => $a->is_active));

        WP_CLI::log('');
        WP_CLI::log("Active Sites: {$active_count} / {$license->max_activations}");

        if (!empty($activations)) {
            WP_CLI::log('');
            WP_CLI::log('Activated Sites:');
            foreach ($activations as $activation) {
                $status = $activation->is_active ? 'active' : 'inactive';
                WP_CLI::log("  - {$activation->site_url} ({$status})");
            }
        }

        WP_CLI::log('');
    }

    /**
     * Run database migration
     *
     * ## EXAMPLES
     *
     *     wp peanut-license migrate
     *
     * @when after_wp_load
     */
    public function migrate($args, $assoc_args) {
        global $peanut_license_server;

        if ($peanut_license_server) {
            WP_CLI::log('Running database migrations...');
            $peanut_license_server->check_db_migrations();
            WP_CLI::success('Migrations complete');
        } else {
            WP_CLI::error('License server not initialized');
        }
    }

    /**
     * Test database tables exist
     */
    private function test_database_tables(): array {
        global $wpdb;

        $tables = [
            'peanut_licenses',
            'peanut_activations',
            'peanut_update_logs',
            'peanut_validation_logs',
            'peanut_webhook_logs',
            'peanut_audit_trail',
        ];

        $results = [];
        foreach ($tables as $table) {
            $full_name = $wpdb->prefix . $table;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $full_name)) === $full_name;
            $results[$table] = $exists;
        }

        return $results;
    }

    /**
     * Test table columns
     */
    private function test_table_columns(): array {
        global $wpdb;

        $results = [];

        // Test activations table columns
        $activations_table = $wpdb->prefix . 'peanut_activations';

        $required_columns = ['deactivated_at', 'health_status', 'health_errors', 'activated_at', 'last_checked'];

        foreach ($required_columns as $column) {
            $exists = $wpdb->get_results(
                $wpdb->prepare("SHOW COLUMNS FROM {$activations_table} LIKE %s", $column)
            );

            if (!empty($exists)) {
                $results[] = [
                    'status' => 'pass',
                    'message' => "Column {$column} exists in activations table",
                ];
            } else {
                $results[] = [
                    'status' => 'fail',
                    'message' => "Column {$column} missing from activations table",
                ];
            }
        }

        return $results;
    }

    /**
     * Test API endpoints
     */
    private function test_api_endpoints(): array {
        $results = [];

        $server = rest_get_server();
        $routes = $server->get_routes();

        $expected_routes = [
            '/peanut-api/v1/license/validate',
            '/peanut-api/v1/license/deactivate',
            '/peanut-api/v1/updates/check',
            '/peanut-api/v1/health',
        ];

        foreach ($expected_routes as $route) {
            $found = false;
            foreach (array_keys($routes) as $registered_route) {
                if (strpos($registered_route, $route) !== false) {
                    $found = true;
                    break;
                }
            }

            if ($found) {
                $results[] = [
                    'status' => 'pass',
                    'message' => "Route {$route} registered",
                ];
            } else {
                $results[] = [
                    'status' => 'fail',
                    'message' => "Route {$route} not found",
                ];
            }
        }

        return $results;
    }

    /**
     * Test license operations
     */
    private function test_license_operations(): array {
        $results = [];

        // Test license generation
        $key = Peanut_License_Manager::generate_license_key();
        if (preg_match('/^[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/', $key)) {
            $results[] = [
                'status' => 'pass',
                'message' => "License key generation works: {$key}",
            ];
        } else {
            $results[] = [
                'status' => 'fail',
                'message' => "Invalid license key format: {$key}",
            ];
        }

        // Test tier features
        $tier_features = Peanut_License_Manager::get_tier_features('pro');
        if (!empty($tier_features)) {
            $results[] = [
                'status' => 'pass',
                'message' => "Tier features configured correctly",
            ];
        } else {
            $results[] = [
                'status' => 'warning',
                'message' => "Tier features may not be configured",
            ];
        }

        return $results;
    }

    /**
     * Encrypt stored license keys and move lookup hashes to the keyed HMAC.
     *
     * Requires PEANUT_LICENSE_KEY_SECRET. Safe to re-run; converted rows are
     * skipped.
     *
     * ## EXAMPLES
     *
     *     wp peanut-license encrypt-keys
     *
     * @subcommand encrypt-keys
     * @when after_wp_load
     */
    public function encrypt_keys($args, $assoc_args) {
        $status = Peanut_License_Key_Vault::secret_status();
        if ($status === 'disabled') {
            WP_CLI::error('PEANUT_LICENSE_KEY_SECRET is not set (or not base64 of 32+ bytes).');
        }
        if ($status === 'mismatch') {
            WP_CLI::error('PEANUT_LICENSE_KEY_SECRET does not match the secret existing keys were encrypted with.');
        }
        if (!Peanut_License_DB_Migrations::license_key_column_ready()) {
            WP_CLI::error('license_key column is not widened yet; load any page so schema 1.8.0 runs, then retry.');
        }
        $total = 0;
        do {
            $done = Peanut_License_Key_Vault::sweep();
            $total += $done;
        } while ($done === Peanut_License_Key_Vault::SWEEP_BATCH);
        $left = Peanut_License_Key_Vault::remaining();
        if ($left > 0) {
            WP_CLI::warning("Encrypted {$total} keys; {$left} could not be converted (see logs).");
            return;
        }
        WP_CLI::success("Encrypted {$total} keys; none remain in plaintext.");
    }
}

// Register the CLI command
WP_CLI::add_command('peanut-license', 'Peanut_License_CLI');
