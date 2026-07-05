<?php
/**
 * Update Download-URL Unification Tests
 *
 * Guards the unified update-delivery contract: the download URL returned by
 * Peanut_Update_Server::get_download_url() — the URL the LEGACY /updates/check
 * and /updates/info responses hand to the peanut-suite / formflow client
 * updaters — must resolve to the SAME canonical GitHub Releases package that
 * the per-product update mu-plugins already serve. Precedence:
 *
 *   1. Explicit operator override (peanut_{slug}_download_url) still wins.
 *   2. Otherwise, when a version is advertised, the canonical GitHub URL:
 *        https://github.com/{repo}/releases/download/v{version}/{slug}-{version}.zip
 *      with {repo} defaulting to peanutgraphic/{slug}, overridable via
 *      peanut_{slug}_github_repo.
 *   3. Otherwise (no advertised version), the legacy signed self-hosted
 *      ?peanut_download URL, retained only as a fallback.
 *
 * Runs on the self-contained mock-WordPress bootstrap (no DB, no real WP).
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers Peanut_Update_Server::get_download_url
 * @covers Peanut_Update_Server::get_github_release_url
 */
class UpdateDownloadUrlUnificationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        PeanutTestHelper::clearOptions();
    }

    protected function tearDown(): void {
        PeanutTestHelper::clearOptions();
        parent::tearDown();
    }

    /**
     * (a) With an advertised version and no overrides, the URL is the canonical
     * GitHub Releases package under the default peanutgraphic/{slug} repo.
     *
     * @test
     */
    public function advertised_version_yields_canonical_github_release_url(): void {
        PeanutTestHelper::setOption('peanut_peanut-suite_version', '4.2.2');

        $server = new Peanut_Update_Server('peanut-suite');

        $this->assertSame(
            'https://github.com/peanutgraphic/peanut-suite/releases/download/v4.2.2/peanut-suite-4.2.2.zip',
            $server->get_download_url()
        );
    }

    /**
     * A normal semver with dots (e.g. 4.0.7) must appear literally in the path —
     * not percent-encoded in a way that mangles it.
     *
     * @test
     */
    public function semver_is_not_url_encoded_into_the_path(): void {
        PeanutTestHelper::setOption('peanut_formflow_version', '4.0.7');

        $server = new Peanut_Update_Server('formflow');

        $url = $server->get_download_url();
        $this->assertSame(
            'https://github.com/peanutgraphic/formflow/releases/download/v4.0.7/formflow-4.0.7.zip',
            $url
        );
        $this->assertStringNotContainsString('%2E', $url);
        $this->assertStringNotContainsString('%2F', $url);
    }

    /**
     * (b) A per-product peanut_{slug}_github_repo override is used in place of
     * the default peanutgraphic/{slug} repo.
     *
     * @test
     */
    public function github_repo_override_is_honoured(): void {
        PeanutTestHelper::setOption('peanut_peanut-suite_version', '4.2.2');
        PeanutTestHelper::setOption('peanut_peanut-suite_github_repo', 'peanutgraphic-labs/suite-fork');

        $server = new Peanut_Update_Server('peanut-suite');

        $this->assertSame(
            'https://github.com/peanutgraphic-labs/suite-fork/releases/download/v4.2.2/peanut-suite-4.2.2.zip',
            $server->get_download_url()
        );
    }

    /**
     * (c) With no advertised version, delivery falls back to the legacy signed
     * self-hosted ?peanut_download URL (never a broken GitHub URL with a "v"
     * tag and no version).
     *
     * @test
     */
    public function empty_version_falls_back_to_signed_self_hosted_url(): void {
        // Explicitly no version advertised.
        PeanutTestHelper::setOption('peanut_peanut-suite_version', '');

        $server = new Peanut_Update_Server('peanut-suite');
        $url = $server->get_download_url('ABCD-EFGH-IJKL-MNOP');

        $this->assertStringContainsString('peanut_download=1', $url);
        $this->assertStringContainsString('plugin=peanut-suite', $url);
        $this->assertStringNotContainsString('github.com', $url);
        // A dangling GitHub tag URL (v with no version) must never be produced.
        $this->assertStringNotContainsString('/releases/download/v/', $url);
    }

    /**
     * (d) The explicit operator override peanut_{slug}_download_url still wins,
     * even when a version is advertised (the GitHub path never overrides it).
     *
     * @test
     */
    public function explicit_download_url_override_wins_over_github(): void {
        PeanutTestHelper::setOption('peanut_peanut-suite_version', '4.2.2');
        PeanutTestHelper::setOption('peanut_peanut-suite_download_url', 'https://cdn.example.com/peanut-suite.zip');

        $server = new Peanut_Update_Server('peanut-suite');

        $this->assertSame(
            'https://cdn.example.com/peanut-suite.zip',
            $server->get_download_url()
        );
    }
}
