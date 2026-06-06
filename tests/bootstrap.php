<?php
/**
 * PHPUnit Bootstrap for Peanut License Server
 *
 * Sets up the test environment with WordPress function mocks.
 *
 * @package Peanut_License_Server\Tests
 */

// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Initialize Brain Monkey
Brain\Monkey\setUp();

// Define WordPress constants if not defined
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

// WordPress time constant used by the download-token helpers.
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// Stand-in for the WordPress AUTH_KEY salt so peanut_get_download_secret()
// returns a stable, non-empty signing secret during tests.
if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', 'peanut-license-server-test-auth-key');
}

// Minimal WordPress class/function stubs (WP_Error, WP_REST_Request, is_wp_error)
// required by the API security tests. ABSPATH is defined above so the guard in
// the loaded files passes.
require_once __DIR__ . '/wp-stubs.php';

// Load the pure download-token helpers in isolation (without booting the full
// plugin) so DownloadSecurityTest can call them directly.
require_once dirname(__DIR__) . '/includes/download-token-functions.php';

// Minimal $wpdb mock for code paths that touch the database (e.g.
// Peanut_API_Security::get_statistics() counting blocked-IP transients).
global $wpdb;
$wpdb = new class {
    public string $options = 'wp_options';

    public function prepare(string $query, ...$args): string {
        return $query;
    }

    public function get_var($query) {
        return 0;
    }
};

// Mock essential WordPress functions using Brain Monkey
function peanut_mock_wordpress_functions(): void {
    // Translation functions
    Brain\Monkey\Functions\stubs([
        '__' => function ($text, $domain = 'default') {
            return $text;
        },
        '_e' => function ($text, $domain = 'default') {
            echo $text;
        },
        'esc_html__' => function ($text, $domain = 'default') {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        },
        'esc_attr__' => function ($text, $domain = 'default') {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        },
    ]);

    // Sanitization functions
    Brain\Monkey\Functions\stubs([
        'sanitize_text_field' => function ($str) {
            return trim(strip_tags($str));
        },
        'sanitize_email' => function ($email) {
            $email = trim($email);
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        },
        'sanitize_title' => function ($title) {
            return strtolower(preg_replace('/[^a-z0-9-]/', '', str_replace(' ', '-', $title)));
        },
        'sanitize_key' => function ($key) {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
        },
        'wp_kses_post' => function ($data) {
            return strip_tags($data, '<p><br><a><strong><em><ul><ol><li><h1><h2><h3><h4><h5><h6>');
        },
    ]);

    // URL functions
    Brain\Monkey\Functions\stubs([
        'esc_url' => function ($url) {
            return filter_var($url, FILTER_SANITIZE_URL);
        },
        'esc_url_raw' => function ($url) {
            return filter_var($url, FILTER_SANITIZE_URL);
        },
        'untrailingslashit' => function ($string) {
            return rtrim($string, '/\\');
        },
        'trailingslashit' => function ($string) {
            return rtrim($string, '/\\') . '/';
        },
    ]);

    // Option functions
    Brain\Monkey\Functions\stubs([
        'get_option' => function ($option, $default = false) {
            global $test_options;
            return $test_options[$option] ?? $default;
        },
        'update_option' => function ($option, $value) {
            global $test_options;
            $test_options[$option] = $value;
            return true;
        },
        'delete_option' => function ($option) {
            global $test_options;
            unset($test_options[$option]);
            return true;
        },
    ]);

    // Transient functions
    Brain\Monkey\Functions\stubs([
        'get_transient' => function ($transient) {
            global $test_transients;
            $data = $test_transients[$transient] ?? null;
            if ($data && isset($data['expires']) && $data['expires'] < time()) {
                unset($test_transients[$transient]);
                return false;
            }
            return $data['value'] ?? false;
        },
        'set_transient' => function ($transient, $value, $expiration = 0) {
            global $test_transients;
            $test_transients[$transient] = [
                'value' => $value,
                'expires' => $expiration > 0 ? time() + $expiration : 0,
            ];
            return true;
        },
        'delete_transient' => function ($transient) {
            global $test_transients;
            unset($test_transients[$transient]);
            return true;
        },
    ]);

    // User functions
    Brain\Monkey\Functions\stubs([
        'current_user_can' => function ($capability) {
            global $test_user_capabilities;
            return $test_user_capabilities[$capability] ?? false;
        },
        'is_user_logged_in' => function () {
            global $test_user_logged_in;
            return $test_user_logged_in ?? false;
        },
        'get_current_user_id' => function () {
            global $test_current_user_id;
            return $test_current_user_id ?? 0;
        },
    ]);

    // Nonce functions
    Brain\Monkey\Functions\stubs([
        'wp_create_nonce' => function ($action) {
            return md5($action . 'test_salt');
        },
        'wp_verify_nonce' => function ($nonce, $action) {
            return $nonce === md5($action . 'test_salt') ? 1 : false;
        },
        'check_ajax_referer' => function ($action, $query_arg = false, $die = true) {
            return true;
        },
    ]);

    // Date/Time functions
    Brain\Monkey\Functions\stubs([
        'current_time' => function ($type, $gmt = 0) {
            if ($type === 'mysql') {
                return date('Y-m-d H:i:s');
            }
            return time();
        },
        'date_i18n' => function ($format, $timestamp = null) {
            return date($format, $timestamp ?? time());
        },
    ]);

    // JSON functions
    Brain\Monkey\Functions\stubs([
        'wp_json_encode' => function ($data, $options = 0, $depth = 512) {
            return json_encode($data, $options, $depth);
        },
    ]);

    // HTTP API functions — there is no real network in unit tests, so remote
    // services (e.g. the optional ML abuse detector) are reported unavailable.
    // Callers are written to gracefully degrade in that case, so this exercises
    // the real degradation path rather than mocking it away.
    Brain\Monkey\Functions\stubs([
        'wp_remote_get' => function ($url = '', $args = []) {
            return new \WP_Error('http_request_failed', 'No HTTP requests in unit tests.');
        },
        'wp_remote_post' => function ($url = '', $args = []) {
            return new \WP_Error('http_request_failed', 'No HTTP requests in unit tests.');
        },
        'wp_remote_retrieve_response_code' => function ($response) {
            return 0;
        },
        'wp_remote_retrieve_body' => function ($response) {
            return '';
        },
    ]);

    // Misc functions
    Brain\Monkey\Functions\stubs([
        'wp_generate_uuid4' => function () {
            return sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
        },
        'wp_hash' => function ($data, $scheme = 'auth') {
            return hash('sha256', $data . 'test_salt');
        },
        'absint' => function ($val) {
            return abs((int) $val);
        },
    ]);
}

// Initialize the mocks
peanut_mock_wordpress_functions();

// Global test state
$test_options = [];
$test_transients = [];
$test_user_capabilities = ['manage_options' => true];
$test_user_logged_in = true;
$test_current_user_id = 1;

// Cleanup after each test class
register_shutdown_function(function () {
    Brain\Monkey\tearDown();
});
