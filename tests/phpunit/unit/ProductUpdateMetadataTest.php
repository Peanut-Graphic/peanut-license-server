<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductUpdateMetadataTest extends TestCase {
    protected function setUp(): void {
        PeanutTestHelper::clearOptions();
    }

    protected function tearDown(): void {
        PeanutTestHelper::clearOptions();
    }

    public function test_each_product_has_its_own_sections_and_canonical_package(): void {
        foreach (Peanut_Update_Server::PRODUCTS as $slug => $product) {
            PeanutTestHelper::setOption("peanut_{$slug}_version", '1.2.3');
            $info = (new Peanut_Update_Server($slug))->get_plugin_info();
            $this->assertStringContainsString($product['name'], $info['sections']['description'], $slug);
            $this->assertStringContainsString('<code>' . $slug . '</code>', $info['sections']['installation'], $slug);
            $this->assertStringContainsString($product['name'], $info['sections']['faq'], $slug);
            $this->assertStringContainsString($product['name'], $info['sections']['changelog'], $slug);
            $this->assertStringNotContainsString('Initial release', $info['sections']['changelog'], $slug);
            $this->assertSame("https://github.com/peanutgraphic/{$slug}/releases/download/v1.2.3/{$slug}-1.2.3.zip", $info['package']);
            if ($slug !== 'peanut-suite') {
                foreach ($info['sections'] as $section) {
                    $this->assertStringNotContainsString('Peanut Suite', $section, $slug);
                    $this->assertStringNotContainsString('UTM Builder', $section, $slug);
                    $this->assertStringNotContainsString('Pro licenses work on 3 sites', $section, $slug);
                }
            }
        }
    }

    public function test_operator_changelog_and_download_override_are_preserved(): void {
        PeanutTestHelper::setOption('peanut_peanut-festival_changelog', '<h4>Reviewed notes</h4><p>Festival maintenance.</p>');
        PeanutTestHelper::setOption('peanut_peanut-festival_download_url', 'https://cdn.example.com/festival.zip');
        $info = (new Peanut_Update_Server('peanut-festival'))->get_plugin_info();
        $this->assertSame('<h4>Reviewed notes</h4><p>Festival maintenance.</p>', $info['sections']['changelog']);
        $this->assertSame('https://cdn.example.com/festival.zip', $info['package']);
    }

    public function test_fallback_sections_escape_option_values(): void {
        PeanutTestHelper::setOption('peanut_peanut-festival_version', '<script>alert(1)</script>');
        PeanutTestHelper::setOption('peanut_peanut-festival_requires_wp', '<img src=x onerror=alert(1)>');
        PeanutTestHelper::setOption('peanut_peanut-festival_requires_php', '<svg onload=alert(1)>');
        $sections = (new Peanut_Update_Server('peanut-festival'))->get_plugin_info()['sections'];
        $this->assertStringNotContainsString('<script>', $sections['changelog']);
        $this->assertStringContainsString('&lt;script&gt;', $sections['changelog']);
        $this->assertStringContainsString('&lt;img', $sections['installation']);
        $this->assertStringContainsString('&lt;svg', $sections['installation']);
    }
}
