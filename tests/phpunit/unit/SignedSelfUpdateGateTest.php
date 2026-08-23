<?php
/**
 * PAR-407: the License Server consumes only its own signed update channel.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SignedSelfUpdateGateTest extends TestCase {

    private string $bootstrapSource;

    private string $updaterSource;

    protected function setUp(): void {
        parent::setUp();
        $this->bootstrapSource = file_get_contents(PEANUT_LICENSE_SERVER_PATH . 'peanut-license-server.php');
        $this->updaterSource = file_get_contents(PEANUT_LICENSE_SERVER_PATH . 'includes/class-license-self-updater.php');
        PeanutTestHelper::clearTransients();
    }

    public function test_self_updater_and_signed_gate_are_registered_early(): void {
        $this->assertStringContainsString(
            "add_action('plugins_loaded', 'peanut_license_server_register_self_updater', 1)",
            $this->bootstrapSource
        );
        $this->assertStringContainsString('SignedUpdateGate', $this->bootstrapSource);
        $this->assertStringContainsString('new Peanut_License_Self_Updater()', $this->bootstrapSource);
        $this->assertStringContainsString("add_filter('pre_set_site_transient_update_plugins'", $this->updaterSource);
        $this->assertStringContainsString("add_filter('plugins_api'", $this->updaterSource);
    }

    public function test_missing_verifier_disables_self_updates_loudly(): void {
        $this->assertStringContainsString('Self-updates are disabled', $this->bootstrapSource);
        $this->assertStringContainsString('admin_notices', $this->bootstrapSource);
        $this->assertStringContainsString('class_exists', $this->bootstrapSource);
    }

    public function test_shared_verifier_is_a_shipping_dependency(): void {
        $composer = json_decode(file_get_contents(PEANUT_LICENSE_SERVER_PATH . 'composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('^0.5.0', $composer['require']['peanut/formflow-core'] ?? null);
    }

    public function test_license_server_is_registered_on_its_own_update_channel(): void {
        $this->assertTrue(Peanut_Update_Server::is_valid_product('peanut-license-server'));

        PeanutTestHelper::setOption('peanut_peanut-license-server_version', '1.4.6');
        $server = new Peanut_Update_Server('peanut-license-server');
        $result = $server->check_update('1.4.5');

        $this->assertTrue($result['update_available']);
        $this->assertSame('1.4.6', $result['latest_version']);
        $this->assertSame(
            'https://github.com/peanutgraphic/peanut-license-server/releases/download/v1.4.6/peanut-license-server-1.4.6.zip',
            $result['plugin_info']['download_url']
        );
    }

    public function test_transport_failure_never_creates_an_update_offer(): void {
        $transient = (object) [
            'checked' => ['peanut-license-server/peanut-license-server.php' => '1.4.5'],
            'response' => [],
        ];

        $result = (new Peanut_License_Self_Updater())->check_for_update($transient);

        $this->assertSame([], $result->response);
    }

    public function test_unsigned_tampered_foreign_key_and_incomplete_packages_are_refused(): void {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('libsodium unavailable');
        }

        $verifier = '\Peanut\FormCore\Update\PackageVerifier';
        $this->assertTrue(class_exists($verifier));

        $keypair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($keypair));
        $secret = sodium_crypto_sign_secretkey($keypair);
        $bytes = 'PK' . str_repeat('license-server', 100);
        $signed = [
            'sha256' => hash('sha256', $bytes),
            'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secret)),
        ];

        $this->assertTrue($verifier::verifyBytes($bytes, $signed, $public));
        $this->assertFalse($verifier::verifyBytes($bytes, [], $public));
        $this->assertFalse($verifier::verifyBytes($bytes . 'tampered', $signed, $public));
        $this->assertFalse($verifier::verifyBytes($bytes, ['sha256' => hash('sha256', $bytes)], $public));

        $other = sodium_crypto_sign_keypair();
        $this->assertFalse(
            $verifier::verifyBytes($bytes, $signed, base64_encode(sodium_crypto_sign_publickey($other)))
        );
    }

    public function test_only_trusted_https_package_hosts_are_accepted(): void {
        $verifier = '\Peanut\FormCore\Update\PackageVerifier';
        $hosts = ['peanutgraphic.com', 'github.com'];

        $this->assertTrue($verifier::isTrustedPackageUrl('https://github.com/peanutgraphic/release.zip', $hosts));
        $this->assertFalse($verifier::isTrustedPackageUrl('http://github.com/peanutgraphic/release.zip', $hosts));
        $this->assertFalse($verifier::isTrustedPackageUrl('https://github.com.evil.test/release.zip', $hosts));
    }
}
