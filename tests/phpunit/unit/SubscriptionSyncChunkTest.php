<?php
/**
 * Subscription Sync Chunking Tests
 *
 * The daily fleet sync used to SELECT every subscription_id and foreach over
 * the whole set with no LIMIT — unbounded work that doesn't scale. It must now
 * process a capped chunk per run and advance a cursor across runs so the whole
 * fleet is covered over several runs without one giant pass.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** $wpdb spy that hands back a fixed subscription-id page per (LIMIT/OFFSET). */
class SubSyncSpyWPDB extends MockWPDB {
    /** @var int[] full universe of subscription ids */
    public array $universe = [];
    public ?int $last_limit = null;
    public ?int $last_offset = null;

    public function get_col(?string $query = null, int $x = 0): array {
        $this->last_limit = null;
        $this->last_offset = null;
        if ($query !== null && preg_match('/LIMIT\s+(\d+)/i', $query, $lm)) {
            $this->last_limit = (int) $lm[1];
        }
        if ($query !== null && preg_match('/OFFSET\s+(\d+)/i', $query, $om)) {
            $this->last_offset = (int) $om[1];
        }
        $offset = $this->last_offset ?? 0;
        $limit = $this->last_limit ?? count($this->universe);
        return array_map('strval', array_slice($this->universe, $offset, $limit));
    }
}

/**
 * @covers Peanut_Subscription_Sync::run_scheduled_sync
 */
class SubscriptionSyncChunkTest extends TestCase {

    private SubSyncSpyWPDB $spy;
    private $orig;
    /** @var int[] ids passed to the (stubbed) sync */
    public static array $synced_ids = [];

    protected function setUp(): void {
        parent::setUp();
        PeanutTestHelper::clearOptions();
        global $wpdb;
        $this->orig = $wpdb;
        $this->spy = new SubSyncSpyWPDB();
        $wpdb = $this->spy;
        $GLOBALS['wpdb'] = $this->spy;

        self::$synced_ids = [];
        Peanut_Subscription_Sync::$test_sync_subscription = static function (int $id): array {
            SubscriptionSyncChunkTest::$synced_ids[] = $id;
            return ['success' => true, 'synced' => 1];
        };
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->orig;
        $GLOBALS['wpdb'] = $this->orig;
        Peanut_Subscription_Sync::$test_sync_subscription = null;
        PeanutTestHelper::clearOptions();
        parent::tearDown();
    }

    /**
     * @test
     * A single run processes at most the per-run cap, not the whole fleet.
     */
    public function single_run_is_capped(): void {
        $cap = Peanut_Subscription_Sync::SYNC_CHUNK_SIZE;
        $this->spy->universe = range(1, $cap * 3);

        Peanut_Subscription_Sync::run_scheduled_sync();

        $this->assertLessThanOrEqual($cap, count(self::$synced_ids), 'one run must not exceed the chunk cap');
        $this->assertSame($cap, $this->spy->last_limit, 'the SELECT must carry the chunk LIMIT');
    }

    /**
     * @test
     * The cursor advances across runs and wraps back to the start, covering
     * the whole fleet over multiple runs.
     */
    public function cursor_advances_and_wraps_across_runs(): void {
        $cap = Peanut_Subscription_Sync::SYNC_CHUNK_SIZE;
        $total = $cap * 2 + 1; // needs 3 runs to cover
        $this->spy->universe = range(1, $total);

        $seen = [];
        for ($i = 0; $i < 3; $i++) {
            self::$synced_ids = [];
            Peanut_Subscription_Sync::run_scheduled_sync();
            $seen = array_merge($seen, self::$synced_ids);
        }

        $covered = array_unique($seen);
        sort($covered);
        $this->assertSame(range(1, $total), $covered, 'three runs must cover the whole fleet exactly once');

        // A further run wraps back to the first page.
        self::$synced_ids = [];
        Peanut_Subscription_Sync::run_scheduled_sync();
        $this->assertSame(0, $this->spy->last_offset, 'cursor must wrap to offset 0 after covering the fleet');
    }
}
