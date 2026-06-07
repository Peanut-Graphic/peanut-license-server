<?php
/**
 * Guards behaviors of the mock-WordPress harness (tests/phpunit/bootstrap.php)
 * that the Unit and Integration suites depend on. These are easy to regress
 * silently — a wrong transient-expiry rule or leaked auth state would quietly
 * change what every other test exercises — so they get their own assertions.
 *
 * @package Peanut_License_Server\Tests
 */

declare(strict_types=1);

namespace Peanut\LicenseServer\Tests\Integration;

use Peanut\LicenseServer\Tests\TestCase;

class MockHarnessTest extends TestCase {

    /**
     * A 0 (non-positive) expiration means "never expires", matching WordPress —
     * the value must remain readable rather than expiring at "now".
     */
    public function test_zero_expiration_transient_never_expires(): void {
        set_transient('peanut_never', 'keep-me', 0);

        // Assert the sentinel directly: under the old behavior this was stored as
        // time() (a large timestamp that expires a second later), so this guard
        // fails on a regression rather than depending on wall-clock timing.
        global $_mock_transients;
        $this->assertSame(0, $_mock_transients['peanut_never']['expires'], 'expected the never-expires sentinel');
        $this->assertSame('keep-me', get_transient('peanut_never'));
    }

    /**
     * A positive expiration is an absolute future expiry — readable before it.
     */
    public function test_time_bounded_transient_is_readable_before_expiry(): void {
        set_transient('peanut_ttl', 'fresh', HOUR_IN_SECONDS);
        $this->assertSame('fresh', get_transient('peanut_ttl'));
    }

    /**
     * Auth state is togglable for unauthorized-path coverage, and resets to the
     * default authenticated admin so it cannot leak between tests.
     */
    public function test_auth_state_toggles_and_resets(): void {
        // Default authenticated admin.
        $this->assertTrue(is_user_logged_in());
        $this->assertTrue(current_user_can('manage_options'));
        $this->assertSame(1, get_current_user_id());

        // Simulate a logged-out, uncapable visitor.
        \PeanutTestHelper::setUserLoggedIn(false);
        \PeanutTestHelper::setUserCan(false);
        $this->assertFalse(is_user_logged_in());
        $this->assertFalse(current_user_can('manage_options'));
        $this->assertSame(0, get_current_user_id());

        // The helper restores the default admin in-test...
        \PeanutTestHelper::resetUser();
        $this->assertTrue(is_user_logged_in());
        $this->assertTrue(current_user_can('manage_options'));
    }

    /**
     * ...and the base TestCase reset guarantees the previous test's toggles did
     * not leak here (this asserts the default with no setup of its own).
     */
    public function test_auth_state_is_isolated_between_tests(): void {
        $this->assertTrue(is_user_logged_in());
        $this->assertTrue(current_user_can('manage_options'));
        $this->assertSame(1, get_current_user_id());
    }
}
