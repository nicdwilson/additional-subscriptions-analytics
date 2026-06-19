<?php
/**
 * Tests for subscription analytics backfill scheduling.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Unit\Sync;

use AdditionalSubscriptionsAnalytics\Sync\BackfillScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Tests backfill batch orchestration.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Sync\BackfillScheduler
 */
final class BackfillSchedulerTest extends TestCase {

	/**
	 * Test backfill refreshes existing stats rows when requested.
	 *
	 * @return void
	 */
	public function test_backfill_batch_refreshes_existing_rows(): void {
		$source     = new BackfillTestSource(
			array(
				1 => array( 101, 102 ),
			)
		);
		$syncer     = new BackfillTestSyncer();
		$repository = new BackfillTestRepository( array( 101 ) );
		$scheduler  = new BackfillScheduler( $source, $syncer, $repository );

		$scheduler->process_backfill_batch( 1, true );

		$this->assertSame( array( 101, 102 ), $syncer->synced_subscription_ids );
	}

	/**
	 * Test regeneration does not skip existing stats rows.
	 *
	 * @return void
	 */
	public function test_regeneration_batch_resyncs_existing_rows(): void {
		$source     = new BackfillTestSource(
			array(
				1 => array( 201, 202 ),
			)
		);
		$syncer     = new BackfillTestSyncer();
		$repository = new BackfillTestRepository( array( 201 ) );
		$scheduler  = new BackfillScheduler( $source, $syncer, $repository );

		$scheduler->process_regeneration_batch( 1 );

		$this->assertSame( array( 201, 202 ), $syncer->synced_subscription_ids );
	}

	/**
	 * Test regeneration init truncates analytics tables before scheduling batches.
	 *
	 * @return void
	 */
	public function test_regeneration_init_truncates_tables(): void {
		$repository = new BackfillTestRepository( array() );
		$scheduler  = new BackfillScheduler(
			new BackfillTestSource( array() ),
			new BackfillTestSyncer(),
			$repository
		);

		$scheduler->process_regeneration_init();

		$this->assertTrue( $repository->truncated );
	}
}

/**
 * Test subscription source.
 */
final class BackfillTestSource {

	/**
	 * Subscription IDs keyed by page.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $pages;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<int, int>> $pages Subscription IDs keyed by page.
	 */
	public function __construct( array $pages ) {
		$this->pages = $pages;
	}

	/**
	 * Get subscription IDs.
	 *
	 * @param int $page  Page number.
	 * @param int $limit Page size.
	 *
	 * @return array<int, int>
	 */
	public function get_subscription_ids( int $page, int $limit ): array {
		unset( $limit );

		return $this->pages[ $page ] ?? array();
	}
}

/**
 * Test syncer.
 */
final class BackfillTestSyncer {

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
 * Test analytics repository.
 */
final class BackfillTestRepository {

	/**
	 * Existing stats row IDs.
	 *
	 * @var array<int, int>
	 */
	private array $existing_subscription_ids;

	/**
	 * Whether tables were truncated.
	 *
	 * @var bool
	 */
	public bool $truncated = false;

	/**
	 * Constructor.
	 *
	 * @param array<int, int> $existing_subscription_ids Existing stats row IDs.
	 */
	public function __construct( array $existing_subscription_ids ) {
		$this->existing_subscription_ids = $existing_subscription_ids;
	}

	/**
	 * Check whether a stats row exists.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return bool
	 */
	public function subscription_exists( int $subscription_id ): bool {
		return \in_array( $subscription_id, $this->existing_subscription_ids, true );
	}

	/**
	 * Truncate tables.
	 *
	 * @return void
	 */
	public function truncate_tables(): void {
		$this->truncated = true;
	}
}
