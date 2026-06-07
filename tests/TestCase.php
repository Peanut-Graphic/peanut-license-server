<?php
/**
 * Base Test Case for the Integration suite.
 *
 * Runs on the self-contained mock-WordPress bootstrap (tests/phpunit/bootstrap.php),
 * the same one the gated Unit suite uses. That bootstrap defines the WordPress
 * functions the plugin calls as *real* global functions backed by in-memory
 * globals ($_mock_options, $_mock_transients, ...), plus a $wpdb mock. So this
 * base needs no Brain Monkey and no real WP test library — it just resets that
 * shared mock state between tests for isolation.
 *
 * (Historically this extended a Brain Monkey setup whose stubs lived in the old
 * tests/bootstrap.php. When the testing scrub switched the active bootstrap to
 * tests/phpunit/bootstrap.php, that stub function was no longer loaded and every
 * Integration test errored in setUp. This base re-homes the suite on the new
 * mock world.)
 *
 * @package Peanut_License_Server\Tests
 */

namespace Peanut\LicenseServer\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

    /**
     * Reset the shared mock-WordPress state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetMockState();
    }

    /**
     * Reset the shared mock-WordPress state after each test, so a test can never
     * leak options/transients into the next one.
     */
    protected function tearDown(): void {
        $this->resetMockState();
        parent::tearDown();
    }

    /**
     * Clear every in-memory store the mock bootstrap exposes.
     */
    private function resetMockState(): void {
        global $_mock_options, $_mock_transients, $_mock_emails, $_mock_actions, $_mock_filters;
        $_mock_options    = [];
        $_mock_transients = [];
        $_mock_emails     = [];
        $_mock_actions    = [];
        $_mock_filters    = [];
    }

    /**
     * Set a mock option (writes the store get_option() reads).
     */
    protected function setOption(string $key, mixed $value): void {
        global $_mock_options;
        $_mock_options[$key] = $value;
    }

    /**
     * Set a mock transient (matches the {value, expires} shape the bootstrap's
     * get_transient() expects).
     */
    protected function setTransient(string $key, mixed $value, int $expiration = 0): void {
        global $_mock_transients;
        $_mock_transients[$key] = [
            'value'   => $value,
            'expires' => time() + ($expiration > 0 ? $expiration : 0),
        ];
    }

    /**
     * Read a mock transient's value (false if unset or expired).
     */
    protected function getTransient(string $key): mixed {
        return get_transient($key);
    }

    /**
     * Generate a license key in the plugin's XXXX-XXXX-XXXX-XXXX format.
     */
    protected function generateLicenseKey(): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $key = '';
        for ($i = 0; $i < 4; $i++) {
            if ($i > 0) {
                $key .= '-';
            }
            for ($j = 0; $j < 4; $j++) {
                $key .= $chars[random_int(0, strlen($chars) - 1)];
            }
        }
        return $key;
    }
}
