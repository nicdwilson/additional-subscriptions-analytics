<?php
/**
 * Tests for incremental sync hooks.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Unit\Sync;

use AdditionalSubscriptionsAnalytics\Sync\SyncHooks;
use PHPUnit\Framework\TestCase;

/**
 * Tests incremental sync hook behavior.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Sync\SyncHooks
 */
final class SyncHooksTest extends TestCase {

	/**
	 * Test duplicate lifecycle events queue one sync action.
	 *
	 * @return void
	 */
	public function test_lifecycle_events_are_debounced_per_subscription(): void {
		$scheduled_actions = array();
		$hooks             = new SyncHooks(
			new SyncHooksTestSyncer(),
			new SyncHooksTestSource(
				array(
					101 => new SyncHooksTestSubscription( 101 ),
				)
			),
			static function ( string $hook, array $args ) use ( &$scheduled_actions ): void {
				$scheduled_actions[] = array(
					'hook' => $hook,
					'args' => $args,
				);
			}
		);

		$hooks->queue_subscription_sync_from_hook( 101 );
		$hooks->queue_subscription_sync_from_hook( 101, new SyncHooksTestSubscription( 101 ) );

		$this->assertSame(
			array(
				array(
					'hook' => SyncHooks::ACTION_SYNC,
					'args' => array( 'subscription_id' => 101 ),
				),
			),
			$scheduled_actions
		);
	}

	/**
	 * Test order item hooks queue the owning subscription.
	 *
	 * @return void
	 */
	public function test_order_item_hooks_queue_owning_subscription(): void {
		$scheduled_actions = array();
		$hooks             = new SyncHooks(
			new SyncHooksTestSyncer(),
			new SyncHooksTestSource(
				array(
					202 => new SyncHooksTestSubscription( 202 ),
				)
			),
			static function ( string $hook, array $args ) use ( &$scheduled_actions ): void {
				$scheduled_actions[] = array(
					'hook' => $hook,
					'args' => $args,
				);
			}
		);

		$hooks->queue_order_item_sync_from_hook( 9001, new SyncHooksTestOrderItem( 202 ) );

		$this->assertSame(
			array(
				array(
					'hook' => SyncHooks::ACTION_SYNC,
					'args' => array( 'subscription_id' => 202 ),
				),
			),
			$scheduled_actions
		);
	}

	/**
	 * Test non-subscription order item hooks do not queue sync.
	 *
	 * @return void
	 */
	public function test_order_item_hooks_ignore_non_subscription_orders(): void {
		$scheduled_actions = array();
		$hooks             = new SyncHooks(
			new SyncHooksTestSyncer(),
			new SyncHooksTestSource( array() ),
			static function ( string $hook, array $args ) use ( &$scheduled_actions ): void {
				$scheduled_actions[] = array(
					'hook' => $hook,
					'args' => $args,
				);
			}
		);

		$hooks->queue_order_item_sync_from_hook( 9002, new SyncHooksTestOrderItem( 303 ) );

		$this->assertSame( array(), $scheduled_actions );
	}

	/**
	 * Test delete hooks remove rows immediately.
	 *
	 * @return void
	 */
	public function test_delete_hooks_remove_subscription_rows_immediately(): void {
		$syncer = new SyncHooksTestSyncer();
		$hooks  = new SyncHooks(
			$syncer,
			new SyncHooksTestSource(
				array(
					404 => new SyncHooksTestSubscription( 404 ),
				)
			)
		);

		$hooks->delete_subscription_rows_from_hook( 404 );

		$this->assertSame( array( 404 ), $syncer->deleted_subscription_ids );
	}

	/**
	 * Test scheduled sync processing delegates to the syncer.
	 *
	 * @return void
	 */
	public function test_process_sync_action_syncs_subscription_by_id(): void {
		$syncer = new SyncHooksTestSyncer();
		$hooks  = new SyncHooks(
			$syncer,
			new SyncHooksTestSource(
				array(
					505 => new SyncHooksTestSubscription( 505 ),
				)
			)
		);

		$hooks->process_sync_action( 505 );

		$this->assertSame( array( 505 ), $syncer->synced_subscription_ids );
	}

	/**
	 * Test subscription item hooks queue the passed subscription.
	 *
	 * @return void
	 */
	public function test_subscription_item_hooks_queue_subscription(): void {
		$scheduled_actions = array();
		$hooks             = new SyncHooks(
			new SyncHooksTestSyncer(),
			new SyncHooksTestSource(
				array(
					606 => new SyncHooksTestSubscription( 606 ),
				)
			),
			static function ( string $hook, array $args ) use ( &$scheduled_actions ): void {
				$scheduled_actions[] = array(
					'hook' => $hook,
					'args' => $args,
				);
			}
		);

		$hooks->queue_subscription_item_sync_from_hook(
			array( 'line_item_id' => 22 ),
			new SyncHooksTestSubscription( 606 )
		);

		$this->assertSame(
			array(
				array(
					'hook' => SyncHooks::ACTION_SYNC,
					'args' => array( 'subscription_id' => 606 ),
				),
			),
			$scheduled_actions
		);
	}
}

/**
 * Test subscription syncer.
 */
final class SyncHooksTestSyncer {

	/**
	 * Synced subscription IDs.
	 *
	 * @var array<int, int>
	 */
	public array $synced_subscription_ids = array();

	/**
	 * Deleted subscription IDs.
	 *
	 * @var array<int, int>
	 */
	public array $deleted_subscription_ids = array();

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

	/**
	 * Delete subscription analytics rows.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return void
	 */
	public function delete( int $subscription_id ): void {
		$this->deleted_subscription_ids[] = $subscription_id;
	}
}

/**
 * Test subscription source.
 */
final class SyncHooksTestSource {

	/**
	 * Subscription objects keyed by ID.
	 *
	 * @var array<int, object>
	 */
	private array $subscriptions;

	/**
	 * Constructor.
	 *
	 * @param array<int, object> $subscriptions Subscription objects keyed by ID.
	 */
	public function __construct( array $subscriptions ) {
		$this->subscriptions = $subscriptions;
	}

	/**
	 * Get a subscription by ID.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return object|null
	 */
	public function get_subscription( int $subscription_id ): ?object {
		return $this->subscriptions[ $subscription_id ] ?? null;
	}

	/**
	 * Check whether an object is a subscription.
	 *
	 * @param object $subscription Candidate subscription object.
	 *
	 * @return bool
	 */
	public function is_subscription( object $subscription ): bool {
		return $subscription instanceof SyncHooksTestSubscription;
	}
}

/**
 * Test subscription object.
 */
final class SyncHooksTestSubscription {

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

	/**
	 * Get the object type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'shop_subscription';
	}
}

/**
 * Test order item object.
 */
final class SyncHooksTestOrderItem {

	/**
	 * Order ID.
	 *
	 * @var int
	 */
	private int $order_id;

	/**
	 * Constructor.
	 *
	 * @param int $order_id Order ID.
	 */
	public function __construct( int $order_id ) {
		$this->order_id = $order_id;
	}

	/**
	 * Get the owning order ID.
	 *
	 * @return int
	 */
	public function get_order_id(): int {
		return $this->order_id;
	}
}
