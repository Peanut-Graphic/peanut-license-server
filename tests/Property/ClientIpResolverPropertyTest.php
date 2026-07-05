<?php
/**
 * Property / regression tests for the trusted-proxy client-IP resolver.
 *
 * SECURITY REGRESSION (GAP / Cluster G): the client IP used for rate-limiting,
 * IP-blocking, and abuse detection must NOT be spoofable via the attacker-
 * controlled CF-Connecting-IP / X-Forwarded-For / X-Real-IP request headers.
 * These tests lock in the guarantee that forwarded headers are honoured ONLY
 * when the immediate peer (REMOTE_ADDR) is a configured trusted proxy, and are
 * otherwise ignored in favour of REMOTE_ADDR.
 *
 * They exercise the PURE helpers in includes/client-ip-functions.php: no
 * WordPress harness, no DB, no network. The resolver takes an injected $server
 * array and $trusted list so both the trusted and untrusted paths are testable
 * deterministically in a single process. If any assertion fails, an attacker
 * can rotate identity to defeat throttles/bans — do not weaken it.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers ::peanut_get_client_ip
 * @covers ::peanut_is_trusted_proxy
 * @covers ::peanut_ip_in_cidr
 */
class ClientIpResolverPropertyTest extends TestCase {

    private const SEED = 909090;

    /**
     * Spoofed-header attack corpus: the attacker sets each forwarded header to
     * an IP they do not own. Direct peer (REMOTE_ADDR) is NOT a trusted proxy.
     *
     * @return array<int, array{string, array<string,string>}>
     */
    private function spoofCorpus(): array {
        mt_srand(self::SEED);
        $cases = [];
        for ($i = 0; $i < 40; $i++) {
            $remote  = sprintf('198.51.100.%d', mt_rand(1, 254));   // real peer (TEST-NET-2)
            $spoofed = sprintf('10.%d.%d.%d', mt_rand(0, 255), mt_rand(0, 255), mt_rand(1, 254));
            $cases[] = [$remote, [
                'REMOTE_ADDR'           => $remote,
                'HTTP_CF_CONNECTING_IP' => $spoofed,
                'HTTP_X_FORWARDED_FOR'  => $spoofed . ', 203.0.113.9',
                'HTTP_X_REAL_IP'        => $spoofed,
            ]];
        }
        return $cases;
    }

    /**
     * With NO trusted-proxy allowlist, spoofed forwarded headers are IGNORED and
     * the resolver returns the direct peer (REMOTE_ADDR).
     */
    public function test_spoofed_headers_ignored_without_allowlist(): void {
        foreach ($this->spoofCorpus() as [$remote, $server]) {
            $resolved = peanut_get_client_ip([], $server);
            $this->assertSame(
                $remote,
                $resolved,
                'Forwarded headers must be ignored when no proxy is trusted; got ' . $resolved
            );
        }
    }

    /**
     * With an allowlist that does NOT include the direct peer, spoofed headers
     * are still IGNORED (returns REMOTE_ADDR).
     */
    public function test_spoofed_headers_ignored_when_peer_not_trusted(): void {
        $trusted = ['192.0.2.1', '172.16.0.0/12']; // deliberately excludes 198.51.100.x
        foreach ($this->spoofCorpus() as [$remote, $server]) {
            $this->assertSame($remote, peanut_get_client_ip($trusted, $server));
        }
    }

    /**
     * When the direct peer IS a trusted proxy, the forwarded client IP is honoured.
     */
    public function test_forwarded_ip_honoured_behind_trusted_proxy(): void {
        $proxy  = '203.0.113.7';
        $client = '198.51.100.23';
        $server = [
            'REMOTE_ADDR'           => $proxy,
            'HTTP_CF_CONNECTING_IP' => $client,
            'HTTP_X_FORWARDED_FOR'  => $client . ', ' . $proxy,
        ];

        // Exact-IP allowlist.
        $this->assertSame($client, peanut_get_client_ip([$proxy], $server));
        // CIDR allowlist covering the proxy.
        $this->assertSame($client, peanut_get_client_ip(['203.0.113.0/24'], $server));
    }

    /**
     * X-Forwarded-For precedence: CF-Connecting-IP wins, then XFF (left-most),
     * then X-Real-IP — but only behind a trusted proxy.
     */
    public function test_forwarded_precedence_and_leftmost_xff(): void {
        $proxy = '203.0.113.7';

        // Cloudflare header wins when present.
        $this->assertSame('198.51.100.1', peanut_get_client_ip([$proxy], [
            'REMOTE_ADDR'           => $proxy,
            'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
            'HTTP_X_FORWARDED_FOR'  => '198.51.100.2',
            'HTTP_X_REAL_IP'        => '198.51.100.3',
        ]));

        // Left-most XFF entry (original client) is used when CF header absent.
        $this->assertSame('198.51.100.2', peanut_get_client_ip([$proxy], [
            'REMOTE_ADDR'          => $proxy,
            'HTTP_X_FORWARDED_FOR' => '198.51.100.2, 70.1.2.3, ' . $proxy,
        ]));
    }

    /**
     * A trusted proxy that forwards a garbage/invalid header falls back to the
     * proxy's own REMOTE_ADDR rather than returning junk.
     */
    public function test_invalid_forwarded_value_falls_back_to_remote_addr(): void {
        $proxy = '203.0.113.7';
        $this->assertSame($proxy, peanut_get_client_ip([$proxy], [
            'REMOTE_ADDR'           => $proxy,
            'HTTP_CF_CONNECTING_IP' => 'not-an-ip',
            'HTTP_X_FORWARDED_FOR'  => 'also;bogus',
        ]));
    }

    /**
     * Missing / invalid REMOTE_ADDR yields the sentinel and never trusts headers.
     */
    public function test_missing_remote_addr_returns_sentinel(): void {
        $this->assertSame('0.0.0.0', peanut_get_client_ip([], [
            'HTTP_CF_CONNECTING_IP' => '10.0.0.9',
        ]));
        $this->assertSame('0.0.0.0', peanut_get_client_ip(['0.0.0.0'], [
            'REMOTE_ADDR'          => 'garbage',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.9',
        ]));
    }

    /**
     * IPv4 and IPv6 CIDR membership (and non-membership) is computed correctly.
     */
    public function test_ip_in_cidr_v4_and_v6(): void {
        $this->assertTrue(peanut_ip_in_cidr('173.245.48.10', '173.245.48.0/20'));
        $this->assertFalse(peanut_ip_in_cidr('173.245.64.1', '173.245.48.0/20'));
        $this->assertTrue(peanut_ip_in_cidr('192.0.2.5', '192.0.2.5'));   // bare IP
        $this->assertFalse(peanut_ip_in_cidr('192.0.2.6', '192.0.2.5'));
        // IPv6 range (Cloudflare-style).
        $this->assertTrue(peanut_ip_in_cidr('2400:cb00::1', '2400:cb00::/32'));
        $this->assertFalse(peanut_ip_in_cidr('2606:4700::1', '2400:cb00::/32'));
        // Mixed families never match.
        $this->assertFalse(peanut_ip_in_cidr('192.0.2.1', '2400:cb00::/32'));
    }
}
