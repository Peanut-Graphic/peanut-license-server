<?php
/**
 * WooCommerce customer-portal ownership tests.
 *
 * WooCommerce does not verify a changed (or freshly registered) account email,
 * so the email address on an account proves nothing about who bought a
 * license. These tests pin the rule that the My Account → Licenses page and
 * the peanut_deactivate_customer_site AJAX action trust ONLY the license's
 * user_id (or manage_options), and that guest-checkout licenses are linked to
 * an account only through a signed, expiring link mailed to the purchase
 * address.
 *
 * WooCommerce itself is not loaded: the integration class bails out of
 * init_hooks() without it, and every method under test is called directly
 * against the mock-WordPress harness and a scripted $wpdb.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Scripted $wpdb: answers get_row/get_results by query substring and records
 * every write so a test can prove a write did (or did not) happen.
 */
class PortalSpyWPDB extends MockWPDB {
    /** @var string[] */
    public array $queries = [];
    /** @var array<string,mixed> substring => get_row answer */
    public array $rows = [];
    /** @var array<string,array> substring => get_results answer */
    public array $results = [];
    /** @var array<int,array> */
    public array $updates = [];
    /** @var string[] */
    public array $writes = [];

    public function get_row(?string $query = null, $output = OBJECT, $offset = 0) {
        $this->queries[] = (string) $query;
        foreach ($this->rows as $needle => $answer) {
            if ($query !== null && stripos($query, $needle) !== false) {
                return $answer;
            }
        }
        return null;
    }

    public function get_results(?string $query = null, $output = OBJECT): array {
        $this->queries[] = (string) $query;
        foreach ($this->results as $needle => $answer) {
            if ($query !== null && stripos($query, $needle) !== false) {
                return $answer;
            }
        }
        return [];
    }

    public function update(string $table, array $data, array $where, $format = null, $where_format = null) {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
        return 1;
    }

    public function query(string $query) {
        $this->queries[] = $query;
        if (preg_match('/^\s*(UPDATE|INSERT|DELETE)/i', $query)) {
            $this->writes[] = $query;
        }
        return 1;
    }
}

/**
 * @covers Peanut_WooCommerce_Integration
 */
class WooCommercePortalOwnershipTest extends TestCase {

    private PortalSpyWPDB $spy;
    private $origWpdb;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->origWpdb = $wpdb;
        $this->spy = new PortalSpyWPDB();
        $wpdb = $this->spy;
        $GLOBALS['wpdb'] = $this->spy;

