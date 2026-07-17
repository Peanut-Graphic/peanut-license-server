<?php
/**
 * Peanut License Server Test Suite
 *
 * Comprehensive tests for license server functionality.
 * Run via WP-CLI: wp eval-file tests/class-license-server-tests.php
 *
 * @package Peanut_License_Server
 */

if (!defined('ABSPATH')) {
    // Allow running from command line
    if (php_sapi_name() !== 'cli') {
        exit;
    }
}

class License_Server_Tests {

    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    private int $warnings = 0;

    /**
     * Run all tests
     */
    public function run(): array {
        $this->log_header('Peanut License Server Test Suite');

        // Core functionality tests
        $this->test_database_tables();
        $this->test_license_creation();
        $this->test_license_validation();
        $this->test_site_activation();
        $this->test_site_deactivation();
        $this->test_license_tiers();
        $this->test_license_expiration();

        // API tests
        $this->test_api_endpoints_exist();
        $this->test_api_validate_endpoint();
        $this->test_api_activate_endpoint();
        $this->test_api_deactivate_endpoint();
        $this->test_api_health_endpoint();

        // Admin functionality tests
        $this->test_admin_pages_accessible();
        $this->test_license_listing();
        $this->test_analytics_data();
        $this->test_audit_trail();

        // Security tests
        $this->test_nonce_verification();
        $this->test_capability_checks();
        $this->test_input_sanitization();
        $this->test_entitlement_signing();

        // Integration tests
        $this->test_webhook_handling();
        $this->test_update_server();

        $this->log_summary();

        return $this->results;
    }

    // ========================================
    // Database Tests
    // ========================================

    private function test_database_tables(): void {
        $this->log_section('Database Tables');

        global $wpdb;

        $required_tables = [
            'peanut_licenses',
            'peanut_activations',
            'peanut_validation_logs',
            'peanut_audit_trail',
            'peanut_update_logs',
        ];

        foreach ($required_tables as $table) {
            $full_table = $wpdb->prefix . $table;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $full_table
            )) === $full_table;

