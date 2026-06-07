<?php
/**
 * PHPUnit Bootstrap File
 *
 * Sets up WordPress mocks and loads plugin classes for unit testing.
 *
 * @package Peanut_License_Server
 */

// Define test environment
define('PEANUT_LICENSE_SERVER_TESTING', true);

// Plugin path constants
define('PEANUT_LICENSE_SERVER_PATH', dirname(dirname(__DIR__)) . '/');
define('PEANUT_LICENSE_SERVER_VERSION', '1.3.1');

// WordPress stub constants
define('ABSPATH', '/var/www/html/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);

/**
 * Mock WordPress $wpdb global
 */
class MockWPDB {
    public string $prefix = 'wp_';
    public ?string $last_error = null;
    public int $insert_id = 0;

    // Core table-name properties the real $wpdb exposes (prefix + table). Code
    // that interpolates "{$wpdb->options}" into a query needs these to exist.
    public string $options = 'wp_options';
    public string $posts = 'wp_posts';
    public string $users = 'wp_users';
    public string $usermeta = 'wp_usermeta';

    private array $tables = [];
    private array $data = [];

    public function prepare(string $query, ...$args): string {
        $query = str_replace('%s', "'%s'", $query);
        $query = str_replace('%d', '%s', $query);
        return vsprintf($query, $args);
    }

    public function get_var(?string $query = null) {
        return null;
    }

    public function get_row(?string $query = null, $output = OBJECT, $offset = 0) {
        return null;
    }

    public function get_results(?string $query = null, $output = OBJECT): array {
        return [];
    }

    public function get_col(?string $query = null, int $x = 0): array {
        return [];
    }

    public function insert(string $table, array $data, $format = null): bool {
        $this->insert_id = rand(1, 10000);
        return true;
    }

    public function update(string $table, array $data, array $where, $format = null, $where_format = null) {
        return 1;
    }

    public function delete(string $table, array $where, $format = null): int {
        return 1;
    }

    public function query(string $query) {
        return true;
    }

    public function esc_like(string $text): string {
        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
}

// Create global $wpdb
$GLOBALS['wpdb'] = new MockWPDB();

// Output type constants
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

/**
 * Mock WordPress WP_REST_Request class
 */
class WP_REST_Request {
    private array $params = [];
    private string $method;
    private string $route;

    public function __construct(string $method = 'GET', string $route = '') {
        $this->method = $method;
        $this->route = $route;
    }

    public function set_param(string $key, $value): void {
        $this->params[$key] = $value;
    }

    public function get_param(string $key) {
        return $this->params[$key] ?? null;
    }

    public function get_params(): array {
        return $this->params;
    }

    public function get_method(): string {
        return $this->method;
    }

    public function get_route(): string {
        return $this->route;
    }
}

/**
 * Mock WordPress WP_REST_Response class
 */
class WP_REST_Response {
    private $data;
    private int $status;
    private array $headers = [];

    public function __construct($data = null, int $status = 200, array $headers = []) {
        $this->data = $data;
        $this->status = $status;
        $this->headers = $headers;
    }

    public function get_data() {
        return $this->data;
    }

    public function get_status(): int {
        return $this->status;
    }

    public function header(string $key, string $value): void {
        $this->headers[$key] = $value;
    }

    public function get_headers(): array {
        return $this->headers;
    }
}

/**
 * Mock WordPress WP_REST_Server class
 */
class WP_REST_Server {
    public const READABLE = 'GET';
    public const CREATABLE = 'POST';
    public const EDITABLE = 'PUT, PATCH';
    public const DELETABLE = 'DELETE';
}

/**
 * Mock WordPress WP_Error class
 */
class WP_Error {
    private string $code;
    private string $message;
    private $data;

    public function __construct(string $code = '', string $message = '', $data = '') {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code(): string {
        return $this->code;
    }

    public function get_error_message(): string {
        return $this->message;
    }

    public function get_error_data() {
        return $this->data;
    }
}

/**
 * Mock WordPress functions
 */

// Escaping functions
function esc_html(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string {
    return filter_var($url, FILTER_SANITIZE_URL);
}

function esc_url_raw(string $url): string {
    return filter_var($url, FILTER_SANITIZE_URL);
}

// WordPress alias of esc_url_raw() for sanitizing a URL for storage/use.
function sanitize_url(string $url): string {
    return filter_var($url, FILTER_SANITIZE_URL);
}

// Sanitization functions
function sanitize_text_field(string $str): string {
    return trim(strip_tags($str));
}

function sanitize_email(string $email): string {
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function sanitize_file_name(string $filename): string {
    return preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
}

function sanitize_key(string $key): string {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function sanitize_sql_orderby(string $orderby): ?string {
    if (preg_match('/^\w+(\s+(ASC|DESC))?$/i', $orderby)) {
        return $orderby;
    }
    return null;
}

function absint($value): int {
    return abs((int) $value);
}

// URL functions
function untrailingslashit(string $string): string {
    return rtrim($string, '/\\');
}

function trailingslashit(string $string): string {
    return untrailingslashit($string) . '/';
}

function home_url(string $path = ''): string {
    return 'https://example.com' . $path;
}

function admin_url(string $path = ''): string {
    return 'https://example.com/wp-admin/' . $path;
}

function rest_url(string $path = ''): string {
    return 'https://example.com/wp-json/' . $path;
}

function plugins_url(string $path = '', string $plugin = ''): string {
    return 'https://example.com/wp-content/plugins/' . $path;
}

function plugin_dir_path(string $file): string {
    return dirname($file) . '/';
}

function plugin_dir_url(string $file): string {
    return 'https://example.com/wp-content/plugins/' . basename(dirname($file)) . '/';
}

// add_query_arg( array $args, string $url ) or add_query_arg( $key, $value, $url ).
function add_query_arg(...$args): string {
    if (is_array($args[0])) {
        $params = $args[0];
        $url = $args[1] ?? '';
    } else {
        $params = [$args[0] => $args[1] ?? ''];
        $url = $args[2] ?? '';
    }
    $frag = '';
    if (($hash = strpos($url, '#')) !== false) {
        $frag = substr($url, $hash);
        $url = substr($url, 0, $hash);
    }
    $existing = [];
    if (($q = strpos($url, '?')) !== false) {
        parse_str(substr($url, $q + 1), $existing);
        $url = substr($url, 0, $q);
    }
    $merged = array_merge($existing, $params);
    return $url . (empty($merged) ? '' : '?' . http_build_query($merged)) . $frag;
}

// Options functions
$_mock_options = [];

function get_option(string $option, $default = false) {
    global $_mock_options;
    return $_mock_options[$option] ?? $default;
}

function update_option(string $option, $value, $autoload = null): bool {
    global $_mock_options;
    $_mock_options[$option] = $value;
    return true;
}

function add_option(string $option, $value = '', $deprecated = '', $autoload = 'yes'): bool {
    global $_mock_options;
    if (!isset($_mock_options[$option])) {
        $_mock_options[$option] = $value;
        return true;
    }
    return false;
}

function delete_option(string $option): bool {
    global $_mock_options;
    unset($_mock_options[$option]);
    return true;
}

// Transients (using options for mock)
$_mock_transients = [];

function get_transient(string $transient) {
    global $_mock_transients;
    $data = $_mock_transients[$transient] ?? false;
    if ($data === false) {
        return false;
    }
    if ($data['expires'] < time()) {
        unset($_mock_transients[$transient]);
        return false;
    }
    return $data['value'];
}

function set_transient(string $transient, $value, int $expiration = 0): bool {
    global $_mock_transients;
    $_mock_transients[$transient] = [
        'value' => $value,
        'expires' => time() + $expiration,
    ];
    return true;
}

function delete_transient(string $transient): bool {
    global $_mock_transients;
    unset($_mock_transients[$transient]);
    return true;
}

// WordPress utility functions
function wp_parse_args($args, array $defaults = []): array {
    if (is_object($args)) {
        $args = get_object_vars($args);
    } elseif (is_string($args)) {
        parse_str($args, $args);
    }
    return array_merge($defaults, (array) $args);
}

function wp_generate_uuid4(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function wp_list_pluck(array $list, string $field, ?string $index_key = null): array {
    $result = [];
    foreach ($list as $item) {
        $item = (array) $item;
        if ($index_key !== null && isset($item[$index_key])) {
            $result[$item[$index_key]] = $item[$field] ?? null;
        } else {
            $result[] = $item[$field] ?? null;
        }
    }
    return $result;
}

function wp_json_encode($data, int $options = 0, int $depth = 512) {
    return json_encode($data, $options, $depth);
}

function wp_salt(string $scheme = 'auth'): string {
    return 'test_salt_' . $scheme;
}

// Time functions
function current_time(string $type, bool $gmt = false): string|int {
    if ($type === 'timestamp') {
        return time();
    }
    if ($type === 'mysql') {
        return date('Y-m-d H:i:s');
    }
    if ($type === 'c') {
        return date('c');
    }
    return date($type);
}

// i18n functions
function __(string $text, string $domain = 'default'): string {
    return $text;
}

function _e(string $text, string $domain = 'default'): void {
    echo $text;
}

function _n(string $single, string $plural, int $number, string $domain = 'default'): string {
    return $number === 1 ? $single : $plural;
}

function _x(string $text, string $context, string $domain = 'default'): string {
    return $text;
}

function date_i18n(string $format, int $timestamp = 0): string {
    return date($format, $timestamp ?: time());
}

function load_plugin_textdomain(string $domain, string $deprecated = '', string $plugin_rel_path = ''): bool {
    return true;
}

function get_bloginfo(string $show = ''): string {
    switch ($show) {
        case 'name':
            return 'Test Site';
        case 'url':
            return 'https://example.com';
        default:
            return '';
    }
}

// User functions
//
// Capability and login state are controllable so permission-callback tests can
// exercise the unauthorized path. They default to "allowed" (an admin who can
// manage_options), which preserves the behaviour every existing test relied on
// before these were made adjustable. Use PeanutTestHelper::setUserCan() /
// ::setUserLoggedIn() to flip them, and reset between tests.
$_mock_user_can       = true;
$_mock_user_logged_in = true;

function get_current_user_id(): int {
    global $_mock_user_logged_in;
    return $_mock_user_logged_in ? 1 : 0;
}

function current_user_can(string $capability): bool {
    global $_mock_user_can;
    return $_mock_user_can ?? true;
}

function is_user_logged_in(): bool {
    global $_mock_user_logged_in;
    return $_mock_user_logged_in ?? true;
}

function wp_unslash($value) {
    return is_string($value) ? stripslashes($value) : $value;
}

// HTTP API — unit tests make no real network requests, so these always report
// failure. Callers (e.g. the ML abuse detector) must degrade gracefully, and
// these stubs let us exercise that real degradation path instead of mocking it.
function wp_remote_get($url = '', array $args = []) {
    return new WP_Error('http_request_failed', 'No HTTP requests in unit tests.');
}

function wp_remote_post($url = '', array $args = []) {
    return new WP_Error('http_request_failed', 'No HTTP requests in unit tests.');
}

function wp_remote_retrieve_response_code($response): int {
    return 0;
}

function wp_remote_retrieve_body($response): string {
    return '';
}

// Nonce functions
function wp_create_nonce(string $action = ''): string {
    return md5($action . 'test_nonce');
}

function wp_verify_nonce(string $nonce, string $action = ''): int {
    return $nonce === md5($action . 'test_nonce') ? 1 : 0;
}

// Mail function
$_mock_emails = [];

function wp_mail(string $to, string $subject, string $message, $headers = '', $attachments = []): bool {
    global $_mock_emails;
    $_mock_emails[] = [
        'to' => $to,
        'subject' => $subject,
        'message' => $message,
        'headers' => $headers,
        'attachments' => $attachments,
    ];
    return true;
}

// Hooks (simplified stubs)
$_mock_actions = [];
$_mock_filters = [];

function add_action(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
    global $_mock_actions;
    $_mock_actions[$tag][] = ['callback' => $callback, 'priority' => $priority];
    return true;
}

function do_action(string $tag, ...$args): void {
    global $_mock_actions;
    if (!isset($_mock_actions[$tag])) {
        return;
    }
    foreach ($_mock_actions[$tag] as $action) {
        call_user_func_array($action['callback'], $args);
    }
}

function add_filter(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
    global $_mock_filters;
    $_mock_filters[$tag][] = ['callback' => $callback, 'priority' => $priority];
    return true;
}

function apply_filters(string $tag, $value, ...$args) {
    global $_mock_filters;
    if (!isset($_mock_filters[$tag])) {
        return $value;
    }
    foreach ($_mock_filters[$tag] as $filter) {
        $value = call_user_func_array($filter['callback'], array_merge([$value], $args));
    }
    return $value;
}

function register_rest_route(string $namespace, string $route, array $args = []): bool {
    return true;
}

function register_activation_hook(string $file, callable $callback): void {
    // No-op in tests
}

function register_deactivation_hook(string $file, callable $callback): void {
    // No-op in tests
}

function is_admin(): bool {
    return true;
}

function is_wp_error($thing): bool {
    return $thing instanceof WP_Error;
}

// Upload directory mock
function wp_upload_dir(): array {
    return [
        'path' => '/var/www/html/wp-content/uploads/' . date('Y/m'),
        'url' => 'https://example.com/wp-content/uploads/' . date('Y/m'),
        'subdir' => '/' . date('Y/m'),
        'basedir' => '/var/www/html/wp-content/uploads',
        'baseurl' => 'https://example.com/wp-content/uploads',
        'error' => false,
    ];
}

function status_header(int $code): void {
    // No-op in tests
}

function wp_die(string $message = '', $title = '', $args = []): void {
    throw new Exception($message);
}

function flush_rewrite_rules(): void {
    // No-op in tests
}

/**
 * Helper class for tests
 */
class PeanutTestHelper {
    /**
     * Get sent emails
     */
    public static function getSentEmails(): array {
        global $_mock_emails;
        return $_mock_emails;
    }

    /**
     * Clear sent emails
     */
    public static function clearEmails(): void {
        global $_mock_emails;
        $_mock_emails = [];
    }

    /**
     * Clear all transients
     */
    public static function clearTransients(): void {
        global $_mock_transients;
        $_mock_transients = [];
    }

    /**
     * Clear all options
     */
    public static function clearOptions(): void {
        global $_mock_options;
        $_mock_options = [];
    }

    /**
     * Set a mock option
     */
    public static function setOption(string $key, $value): void {
        global $_mock_options;
        $_mock_options[$key] = $value;
    }

    /**
     * Control what current_user_can() returns (default true).
     */
    public static function setUserCan(bool $can): void {
        global $_mock_user_can;
        $_mock_user_can = $can;
    }

    /**
     * Control what is_user_logged_in()/get_current_user_id() report (default true).
     */
    public static function setUserLoggedIn(bool $logged_in): void {
        global $_mock_user_logged_in;
        $_mock_user_logged_in = $logged_in;
    }

    /**
     * Reset auth state to the default authenticated admin.
     */
    public static function resetUser(): void {
        global $_mock_user_can, $_mock_user_logged_in;
        $_mock_user_can       = true;
        $_mock_user_logged_in = true;
    }

    /**
     * Create a mock license object
     */
    public static function createMockLicense(array $overrides = []): object {
        $defaults = [
            'id' => rand(1, 1000),
            'license_key' => 'ABCD-EFGH-IJKL-MNOP',
            'license_key_hash' => hash('sha256', 'ABCD-EFGH-IJKL-MNOP'),
            'order_id' => 100,
            'subscription_id' => null,
            'user_id' => 1,
            'customer_email' => 'test@example.com',
            'customer_name' => 'Test User',
            'product_id' => 1,
            'tier' => 'pro',
            'status' => 'active',
            'max_activations' => 3,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 year')),
            'activations' => [],
            'activations_count' => 0,
        ];

        return (object) array_merge($defaults, $overrides);
    }

    /**
     * Create a mock activation object
     */
    public static function createMockActivation(array $overrides = []): object {
        $defaults = [
            'id' => rand(1, 1000),
            'license_id' => 1,
            'site_url' => 'https://test-site.example.com',
            'site_name' => 'Test Site',
            'site_hash' => md5('https://test-site.example.com'),
            'ip_address' => '192.168.1.1',
            'plugin_version' => '1.0.0',
            'wp_version' => '6.4',
            'php_version' => '8.2',
            'is_multisite' => 0,
            'active_plugins' => 10,
            'health_status' => 'healthy',
            'health_errors' => null,
            'activated_at' => date('Y-m-d H:i:s'),
            'last_checked' => date('Y-m-d H:i:s'),
            'deactivated_at' => null,
            'is_active' => 1,
        ];

        return (object) array_merge($defaults, $overrides);
    }

    /**
     * Create a mock WP_REST_Request
     */
    public static function createMockRequest(string $method, string $route, array $params = []): WP_REST_Request {
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return $request;
    }
}

// Load standalone plugin helpers (HMAC download-token functions live outside any
// class; the production code calls them as globals, so load them in isolation).
require_once PEANUT_LICENSE_SERVER_PATH . 'includes/download-token-functions.php';

// Load plugin classes
require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-license-manager.php';
require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-license-validator.php';
require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-rate-limiter.php';
require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-api-security.php';
require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-security-features.php';
require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-update-server.php';
require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-gdpr-compliance.php';
require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-webhook-handler.php';
require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-api-endpoints.php';
