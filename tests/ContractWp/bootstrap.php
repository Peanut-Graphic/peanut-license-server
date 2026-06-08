<?php
/**
 * Real-WordPress REST contract suite bootstrap (net 7).
 *
 * Boots a REAL WordPress (via the shared Peanut wp-harness) so the License
 * Server's `register_rest_route('peanut-api/v1', ...)` calls actually run and
 * the contract tests can pin real `/wp-json/peanut-api/v1/*` responses. This is
 * intentionally SEPARATE from the existing mock-based tests/phpunit suites — it
 * must never fall back to mocks.
 */

define('PLUGIN_MAIN_FILE', dirname(__DIR__, 2) . '/peanut-license-server.php');

// The WordPress test bootstrap loads the Yoast PHPUnit Polyfills via the
// WP_TESTS_PHPUNIT_POLYFILLS_PATH *constant* (not an env var). When we run via a
// standalone phpunit phar, Composer's autoloader isn't active, so point the WP
// bootstrap at the composer-installed polyfills explicitly.
if (! defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
    $polyfills = getenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH')
        ?: dirname(__DIR__, 2) . '/vendor/yoast/phpunit-polyfills';
    if (is_dir($polyfills)) {
        define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills);
    }
}

require __DIR__ . '/../../.peanut/wp-harness/bootstrap-wp.php';
