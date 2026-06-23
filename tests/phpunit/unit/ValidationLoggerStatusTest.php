<?php
/**
 * Validation Logger Status-Clamp Tests
 *
 * The status column is ENUM('success','failed'); inserting any other value
 * (e.g. the old `?? 'unknown'` default) errors under MySQL STRICT mode on the
 * per-validation hot path. The logger must clamp to a valid enum member.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** $wpdb spy that records the row handed to insert(). */
class ValidationLoggerSpyWPDB extends MockWPDB {
    public array $last_insert = [];
    public function insert(string $table, array $data, $format = null): bool {
        $this->last_insert = $data;
        $this->insert_id = 1;
        return true;
    }
}

/**
 * @covers Peanut_Validation_Logger
 */
class ValidationLoggerStatusTest extends TestCase {

    private ValidationLoggerSpyWPDB $spy;
    private $orig;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->orig = $wpdb;
        $this->spy = new ValidationLoggerSpyWPDB();
        $wpdb = $this->spy;
        $GLOBALS['wpdb'] = $this->spy;
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->orig;
        $GLOBALS['wpdb'] = $this->orig;
        parent::tearDown();
    }

    /**
     * @test
     * An unknown status (or none) must NOT reach the ENUM as 'unknown'.
     */
    public function unknown_status_is_clamped_to_a_valid_enum_member(): void {
        Peanut_Validation_Logger::log([
            'license_key' => 'AAAA-BBBB-CCCC-DDDD',
            'site_url' => 'https://example.com',
            'action' => 'validate',
            // no 'status' -> old code would insert 'unknown'
        ]);

        $this->assertContains(
            $this->spy->last_insert['status'],
            ['success', 'failed'],
            'status written to ENUM column must be a valid member'
        );
    }

    /**
     * @test
     * A bogus status value is also clamped, not passed through.
     */
    public function bogus_status_is_clamped(): void {
        Peanut_Validation_Logger::log([
            'license_key' => 'AAAA-BBBB-CCCC-DDDD',
            'site_url' => 'https://example.com',
            'status' => 'banana',
        ]);

        $this->assertContains($this->spy->last_insert['status'], ['success', 'failed']);
    }

    /**
     * @test
     * Valid values pass through unchanged.
     */
    public function valid_status_passes_through(): void {
        Peanut_Validation_Logger::log(['status' => 'success']);
        $this->assertSame('success', $this->spy->last_insert['status']);

        Peanut_Validation_Logger::log(['status' => 'failed']);
        $this->assertSame('failed', $this->spy->last_insert['status']);
    }
}