        PeanutTestHelper::resetUser();
        PeanutTestHelper::clearTransients();
        PeanutTestHelper::clearOptions();
        PeanutTestHelper::clearEmails();
        $_POST = [];
        $_REQUEST = [];
        $_GET = [];
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->origWpdb;
        $GLOBALS['wpdb'] = $this->origWpdb;
        PeanutTestHelper::resetUser();
        PeanutTestHelper::clearTransients();
        PeanutTestHelper::clearEmails();
        $_POST = [];
        $_REQUEST = [];
        $_GET = [];
        parent::tearDown();
    }

    private function integration(): Peanut_WooCommerce_Integration {
        // The constructor returns before registering hooks when WooCommerce is
        // absent, which is exactly the state of this harness.
        return new Peanut_WooCommerce_Integration();
    }

    /** A license row as the licenses table returns it (before activations are attached). */
    private function licenseRow(array $overrides = []): object {
        return (object) array_merge([
            'id'               => 42,
            'license_key'      => 'VICT-IM00-KEY0-AAAA',
            'license_key_hash' => hash('sha256', 'VICT-IM00-KEY0-AAAA'),
            'user_id'          => null,
            'customer_email'   => 'victim@example.com',
            'customer_name'    => 'Victim',
            'product_id'       => 1,
            'tier'             => 'pro',
            'status'           => 'active',
            'max_activations'  => 3,
            'expires_at'       => null,
            'created_at'       => '2026-01-01 00:00:00',
        ], $overrides);
    }

    private function renderPortal(): string {
        ob_start();
        try {
            $this->integration()->render_licenses_page();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    private function callAjax(callable $fn): PeanutJsonResponse {
        try {
            $fn();
        } catch (PeanutJsonResponse $response) {
            return $response;
        }
        $this->fail('Expected the AJAX handler to send a JSON response.');
    }

    // ---------------------------------------------------------------------
    // Ownership predicate
    // ---------------------------------------------------------------------

    /** @test */
    public function ownership_requires_a_matching_positive_user_id(): void {
        $this->assertTrue(Peanut_WooCommerce_Integration::user_owns_license((object) ['user_id' => '7'], 7));
        $this->assertFalse(Peanut_WooCommerce_Integration::user_owns_license((object) ['user_id' => 8], 7));
        $this->assertFalse(Peanut_WooCommerce_Integration::user_owns_license((object) ['user_id' => null], 7));
        $this->assertFalse(Peanut_WooCommerce_Integration::user_owns_license((object) ['user_id' => 0], 7));
        $this->assertFalse(Peanut_WooCommerce_Integration::user_owns_license((object) ['user_id' => 0], 0));
        $this->assertFalse(
            Peanut_WooCommerce_Integration::user_owns_license(
                (object) ['user_id' => null, 'customer_email' => 'victim@example.com'],
                7
            ),
            'A matching customer_email must never establish ownership.'
        );
    }

    // ---------------------------------------------------------------------
    // My Account → Licenses
    // ---------------------------------------------------------------------

    /** @test */
    public function portal_never_lists_licenses_matched_only_by_account_email(): void {
        // Attacker registers (or changes their account email) to the victim's
        // purchase address. They own no licenses by user_id.
        PeanutTestHelper::setUserCan(false);
        PeanutTestHelper::setCurrentUser(2, 'victim@example.com');

        $this->spy->results['WHERE user_id = 2'] = [];
        $this->spy->results["customer_email = 'victim@example.com'"] = [$this->licenseRow()];

        $html = $this->renderPortal();

        $this->assertStringNotContainsString('VICT-IM00-KEY0-AAAA', $html, 'Another customer\'s key leaked into the portal.');
        $this->assertStringNotContainsString('data-license-id="42"', $html);
    }

    /** @test */
    public function portal_lists_licenses_owned_by_user_id(): void {
        PeanutTestHelper::setUserCan(false);
        PeanutTestHelper::setCurrentUser(5, 'owner@example.com');

        $this->spy->results['WHERE user_id = 5'] = [
            $this->licenseRow(['id' => 9, 'user_id' => 5, 'license_key' => 'OWNR-0000-1111-2222', 'customer_email' => 'owner@example.com']),
        ];

        $html = $this->renderPortal();

        $this->assertStringContainsString('OWNR-0000-1111-2222', $html);
        $this->assertStringContainsString('data-license-id="9"', $html);
    }

    /** @test */
    public function portal_download_link_never_puts_the_license_key_in_the_url(): void {
        PeanutTestHelper::setUserCan(false);
        PeanutTestHelper::setCurrentUser(5, 'owner@example.com');

        $this->spy->results['WHERE user_id = 5'] = [
            $this->licenseRow(['id' => 9, 'user_id' => 5, 'license_key' => 'OWNR-0000-1111-2222']),
        ];

        $html = $this->renderPortal();

        $this->assertMatchesRegularExpression('/href="[^"]*updates\/download[^"]*token=/', $html, 'Expected a signed-token download link.');
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*license=/', $html, 'License key must not be placed in a URL.');

        // The token must be one the /updates/download route accepts.
        $this->assertSame(1, preg_match('/href="([^"]*updates\/download[^"]*)"/', $html, $m));
        parse_str((string) parse_url(html_entity_decode($m[1]), PHP_URL_QUERY), $query);
        $this->assertSame('peanut-suite', $query['plugin']);
        $this->assertTrue(peanut_verify_download_token('peanut-suite', $query['token'], ''));
    }

    /** @test */
    public function portal_offers_no_download_for_a_license_that_cannot_download(): void {
        $this->assertSame('', Peanut_WooCommerce_Integration::get_portal_download_url(
            $this->licenseRow(['status' => 'expired'])
        ));
        $this->assertSame('', Peanut_WooCommerce_Integration::get_portal_download_url(
            $this->licenseRow(['expires_at' => '2020-01-01 00:00:00'])
        ));
        $this->assertNotSame('', Peanut_WooCommerce_Integration::get_portal_download_url($this->licenseRow()));
    }

    // ---------------------------------------------------------------------
    // AJAX: peanut_deactivate_customer_site
    // ---------------------------------------------------------------------

    private function primeAjax(int $activation_id): void {
        $_POST['activation_id'] = (string) $activation_id;
        $_POST['nonce'] = wp_create_nonce('peanut_customer_portal');
        $_REQUEST = $_POST;
    }

    /** @test */
    public function deactivate_refuses_a_user_whose_only_link_is_the_purchase_email(): void {
        PeanutTestHelper::setUserCan(false);
        PeanutTestHelper::setCurrentUser(2, 'victim@example.com');
        $this->primeAjax(77);

        // Guest purchase (no user_id) bought with the attacker's current email.
        $this->spy->rows['a.id = 77'] = (object) [
            'id' => 77, 'license_id' => 42, 'user_id' => null, 'customer_email' => 'victim@example.com',
        ];

        $response = $this->callAjax(fn() => $this->integration()->ajax_deactivate_site());

        $this->assertFalse($response->success);
        $this->assertSame([], $this->spy->updates, 'No activation may be deactivated.');
    }

    /** @test */
    public function deactivate_refuses_another_users_license_even_when_emails_match(): void {
        PeanutTestHelper::setUserCan(false);
        PeanutTestHelper::setCurrentUser(2, 'victim@example.com');
        $this->primeAjax(78);

        $this->spy->rows['a.id = 78'] = (object) [
            'id' => 78, 'license_id' => 43, 'user_id' => 99, 'customer_email' => 'victim@example.com',
        ];

        $response = $this->callAjax(fn() => $this->integration()->ajax_deactivate_site());

        $this->assertFalse($response->success);
        $this->assertSame([], $this->spy->updates);
    }

    /** @test */
    public function deactivate_allows_the_owner_by_user_id(): void {
        PeanutTestHelper::setUserCan(false);
        PeanutTestHelper::setCurrentUser(5, 'someone-else@example.com');
        $this->primeAjax(79);

        $this->spy->rows['a.id = 79'] = (object) [
            'id' => 79, 'license_id' => 44, 'user_id' => '5', 'customer_email' => 'owner@example.com',
        ];

        $response = $this->callAjax(fn() => $this->integration()->ajax_deactivate_site());

        $this->assertTrue($response->success);
        $this->assertCount(1, $this->spy->updates);
        $this->assertSame(['id' => 79], $this->spy->updates[0]['where']);
    }

    /** @test */
    public function deactivate_allows_a_site_administrator(): void {
        PeanutTestHelper::setUserCan(true);
        PeanutTestHelper::setCurrentUser(1, 'admin@example.com');
        $this->primeAjax(80);

        $this->spy->rows['a.id = 80'] = (object) [
            'id' => 80, 'license_id' => 45, 'user_id' => 99, 'customer_email' => 'customer@example.com',
        ];

        $response = $this->callAjax(fn() => $this->integration()->ajax_deactivate_site());

        $this->assertTrue($response->success);
    }

    // ---------------------------------------------------------------------
    // Guest-license claim flow
    // ---------------------------------------------------------------------

    private function claimableRows(): array {
        return [
            (object) ['id' => 42, 'customer_email' => 'victim@example.com'],
            (object) ['id' => 43, 'customer_email' => 'victim@example.com'],
        ];
    }

    /** @test */
    public function claim_request_mails_the_link_to_the_purchase_address_only(): void {
        PeanutTestHelper::setUserCan(false);
        PeanutTestHelper::setCurrentUser(2, 'victim@example.com');
        $this->spy->results['user_id IS NULL OR user_id = 0'] = $this->claimableRows();

        $message = Peanut_WooCommerce_Integration::request_claim_email(2, 'victim@example.com');

        $emails = PeanutTestHelper::getSentEmails();
        $this->assertCount(1, $emails);
        $this->assertSame('victim@example.com', $emails[0]['to']);
        $this->assertStringContainsString('peanut_claim_sig=', $emails[0]['message']);
        $this->assertStringNotContainsString('VICT-IM00-KEY0-AAAA', $emails[0]['message']);
        $this->assertNotSame('', $message);
        $this->assertSame([], $this->spy->writes, 'Requesting a claim must not bind anything yet.');
    }

    /** @test */
    public function claim_request_does_not_reveal_whether_licenses_exist(): void {
        PeanutTestHelper::setUserCan(false);
        PeanutTestHelper::setCurrentUser(2, 'nobody@example.com');

        $none = Peanut_WooCommerce_Integration::request_claim_email(2, 'nobody@example.com');
        $this->assertSame([], PeanutTestHelper::getSentEmails());

        PeanutTestHelper::clearTransients();
        PeanutTestHelper::setCurrentUser(3, 'victim@example.com');
        $this->spy->results['user_id IS NULL OR user_id = 0'] = $this->claimableRows();
        $some = Peanut_WooCommerce_Integration::request_claim_email(3, 'victim@example.com');

        $this->assertSame(
            str_replace('nobody@example.com', 'X', $none),
            str_replace('victim@example.com', 'X', $some),
            'The on-screen reply must be identical whether or not licenses exist.'
        );
    }

    /** @test */
    public function claim_request_is_rate_limited_per_user(): void {
        PeanutTestHelper::setUserCan(false);
        PeanutTestHelper::setCurrentUser(2, 'victim@example.com');
        $this->spy->results['user_id IS NULL OR user_id = 0'] = $this->claimableRows();

        Peanut_WooCommerce_Integration::request_claim_email(2, 'victim@example.com');
        Peanut_WooCommerce_Integration::request_claim_email(2, 'victim@example.com');

        $this->assertCount(1, PeanutTestHelper::getSentEmails());
    }

    /** @return array<string,string> */
    private function claimArgsFor(int $user_id, array $ids, string $email, int $expires): array {
        $url = Peanut_WooCommerce_Integration::build_claim_url($ids, $user_id, $email, $expires);
        $query = (string) parse_url(html_entity_decode($url), PHP_URL_QUERY);
        parse_str($query, $args);
        return $args;
    }

    /** @test */
    public function a_valid_claim_link_binds_the_guest_licenses_to_the_requesting_user(): void {
        PeanutTestHelper::setUserCan(false);
        PeanutTestHelper::setCurrentUser(2, 'victim@example.com');
        $this->spy->results['WHERE id IN'] = $this->claimableRows();

        $args = $this->claimArgsFor(2, [43, 42], 'victim@example.com', time() + 600);
        $result = Peanut_WooCommerce_Integration::process_claim($args, 2);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame(2, $result['claimed']);
        $this->assertCount(2, $this->spy->writes);
        foreach ($this->spy->writes as $write) {
            $this->assertMatchesRegularExpression('/SET user_id = 2\s+WHERE id = (42|43) AND \(user_id IS NULL OR user_id = 0\)/', $write);
        }
    }

    /** @test */
    public function a_claim_link_cannot_be_used_by_a_different_account(): void {
        PeanutTestHelper::setUserCan(false);
        PeanutTestHelper::setCurrentUser(3, 'attacker@example.com');
        $this->spy->results['WHERE id IN'] = $this->claimableRows();

        $args = $this->claimArgsFor(2, [42, 43], 'victim@example.com', time() + 600);
        $result = Peanut_WooCommerce_Integration::process_claim($args, 3);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->spy->writes);
    }

    /** @test */
    public function an_expired_claim_link_is_refused(): void {
        PeanutTestHelper::setCurrentUser(2, 'victim@example.com');
        $this->spy->results['WHERE id IN'] = $this->claimableRows();

        $args = $this->claimArgsFor(2, [42, 43], 'victim@example.com', time() - 1);
        $result = Peanut_WooCommerce_Integration::process_claim($args, 2);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->spy->writes);
    }

    /** @test */
    public function a_tampered_claim_link_is_refused(): void {
        PeanutTestHelper::setCurrentUser(2, 'victim@example.com');
        $this->spy->results['WHERE id IN'] = array_merge($this->claimableRows(), [
            (object) ['id' => 44, 'customer_email' => 'victim@example.com'],
        ]);

        $args = $this->claimArgsFor(2, [42, 43], 'victim@example.com', time() + 600);
        $args['peanut_claim_ids'] = '42,43,44';
        $result = Peanut_WooCommerce_Integration::process_claim($args, 2);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->spy->writes);
    }

    /** @test */
    public function a_claim_link_signed_for_one_email_cannot_claim_another_emails_licenses(): void {
        PeanutTestHelper::setCurrentUser(2, 'attacker@example.com');
        // The signed ids now belong to a different purchase address.
        $this->spy->results['WHERE id IN'] = [
            (object) ['id' => 42, 'customer_email' => 'victim@example.com'],
        ];

        $args = $this->claimArgsFor(2, [42], 'attacker@example.com', time() + 600);
        $result = Peanut_WooCommerce_Integration::process_claim($args, 2);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->spy->writes);
    }

    /** @test */
    public function already_claimed_licenses_are_not_rebound(): void {
        PeanutTestHelper::setCurrentUser(2, 'victim@example.com');
        // The claimable query (user_id IS NULL OR 0) no longer returns them.
        $this->spy->results['WHERE id IN'] = [];

        $args = $this->claimArgsFor(2, [42, 43], 'victim@example.com', time() + 600);
        $result = Peanut_WooCommerce_Integration::process_claim($args, 2);

        $this->assertFalse($result['success']);
        $this->assertSame([], $this->spy->writes);
    }
}
