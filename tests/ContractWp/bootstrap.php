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

require __DIR__ . '/../../.peanut/wp-harness/bootstrap-wp.php';
