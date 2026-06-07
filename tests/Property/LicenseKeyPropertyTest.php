<?php
/**
 * Property tests for the pure license-key sanitizer/validator (net 6).
 *
 * Peanut_License_Validator::sanitize_key() and ::is_valid_format() are pure
 * static functions (regex + string ops, no WP, no DB). These tests assert
 * REAL invariants across a seeded, deterministic corpus:
 *   - idempotency of sanitize_key
 *   - sanitize_key output charset bound ([A-Z0-9-] only)
 *   - is_valid_format is exactly the canonical XXXX-XXXX-XXXX-XXXX shape
 *   - well-formed keys survive sanitize_key unchanged (round-trip)
 *
 * Determinism: fixed mt_srand() seed. A failure here is a real bug — report
 * the counter-example, never relax the assertion.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers Peanut_License_Validator::sanitize_key
 * @covers Peanut_License_Validator::is_valid_format
 */
class LicenseKeyPropertyTest extends TestCase {

    private const SEED = 991122;

    /**
     * Deterministic corpus of messy raw inputs (whitespace, punctuation,
     * unicode, mixed case, empty).
     *
     * @return array<int, string>
     */
    private function rawCorpus(): array {
        mt_srand(self::SEED);
        // Includes hyphens, the field char of interest, and noise that must be stripped.
        $alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_ @#\$%!.\t\n";
        $cases = ['', '   ', 'abcd-efgh-ijkl-mnop', 'ABCD-EFGH-IJKL-MNOP', '  AbCd EfGh ', "k\u{00e9}y-\u{2603}"];
        for ($i = 0; $i < 80; $i++) {
            $s = '';
            $len = mt_rand(0, 40);
            for ($j = 0; $j < $len; $j++) {
                $s .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            $cases[] = $s;
        }
        return $cases;
    }

    /**
     * INVARIANT (idempotency): sanitize_key(sanitize_key(x)) === sanitize_key(x).
     * A sanitizer that is not idempotent is a latent double-encoding bug.
     *
     * @test
     */
    public function sanitize_key_is_idempotent(): void {
        foreach ($this->rawCorpus() as $raw) {
            $once = Peanut_License_Validator::sanitize_key($raw);
            $twice = Peanut_License_Validator::sanitize_key($once);
            $this->assertSame(
                $once,
                $twice,
                "sanitize_key not idempotent for input " . var_export($raw, true)
            );
        }
    }

    /**
     * INVARIANT (output charset bound): every char of the output is one of
     * A-Z, 0-9, or '-'. Nothing else may escape the sanitizer.
     *
     * @test
     */
    public function sanitize_key_output_is_within_charset(): void {
        foreach ($this->rawCorpus() as $raw) {
            $out = Peanut_License_Validator::sanitize_key($raw);
            $this->assertMatchesRegularExpression(
                '/^[A-Z0-9-]*$/',
                $out,
                "sanitize_key leaked a char outside [A-Z0-9-] for " . var_export($raw, true)
                . " -> " . var_export($out, true)
            );
        }
    }

    /**
     * INVARIANT (is_valid_format == canonical shape): is_valid_format(k) is
     * true IFF k matches exactly four hyphen-separated groups of four
     * alphanumerics (case-insensitive). We cross-check against an independent
     * reference predicate so the regex can't quietly drift.
     *
     * @test
     */
    public function is_valid_format_matches_canonical_shape(): void {
        mt_srand(self::SEED + 1);
        $cases = [
            'ABCD-EFGH-IJKL-MNOP', 'abcd-efgh-ijkl-mnop', '1234-5678-90AB-CDEF',
            'ABC-EFGH-IJKL-MNOP', 'ABCDE-EFGH-IJKL-MNOP', 'ABCD-EFGH-IJKL',
            'ABCD-EFGH-IJKL-MNOP-QRST', 'ABCDEFGHIJKLMNOP', '', 'ABCD EFGH IJKL MNOP',
            'ABCD-EFGH-IJKL-MNO!', ' ABCD-EFGH-IJKL-MNOP',
        ];
        // Add random groups that may or may not be valid.
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        for ($i = 0; $i < 60; $i++) {
            $groups = [];
            $n = mt_rand(2, 5);
            for ($g = 0; $g < $n; $g++) {
                $glen = mt_rand(3, 5);
                $grp = '';
                for ($c = 0; $c < $glen; $c++) {
                    $grp .= $alpha[mt_rand(0, strlen($alpha) - 1)];
                }
                $groups[] = $grp;
            }
            $cases[] = implode('-', $groups);
        }

        foreach ($cases as $k) {
            $reference = $this->referenceValid($k);
            $actual = Peanut_License_Validator::is_valid_format($k);
            $this->assertSame(
                $reference,
                $actual,
                "is_valid_format disagreed with reference for " . var_export($k, true)
            );
        }
    }

    /**
     * INVARIANT (well-formed round-trip): any key that is_valid_format AND
     * already uppercase/trimmed passes through sanitize_key unchanged, and the
     * sanitized form of a valid key remains valid.
     *
     * @test
     */
    public function valid_uppercase_keys_survive_sanitize_unchanged(): void {
        mt_srand(self::SEED + 2);
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        for ($i = 0; $i < 50; $i++) {
            $groups = [];
            for ($g = 0; $g < 4; $g++) {
                $grp = '';
                for ($c = 0; $c < 4; $c++) {
                    $grp .= $alpha[mt_rand(0, strlen($alpha) - 1)];
                }
                $groups[] = $grp;
            }
            $key = implode('-', $groups);
            $this->assertTrue(Peanut_License_Validator::is_valid_format($key));
            $this->assertSame(
                $key,
                Peanut_License_Validator::sanitize_key($key),
                "valid uppercase key mutated by sanitize_key: $key"
            );
            // Lowercasing then sanitizing must reproduce the canonical key.
            $this->assertSame(
                $key,
                Peanut_License_Validator::sanitize_key(strtolower($key)),
                "lowercase round-trip lost the key: $key"
            );
        }
    }

    /**
     * Independent reference: exactly four groups of four [A-Za-z0-9] joined by
     * hyphens. Deliberately implemented WITHOUT the production regex.
     */
    private function referenceValid(string $k): bool {
        $groups = explode('-', $k);
        if (count($groups) !== 4) {
            return false;
        }
        foreach ($groups as $g) {
            if (strlen($g) !== 4) {
                return false;
            }
            if (!ctype_alnum($g)) {
                return false;
            }
        }
        return true;
    }
}
