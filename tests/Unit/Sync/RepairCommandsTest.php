<?php
/**
 * Tests for subscription analytics repair commands.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Unit\Sync;

use AdditionalSubscriptionsAnalytics\Sync\RepairCommands;
use PHPUnit\Framework\TestCase;

/**
 * Tests repair operations.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Sync\RepairCommands
 */
final class RepairCommandsTest extends TestCase {

	/**
	 * Test full regeneration delegates to the scheduler.
	 *
	 * @return void
	 */
	public function test_regenerate_schedules_full_regeneration(): void {
		$scheduler = new RepairTestScheduler();
		$commands  = new RepairCommands(
			$scheduler,
			new RepairTestSyncer(),
			new RepairTestRepository( array() )
		);

		$commands->regenerate();

		$this->assertTrue( $scheduler->regeneration_scheduled );
	}

	/**
	 * Test stale row repair resyncs stale subscriptions.
	 *
	 * @return void
	 */
	public function test_repair_stale_rows_resyncs_stale_subscription_ids(): void {
		$syncer     = new RepairTestSyncer();
		$repository = new RepairTestRepository( array( 301, 302 ) );
		$commands   = new RepairCommands(
			new RepairTestScheduler(),
			$syncer,
			$repository
		);

		$this->assertSame( 2, $commands->repair_stale_rows( '2026-06-17 00:00:00', 25 ) );
		$this->assertSame( array( 301, 302 ), $syncer->synced_subscription_ids );
		$this->assertSame( '2026-06-17 00:00:00', $repository->last_before_gmt );
		$this->assertSame( 25, $repository->last_limit );
	}

	/**
	 * Test orphan cleanup delegates to repository.
	 *
	 * @return void
	 */
	public function test_cleanup_orphan_product_lookup_rows(): void {
		$repository = new RepairTestRepository( array() );
		$repository->orphan_rows_deleted = 7;
		$commands = new RepairCommands(
			new RepairTestScheduler(),
			new RepairTestSyncer(),
			$repository
		);

		$this->assertSame( 7, $commands->cleanup_orphan_product_lookup_rows() );
	}

	/**
	 * Test upcoming renewals reconciliation delegates to diagnostics.
	 *
	 * @return void
	 */
	public function test_reconcile_upcoming_renewals_delegates_to_reconciler(): void {
		$reconciler = new RepairTestReconciler();
		$commands   = new RepairCommands(
			new RepairTestScheduler(),
			new RepairTestSyncer(),
			new RepairTestRepository( array() ),
			null,
			$reconciler
		);

		$result = $commands->reconcile_upcoming_renewals(
			array(
				'after'  => '2026-07-03',
				'before' => '2026-07-03',
				'status' => 'active',
			)
		);

		$this->assertSame( 'matched', $result['status'] );
		$this->assertSame( '2026-07-03', $reconciler->last_args['after'] );
		$this->assertSame( '2026-07-03', $reconciler->last_args['before'] );
		$this->assertSame( 'active', $reconciler->last_args['status'] );
	}
}

/**
 * Test scheduler.
 */
final class RepairTestScheduler {

	/**
	 * Whether regeneration was scheduled.
	 *
	 * @var bool
	 */
	public bool $regeneration_scheduled = false;

	/**
	 * Schedule regeneration.
	 *
	 * @return void
	 */
	public function schedule_regeneration(): void {
		$this->regeneration_scheduled = true;
	}
}

/**
 * Test syncer.
 */
final class RepairTestSyncer {

	/**
	 * Synced subscription IDs.
	 *
	 * @var array<int, int>
	 */
	public array $synced_subscription_ids = array();

	/**
	 * Sync a subscription by ID.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return bool
	 */
	public function sync_by_id( int $subscription_id ): bool {
		$this->synced_subscription_ids[] = $subscription_id;

		return true;
	}
}

/**
 * Test repository.
 */
final class RepairTestRepository {

	/**
	 * Stale subscription IDs.
	 *
	 * @var array<int, int>
	 */
	private array $stale_subscription_ids;

	/**
	 * Last stale threshold.
	 *
	 * @var string
	 */
	public string $last_before_gmt = '';

	/**
	 * Last stale query limit.
	 *
	 * @var int
	 */
	public int $last_limit = 0;

	/**
	 * Rows deleted by orphan cleanup.
	 *
	 * @var int
	 */
	public int $orphan_rows_deleted = 0;

	/**
	 * Constructor.
	 *
	 * @param array<int, int> $stale_subscription_ids Stale subscription IDs.
	 */
	public function __construct( array $stale_subscription_ids ) {
		$this->stale_subscription_ids = $stale_subscription_ids;
	}

	/**
	 * Find stale subscription IDs.
	 *
	 * @param string $before_gmt GMT threshold.
	 * @param int    $limit      Query limit.
	 *
	 * @return array<int, int>
	 */
	public function find_stale_subscription_ids( string $before_gmt, int $limit ): array {
		$this->last_before_gmt = $before_gmt;
		$this->last_limit      = $limit;

		return $this->stale_subscription_ids;
	}

	/**
	 * Clean up orphan rows.
	 *
	 * @return int
	 */
	public function cleanup_orphan_product_lookup_rows(): int {
		return $this->orphan_rows_deleted;
	}
}

/**
 * Test reconciler.
 */
final class RepairTestReconciler {

	/**
	 * Last reconciliation args.
	 *
	 * @var array<string, mixed>
	 */
	public array $last_args = array();

	/**
	 * Reconcile upcoming renewal diagnostics.
	 *
	 * @param array<string, mixed> $args Diagnostic args.
	 *
	 * @return array<string, mixed>
	 */
	public function reconcile( array $args ): array {
		$this->last_args = $args;

		return array( 'status' => 'matched' );
	}
}