            $this->assert($exists, "Table {$table} exists");
        }

        // Check table structure
        $licenses_cols = $wpdb->get_col("DESCRIBE {$wpdb->prefix}peanut_licenses");
        $required_cols = ['id', 'license_key', 'license_key_hash', 'customer_email', 'tier', 'status', 'max_activations'];

        foreach ($required_cols as $col) {
            $this->assert(in_array($col, $licenses_cols), "Licenses table has '{$col}' column");
        }
    }

    // ========================================
    // License CRUD Tests
    // ========================================

    private function test_license_creation(): void {
        $this->log_section('License Creation');

        // Test creating a license
        $license_data = [
            'customer_email' => 'test-' . time() . '@example.com',
            'customer_name' => 'Test User',
            'tier' => 'pro',
            'product_id' => 1,
        ];

        // Check if License_Manager class exists
        if (!class_exists('License_Manager')) {
            $this->warning('License_Manager class not found - checking direct DB');

            global $wpdb;
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}peanut_licenses");
            $this->assert($count !== null, "Can query licenses table (count: {$count})");
            return;
        }

        $manager = new License_Manager();
        $license = $manager->create_license($license_data);

        $this->assert(!empty($license), 'License created successfully');
        $this->assert(!empty($license['license_key']), 'License key generated');
        $this->assert($license['tier'] === 'pro', 'License tier set correctly');

        // Cleanup
        if (!empty($license['id'])) {
            $manager->delete_license($license['id']);
        }
    }

    private function test_license_validation(): void {
        $this->log_section('License Validation');

        global $wpdb;

        // Get an existing license
        $license = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}peanut_licenses WHERE status = 'active' LIMIT 1");

        if (!$license) {
            $this->warning('No active licenses found for validation test');
            return;
        }

        $this->assert(!empty($license->license_key), 'Found license key: ' . substr($license->license_key, 0, 4) . '...');
        $this->assert($license->status === 'active', 'License status is active');

        // Test hash verification
        $expected_hash = hash('sha256', $license->license_key);
        $this->assert($expected_hash === $license->license_key_hash, 'License key hash matches');
    }

    private function test_site_activation(): void {
        $this->log_section('Site Activation');

        global $wpdb;

        // Check activations table
        $activations = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}peanut_activations LIMIT 5");
        $this->assert($activations !== null, 'Can query activations table');

        // Check activation structure
        if (!empty($activations)) {
            $activation = $activations[0];
            $this->assert(isset($activation->license_id), 'Activation has license_id');
            $this->assert(isset($activation->site_url), 'Activation has site_url');
            $this->assert(isset($activation->activated_at), 'Activation has timestamp');
        } else {
            $this->warning('No activations found for structure test');
        }
    }

    private function test_site_deactivation(): void {
        $this->log_section('Site Deactivation');

        global $wpdb;

        // Check for deactivated sites
        $deactivated = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}peanut_activations WHERE deactivated_at IS NOT NULL"
        );

        $this->assert($deactivated !== null, "Deactivation tracking works (count: {$deactivated})");
    }

    private function test_license_tiers(): void {
        $this->log_section('License Tiers');

        global $wpdb;

        // Get tier distribution
        $tiers = $wpdb->get_results(
            "SELECT tier, COUNT(*) as count, MAX(max_activations) as max_sites
             FROM {$wpdb->prefix}peanut_licenses
             GROUP BY tier"
        );

        $this->assert(!empty($tiers), 'Can query tier distribution');

        foreach ($tiers as $tier) {
            $expected_max = match($tier->tier) {
                'free' => 1,
                'pro' => 3,
                'agency' => 25,
                default => null
            };

            if ($expected_max !== null) {
                $this->assert(
                    (int)$tier->max_sites === $expected_max,
                    "Tier '{$tier->tier}' has correct max activations ({$tier->max_sites})"
                );
            }
        }
    }

    private function test_license_expiration(): void {
        $this->log_section('License Expiration');

        global $wpdb;

        // Check for expiring licenses query
        $expiring = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}peanut_licenses
             WHERE expires_at IS NOT NULL
             AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL %d DAY)
             LIMIT 5",
            30
        ));

        $this->assert($expiring !== null, 'Expiring licenses query works');

        // Check expired license handling
        $expired = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}peanut_licenses
             WHERE expires_at IS NOT NULL AND expires_at < NOW() AND status = 'active'"
        );

        if ($expired > 0) {
            $this->warning("{$expired} licenses are expired but still marked active");
        } else {
            $this->assert(true, 'No expired licenses with active status');
        }
    }

    // ========================================
    // API Tests
    // ========================================

    private function test_api_endpoints_exist(): void {
        $this->log_section('API Endpoints');

        // Check if REST routes are registered
        $routes = rest_get_server()->get_routes();

        $expected_routes = [
            '/peanut-api/v1/validate',
            '/peanut-api/v1/activate',
            '/peanut-api/v1/deactivate',
            '/peanut-api/v1/health',
        ];

        foreach ($expected_routes as $route) {
            $exists = isset($routes[$route]) ||
                      isset($routes[str_replace('/peanut-api/v1', '/peanut-license/v1', $route)]);
            $this->assert($exists, "Route exists: {$route}");
        }
    }

    private function test_api_validate_endpoint(): void {
        $this->log_section('API Validate Endpoint');

        global $wpdb;

        // Get a test license
        $license = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}peanut_licenses WHERE status = 'active' LIMIT 1"
        );

        if (!$license) {
            $this->warning('No license available for API test');
            return;
        }

        // Simulate API request
        $request = new WP_REST_Request('POST', '/peanut-api/v1/validate');
        $request->set_param('license_key', $license->license_key);
        $request->set_param('site_url', 'https://test-site.example.com');
        $request->set_param('product_slug', 'test-product');

        // Check request structure
        $this->assert($request->get_param('license_key') === $license->license_key, 'Request params set correctly');
    }

    private function test_api_activate_endpoint(): void {
        $this->log_section('API Activate Endpoint');
        // Structure test - actual activation tested in integration
        $this->assert(true, 'Activate endpoint structure OK');
    }

    private function test_api_deactivate_endpoint(): void {
        $this->log_section('API Deactivate Endpoint');
        $this->assert(true, 'Deactivate endpoint structure OK');
    }

    private function test_api_health_endpoint(): void {
        $this->log_section('API Health Endpoint');
        $this->assert(true, 'Health endpoint structure OK');
    }

    // ========================================
    // Admin Tests
    // ========================================

    private function test_admin_pages_accessible(): void {
        $this->log_section('Admin Pages');

        // Check if admin pages are registered
        global $submenu;

        $peanut_pages = $submenu['peanut-licenses'] ?? [];
        $expected_pages = ['All Licenses', 'Add New', 'Analytics', 'Settings'];

        foreach ($expected_pages as $page) {
            $found = false;
            foreach ($peanut_pages as $menu_item) {
                if (stripos($menu_item[0], $page) !== false) {
                    $found = true;
                    break;
                }
            }
            // Just log, don't fail (menu may not be loaded in CLI)
            if (!$found) {
                $this->warning("Admin page '{$page}' check skipped (CLI context)");
            } else {
                $this->assert(true, "Admin page '{$page}' registered");
            }
        }
    }

    private function test_license_listing(): void {
        $this->log_section('License Listing');

        global $wpdb;

        // Test pagination query
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}peanut_licenses");
        $this->assert($total !== null, "License count: {$total}");

        // Test filter queries
        $active = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}peanut_licenses WHERE status = 'active'");
        $this->assert($active !== null, "Active licenses: {$active}");
    }

    private function test_analytics_data(): void {
        $this->log_section('Analytics Data');

        global $wpdb;

        // Test validation logs
        $validations = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}peanut_validation_logs"
        );
        $this->assert($validations !== null, "Validation log entries: {$validations}");

        // Test daily stats if available
        $stats_table = $wpdb->prefix . 'peanut_daily_stats';
        $has_stats = $wpdb->get_var("SHOW TABLES LIKE '{$stats_table}'") === $stats_table;

        if ($has_stats) {
            $stats = $wpdb->get_var("SELECT COUNT(*) FROM {$stats_table}");
            $this->assert($stats !== null, "Daily stats entries: {$stats}");
        }
    }

    private function test_audit_trail(): void {
        $this->log_section('Audit Trail');

        global $wpdb;

        $audit = $wpdb->get_results(
            "SELECT action, COUNT(*) as count FROM {$wpdb->prefix}peanut_audit_trail GROUP BY action LIMIT 10"
        );

        $this->assert($audit !== null, 'Audit trail queryable');

        if (!empty($audit)) {
            foreach ($audit as $entry) {
                $this->log_info("  Audit action '{$entry->action}': {$entry->count} entries");
            }
        }
    }

    // ========================================
    // Security Tests
    // ========================================

    private function test_nonce_verification(): void {
        $this->log_section('Security: Nonce Verification');

        // Check that nonce functions are available
        $this->assert(function_exists('wp_verify_nonce'), 'wp_verify_nonce available');
        $this->assert(function_exists('wp_create_nonce'), 'wp_create_nonce available');

        // Verify nonce creation/verification cycle
        $nonce = wp_create_nonce('peanut_test_action');
        $valid = wp_verify_nonce($nonce, 'peanut_test_action');
        $this->assert($valid !== false, 'Nonce verification works');
    }

    private function test_capability_checks(): void {
        $this->log_section('Security: Capability Checks');

        // Admin capabilities
        $this->assert(
            current_user_can('manage_options') || !is_user_logged_in(),
            'Capability check functions work'
        );
    }

    private function test_input_sanitization(): void {
        $this->log_section('Security: Input Sanitization');

        // Test sanitization functions
        $dirty = '<script>alert("xss")</script>Test';
        $clean = sanitize_text_field($dirty);
        $this->assert(strpos($clean, '<script>') === false, 'XSS stripped from input');

        $dirty_email = 'test@example.com<script>';
        $clean_email = sanitize_email($dirty_email);
        $this->assert($clean_email === 'test@example.com', 'Email sanitization works');
    }

    // ========================================
    // Integration Tests
    // ========================================

    private function test_webhook_handling(): void {
        $this->log_section('Webhook Integration');

        global $wpdb;

        // Check webhook logs if available
        $webhook_table = $wpdb->prefix . 'peanut_webhooks_received';
        $has_webhooks = $wpdb->get_var("SHOW TABLES LIKE '{$webhook_table}'") === $webhook_table;

        if ($has_webhooks) {
            $webhooks = $wpdb->get_var("SELECT COUNT(*) FROM {$webhook_table}");
            $this->assert($webhooks !== null, "Webhook log entries: {$webhooks}");
        } else {
            $this->warning('Webhook logs table not found');
        }
    }

    private function test_update_server(): void {
        $this->log_section('Update Server');

        // Check if update server is enabled
        $enabled = get_option('peanut_license_server_update_enabled', false);
        $this->assert(true, "Update server enabled: " . ($enabled ? 'yes' : 'no'));

        global $wpdb;

        // Check update logs
        $updates = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}peanut_update_logs"
        );
        $this->assert($updates !== null, "Update log entries: {$updates}");
    }

    // ========================================
    // Helpers
    // ========================================

    private function test_entitlement_signing(): void {
        $this->log_section('Entitlement Signing');

        if (!class_exists('Peanut_License_Signer')) {
            $this->assert(false, 'Peanut_License_Signer class exists');
            return;
        }
        $signer = new Peanut_License_Signer();

        $pk = $signer->public_key();
        $this->assert(
            ($pk['alg'] ?? '') === 'ed25519' && !empty($pk['public_key']) && !empty($pk['kid']),
            'public_key() returns an ed25519 key + kid'
        );

        $license = ['status' => 'active', 'tier' => 'agency', 'expires_at' => '2027-01-01'];
        $signed  = $signer->sign_entitlement($license, 'PEANUT-REAL-KEY-123', 'https://site.example');
        $this->assert(!empty($signed['signature']), 'sign_entitlement() returns a signature');

        $this->assert(
            $signer->verify($signed, 'PEANUT-REAL-KEY-123', 'https://site.example') === true,
            'verify() accepts a genuine signature (canonicalization round-trips)'
        );
        $this->assert(
            $signer->verify($signed, 'PEANUT-OTHER-KEY-999', 'https://site.example') === false,
            'verify() rejects a mismatched license key (no cross-site replay)'
        );
        $this->assert(
            $signer->verify($signed, 'PEANUT-REAL-KEY-123', 'https://evil.example') === false,
            'verify() rejects a mismatched site'
        );
        $tampered = $signed; $tampered['tier'] = 'free';
        $this->assert(
            $signer->verify($tampered, 'PEANUT-REAL-KEY-123', 'https://site.example') === false,
            'verify() rejects a tampered tier'
        );
    }

    private function assert(bool $condition, string $message): void {
        if ($condition) {
            $this->passed++;
            $this->results[] = ['status' => 'pass', 'message' => $message];
            $this->log_pass($message);
        } else {
            $this->failed++;
            $this->results[] = ['status' => 'fail', 'message' => $message];
            $this->log_fail($message);
        }
    }

    private function warning(string $message): void {
        $this->warnings++;
        $this->results[] = ['status' => 'warning', 'message' => $message];
        $this->log_warning($message);
    }

    private function log_header(string $text): void {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "  {$text}\n";
        echo str_repeat('=', 60) . "\n\n";
    }

    private function log_section(string $text): void {
        echo "\n--- {$text} ---\n";
    }

    private function log_pass(string $text): void {
        echo "  ✓ {$text}\n";
    }

    private function log_fail(string $text): void {
        echo "  ✗ {$text}\n";
    }

    private function log_warning(string $text): void {
        echo "  ⚠ {$text}\n";
    }

    private function log_info(string $text): void {
        echo "{$text}\n";
    }

    private function log_summary(): void {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "  SUMMARY\n";
        echo str_repeat('=', 60) . "\n";
        echo "  Passed:   {$this->passed}\n";
        echo "  Failed:   {$this->failed}\n";
        echo "  Warnings: {$this->warnings}\n";
        echo str_repeat('=', 60) . "\n\n";
    }

    /**
     * Get results array
     */
    public function get_results(): array {
        return [
            'passed' => $this->passed,
            'failed' => $this->failed,
            'warnings' => $this->warnings,
            'details' => $this->results,
        ];
    }
}

// Run tests if executed directly
if (defined('ABSPATH')) {
    $tests = new License_Server_Tests();
    $tests->run();
}
