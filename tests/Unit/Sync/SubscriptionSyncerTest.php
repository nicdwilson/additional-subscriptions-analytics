<?php
/**
 * Tests for subscription sync persistence coordination.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Unit\Sync;

use AdditionalSubscriptionsAnalytics\Sync\SubscriptionSyncer;
use PHPUnit\Framework\TestCase;

/**
 * Tests subscription sync orchestration.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Sync\SubscriptionSyncer
 */
final class SubscriptionSyncerTest extends TestCase {

	/**
	 * Test syncing a subscription upserts stats and replaces product rows.
	 *
	 * @return void
	 */
	public function test_sync_replaces_product_lookup_rows(): void {
		$subscription = new SyncerTestSubscription( 123 );
		$repository   = new SyncerTestRepository();
		$syncer       = new SubscriptionSyncer(
			$repository,
			new SyncerTestStatsRowBuilder(),
			new SyncerTestProductRowBuilder(),
			new SyncerTestSource( array() )
		);

		$this->assertTrue( $syncer->sync( $subscription ) );

		$this->assertSame( array( 123 ), $repository->upserted_subscription_ids );
		$this->assertSame( array( 123 ), $repository->replaced_subscription_ids );
		$this->assertSame( 1, $repository->replacement_row_counts[0] );
	}

	/**
	 * Test missing source subscriptions delete stale analytics rows.
	 *
	 * @return void
	 */
	public function test_sync_by_id_deletes_rows_when_source_subscription_is_missing(): void {
		$repository = new SyncerTestRepository();
		$syncer     = new SubscriptionSyncer(
			$repository,
			new SyncerTestStatsRowBuilder(),
			new SyncerTestProductRowBuilder(),
			new SyncerTestSource( array() )
		);

		$this->assertFalse( $syncer->sync_by_id( 456 ) );
		$this->assertSame( array( 456 ), $repository->deleted_subscription_ids );
	}

	/**
	 * Test syncing by ID loads a source subscription.
	 *
	 * @return void
	 */
	public function test_sync_by_id_loads_source_subscription(): void {
		$repository = new SyncerTestRepository();
		$syncer     = new SubscriptionSyncer(
			$repository,
			new SyncerTestStatsRowBuilder(),
			new SyncerTestProductRowBuilder(),
			new SyncerTestSource(
				array(
					789 => new SyncerTestSubscription( 789 ),
				)
			)
		);

		$this->assertTrue( $syncer->sync_by_id( 789 ) );
		$this->assertSame( array( 789 ), $repository->upserted_subscription_ids );
	}
}

/**
 * Test subscription object.
 */
final class SyncerTestSubscription {

	/**
	 * Subscription ID.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Constructor.
	 *
	 * @param int $id Subscription ID.
	 */
	public function __construct( int $id ) {
		$this->id = $id;
	}

	/**
	 * Get the subscription ID.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->id;
	}
}

/**
 * Test stats row builder.
 */
final class SyncerTestStatsRowBuilder {

	/**
	 * Build a stats row.
	 *
	 * @param object $subscription Subscription object.
	 *
	 * @return array<string, int|string>
	 */
	public function build( object $subscription ): array {
		return array(
			'subscription_id' => $subscription->get_id(),
			'synced_at_gmt'   => '2026-06-17 00:00:00',
		);
	}
}

/**
 * Test product lookup row builder.
 */
final class SyncerTestProductRowBuilder {

	/**
	 * Build product lookup rows.
	 *
	 * @param object $subscription Subscription object.
	 *
	 * @return array<int, array<string, int|string>>
	 */
	public function build( object $subscription ): array {
		return array(
			array(
				'subscription_id' => $subscription->get_id(),
				'line_item_id'    => 10,
			),
		);
	}
}

/**
 * Test subscription source.
 */
final class SyncerTestSource {

	/**
	 * Subscriptions keyed by ID.
	 *
	 * @var array<int, object>
	 */
	private array $subscriptions;

	/**
	 * Constructor.
	 *
	 * @param array<int, object> $subscriptions Subscriptions keyed by ID.
	 */
	public function __construct( array $subscriptions ) {
		$this->subscriptions = $subscriptions;
	}

	/**
	 * Get a subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return object|null
	 */
	public function get_subscription( int $subscription_id ): ?object {
		return $this->subscriptions[ $subscription_id ] ?? null;
	}
}

/**
 * Test analytics repository.
 */
final class SyncerTestRepository {

	/**
	 * Upserted subscription IDs.
	 *
	 * @var array<int, int>
	 */
	public array $upserted_subscription_ids = array();

	/**
	 * Replacement subscription IDs.
	 *
	 * @var array<int, int>
	 */
	public array $replaced_subscription_ids = array();

	/**
	 * Replacement row counts.
	 *
	 * @var array<int, int>
	 */
	public array $replacement_row_counts = array();

	/**
	 * Deleted subscription IDs.
	 *
	 * @var array<int, int>
	 */
	public array $deleted_subscription_ids = array();

	/**
	 * Upsert stats.
	 *
	 * @param array<string, int|string> $row Stats row.
	 *
	 * @return bool
	 */
	public function upsert_subscription_stats( array $row ): bool {
		$this->upserted_subscription_ids[] = (int) $row['subscription_id'];

		return true;
	}

	/**
	 * Replace product lookup rows.
	 *
	 * @param int                                  $subscription_id Subscription ID.
	 * @param array<int, array<string, int|string>> $rows            Product lookup rows.
	 *
	 * @return int
	 */
	public function replace_product_lookup_rows( int $subscription_id, array $rows ): int {
		$this->replaced_subscription_ids[] = $subscription_id;
		$this->replacement_row_counts[]    = \count( $rows );

		return \count( $rows );
	}

	/**
	 * Delete subscription rows.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return void
	 */
	public function delete_subscription( int $subscription_id ): void {
		$this->deleted_subscription_ids[] = $subscription_id;
	}
}
