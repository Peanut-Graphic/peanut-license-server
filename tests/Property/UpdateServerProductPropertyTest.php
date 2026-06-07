<?php
/**
 * Property tests for the pure product-registry helpers (net 6).
 *
 * Peanut_Update_Server::is_valid_product() / ::get_all_products() and the
 * constructor's slug-fallback are pure (array membership over a const map,
 * no WP options, no DB). Invariants asserted across a deterministic corpus:
 *   - is_valid_product(slug) is true IFF slug is a key of get_all_products()
 *   - an unknown slug constructed falls back to the default config; a known
 *     slug yields its own config (constructor never throws / never half-sets)
 *   - every registered product config carries the required keys
 *
 * Pure: no WordPress harness needed. A failure is a real bug.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers Peanut_Update_Server::is_valid_product
 * @covers Peanut_Update_Server::get_all_products
 * @covers Peanut_Update_Server::get_product_config
 */
class UpdateServerProductPropertyTest extends TestCase {

    private const SEED = 70707;

    /**
     * INVARIANT (membership equivalence): is_valid_product(slug) === slug is a
     * key of the registry, for both real keys and a seeded spread of random
     * non-keys.
     *
     * @test
     */
    public function is_valid_product_matches_registry_membership(): void {
        $products = Peanut_Update_Server::get_all_products();
        $this->assertNotEmpty($products);

        // Every real key validates.
        foreach (array_keys($products) as $slug) {
            $this->assertTrue(
                Peanut_Update_Server::is_valid_product($slug),
                "registered slug rejected: $slug"
            );
        }

        // Seeded random non-keys must NOT validate.
        mt_srand(self::SEED);
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789-';
        for ($i = 0; $i < 100; $i++) {
            $slug = '';
            $len = mt_rand(0, 20);
            for ($j = 0; $j < $len; $j++) {
                $slug .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            $expected = array_key_exists($slug, $products);
            $this->assertSame(
                $expected,
                Peanut_Update_Server::is_valid_product($slug),
                "is_valid_product disagreed with registry for " . var_export($slug, true)
            );
        }
    }

    /**
     * INVARIANT (constructor fallback): constructing with an unknown slug
     * yields the DEFAULT product config; constructing with a known slug yields
     * that slug's config. Either way the config is a complete array.
     *
     * @test
     */
    public function constructor_falls_back_for_unknown_slug(): void {
        $products = Peanut_Update_Server::get_all_products();
        $default = (new Peanut_Update_Server('this-slug-does-not-exist'))->get_product_config();
        $this->assertSame($products['peanut-suite'], $default);

        foreach ($products as $slug => $expectedConfig) {
            $config = (new Peanut_Update_Server($slug))->get_product_config();
            $this->assertSame(
                $expectedConfig,
                $config,
                "known slug did not yield its own config: $slug"
            );
        }
    }

    /**
     * INVARIANT (config shape): every registered product carries the keys the
     * update payload depends on, each a non-empty string.
     *
     * @test
     */
    public function every_product_config_has_required_keys(): void {
        $required = ['name', 'slug', 'file', 'author', 'homepage'];
        foreach (Peanut_Update_Server::get_all_products() as $slug => $config) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $config, "product $slug missing key $key");
                $this->assertIsString($config[$key], "product $slug key $key not a string");
                $this->assertNotSame('', $config[$key], "product $slug key $key is empty");
            }
            // The map key and the config's own slug must agree.
            $this->assertSame($slug, $config['slug'], "registry key/slug mismatch for $slug");
        }
    }
}
