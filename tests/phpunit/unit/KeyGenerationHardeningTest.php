<?php
/**
 * License-key generation and legacy download-handler hardening.
 *
 * Keys were built from md5(wp_generate_uuid4()); wp_generate_uuid4() draws on
 * mt_rand(), which is not a CSPRNG, so whoever recovers the generator state
 * can predict keys. Keys now come from random_bytes() in the exact same
 * XXXX-XXXX-XXXX-XXXX uppercase-hex shape every client and validator expects.
 *
 * The admin-ajax download handler checked a nonce that nothing ever creates
 * and performed no license check; its logged-out (nopriv) registration is
 * gone and the remaining handler is limited to administrators.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers Peanut_License_Manager::generate_license_key
 */
class KeyGenerationHardeningTest extends TestCase {

    protected function tearDown(): void {
        mt_srand(); // Re-seed randomly so no other test inherits a fixed seed.
        parent::tearDown();
    }

    /** @test */
    public function keys_are_not_reproducible_from_the_mt_rand_state(): void {
        mt_srand(424242);
        $first = Peanut_License_Manager::generate_license_key();

        mt_srand(424242);
        $second = Peanut_License_Manager::generate_license_key();

        $this->assertNotSame($first, $second, 'Key generation must not depend on mt_rand().');
    }

    /** @test */
    public function keys_keep_the_established_format(): void {
        $seen = [];
        for ($i = 0; $i < 2000; $i++) {
            $key = Peanut_License_Manager::generate_license_key();

            $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $key);
            $this->assertTrue(Peanut_License_Validator::is_valid_format($key));
            $this->assertSame($key, Peanut_License_Validator::sanitize_key($key));
            $seen[$key] = true;
        }

        $this->assertCount(2000, $seen, 'Generated keys collided.');
    }

    /** @test */
    public function key_generation_source_uses_a_csprng(): void {
        $source = (string) file_get_contents(PEANUT_LICENSE_SERVER_PATH . 'includes/class-license-manager.php');
        $start = strpos($source, 'function generate_license_key');
        $body = substr($source, $start, (int) strpos($source, "\n    }\n", $start) - $start);

        $this->assertStringContainsString('random_bytes(', $body);
        $this->assertStringNotContainsString('wp_generate_uuid4', $body);
        $this->assertStringNotContainsString('md5(', $body);
    }

    /** @test */
    public function the_unauthenticated_ajax_download_handler_is_not_registered(): void {
        $source = (string) file_get_contents(PEANUT_LICENSE_SERVER_PATH . 'peanut-license-server.php');

        $this->assertStringNotContainsString('wp_ajax_nopriv_peanut_download_plugin', $source);
    }

    /** @test */
    public function the_remaining_ajax_download_handler_requires_an_administrator(): void {
        $source = (string) file_get_contents(PEANUT_LICENSE_SERVER_PATH . 'peanut-license-server.php');
        $start = strpos($source, 'public function handle_ajax_download');
        $this->assertNotFalse($start);
        $body = substr($source, $start, (int) strpos($source, 'readfile(', $start) - $start);

        $this->assertMatchesRegularExpression("/current_user_can\\(\\s*'manage_options'\\s*\\)/", $body);
    }
}
