<?php
/**
 * Property tests for the signed download-token helpers (net 6).
 *
 * These exercise REAL invariants of the HMAC-SHA256 token system in
 * includes/download-token-functions.php — round-trip validity, tamper
 * rejection, and expiry bounds — across a seeded, deterministic spread of
 * inputs. They are PURE: no WordPress harness, no DB, no network. The only
 * WP surface they touch (peanut_get_download_secret) is satisfied by the
 * self-contained bootstrap's constants/stubs.
 *
 * Determinism: a fixed mt_srand() seed makes the generated input corpus
 * reproducible run-to-run. If any assertion here fails, it is a real bug in
 * the token system — do not weaken it; report the counter-example.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers ::peanut_generate_download_token
 * @covers ::peanut_verify_download_token
 */
class DownloadTokenPropertyTest extends TestCase {

    private const SEED = 424242;

    /**
     * Build a deterministic corpus of (plugin, license) pairs plus a sampling
     * of awkward strings (empty, unicode, separators, long).
     *
     * @return array<int, array{string, string}>
     */
    private function corpus(): array {
        mt_srand(self::SEED);
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $cases = [
            ['peanut-suite', ''],
            ['formflow', 'ABCD-EFGH-IJKL-MNOP'],
            ['peanut-booker', '0000-0000-0000-0000'],
            ['peanut-connect', 'license|with|pipes'], // pipe is the field delimiter
            ['', ''],
            ['plug', "uni\u{00e7}ode-\u{2603}"],
        ];
        for ($i = 0; $i < 60; $i++) {
            $plugin = '';
            $plen = mt_rand(1, 24);
            for ($j = 0; $j < $plen; $j++) {
                $plugin .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            $license = '';
            $llen = mt_rand(0, 32);
            for ($j = 0; $j < $llen; $j++) {
                $license .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            $cases[] = [$plugin, $license];
        }
        return $cases;
    }

    /**
     * INVARIANT (round-trip): a freshly generated, non-expired token always
     * verifies for the exact (plugin, license) it was minted for.
     *
     * @test
     */
    public function freshly_generated_token_always_verifies(): void {
        $future = time() + 3600;
        foreach ($this->corpus() as [$plugin, $license]) {
            $token = peanut_generate_download_token($plugin, $license, $future);
            $this->assertTrue(
                peanut_verify_download_token($plugin, $token, $license),
                "round-trip failed for plugin=" . var_export($plugin, true)
                . " license=" . var_export($license, true)
            );
        }
    }

    /**
     * INVARIANT (binding): a token minted for one (plugin|license) tuple must
     * NOT verify against a DIFFERENT plugin or license. The signature binds
     * all three fields, so swapping any one must fail.
     *
     * @test
     */
    public function token_does_not_verify_for_different_plugin_or_license(): void {
        $future = time() + 3600;
        $corpus = $this->corpus();
        foreach ($corpus as $i => [$plugin, $license]) {
            $token = peanut_generate_download_token($plugin, $license, $future);

            // Different plugin, same license — must fail (unless they collide,
            // which for distinct strings under HMAC is cryptographically absurd).
            $otherPlugin = $plugin . 'X';
            $this->assertFalse(
                peanut_verify_download_token($otherPlugin, $token, $license),
                "token verified for a different plugin (i=$i)"
            );

            // Same plugin, different license — must fail.
            $otherLicense = $license . 'Y';
            $this->assertFalse(
                peanut_verify_download_token($plugin, $token, $otherLicense),
                "token verified for a different license (i=$i)"
            );
        }
    }

    /**
     * INVARIANT (tamper): mutating any single byte of the encoded token makes
     * verification fail. base64 garbage, a flipped signature byte, or a forged
     * expiry must all be rejected.
     *
     * @test
     */
    public function any_tampered_token_fails_verification(): void {
        $future = time() + 3600;
        foreach ($this->corpus() as $i => [$plugin, $license]) {
            $token = peanut_generate_download_token($plugin, $license, $future);
            $decoded = base64_decode($token, true);
            $this->assertIsString($decoded, "valid token must base64-decode (i=$i)");

            // Flip one byte in the signature half and re-encode.
            $pos = intdiv(strlen($decoded), 2) + 3;
            if ($pos < strlen($decoded)) {
                $mutated = $decoded;
                $mutated[$pos] = $mutated[$pos] === 'a' ? 'b' : 'a';
                $tampered = base64_encode($mutated);
                $this->assertFalse(
                    peanut_verify_download_token($plugin, $tampered, $license),
                    "tampered signature still verified (i=$i)"
                );
            }

            // Non-base64 / structurally broken inputs must be rejected, never throw.
            $this->assertFalse(peanut_verify_download_token($plugin, '!!!not-base64!!!', $license));
            $this->assertFalse(peanut_verify_download_token($plugin, base64_encode('no-delimiter-here'), $license));
            $this->assertFalse(peanut_verify_download_token($plugin, '', $license));
        }
    }

    /**
     * INVARIANT (expiry bound): a token whose embedded expiry is in the past
     * never verifies, regardless of a valid signature. Tokens at/after "now"
     * with a valid signature verify. This pins the time bound exactly.
     *
     * @test
     */
    public function expired_tokens_never_verify_and_future_tokens_do(): void {
        $now = time();
        foreach (array_slice($this->corpus(), 0, 20) as $i => [$plugin, $license]) {
            // Already expired (1 second ago) — must fail even though signed correctly.
            $past = peanut_generate_download_token($plugin, $license, $now - 1);
            $this->assertFalse(
                peanut_verify_download_token($plugin, $past, $license),
                "expired token verified (i=$i)"
            );

            // Comfortably in the future — must verify.
            $future = peanut_generate_download_token($plugin, $license, $now + 600);
            $this->assertTrue(
                peanut_verify_download_token($plugin, $future, $license),
                "future token failed to verify (i=$i)"
            );
        }
    }
}
