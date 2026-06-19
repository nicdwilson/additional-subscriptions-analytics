<?php
/**
 * Integration tests for incremental subscription sync hooks.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Integration;

use AdditionalSubscriptionsAnalytics\Data\SubscriptionAnalyticsRepository;
use AdditionalSubscriptionsAnalytics\Data\TableNames;
	use AdditionalSubscriptionsAnalytics\Database\Installer;
	use AdditionalSubscriptionsAnalytics\Sync\BackfillScheduler;
	use AdditionalSubscriptionsAnalytics\Sync\SyncHooks;
	use PHPUnit\Framework\TestCase;

if ( ! \class_exists( '\WP_UnitTestCase' ) ) {
	/**
	 * Fallback test case for non-WordPress PHPUnit runs.
	 */
	final class SyncHooksIntegrationTest extends TestCase {

		/**
		 * Mark this suite skipped when WordPress test libraries are unavailable.
		 *
		 * @return void
		 */
		public function test_requires_wordpress_test_environment(): void {
			$this->markTestSkipped( 'Sync hook integration tests require the WordPress PHPUnit environment.' );
		}
	}

	return;
}

/**
 * Tests Phase 5 incremental sync behavior against real WooCommerce Subscriptions storage.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Sync\SyncHooks
 * @covers \AdditionalSubscriptionsAnalytics\Sync\SubscriptionSyncer
 */
final class SyncHooksIntegrationTest extends \WP_UnitTestCase {

	/**
	 * Analytics repository.
	 *
	 * @var SubscriptionAnalyticsRepository
	 */
	private SubscriptionAnalyticsRepository $repository;

	/**
	 * Table name helper.
	 *
	 * @var TableNames
	 */
	private TableNames $table_names;

	/**
	 * Incremental sync hooks under test.
	 *
	 * @var SyncHooks
	 */
	private SyncHooks $sync_hooks;

	/**
	 * Set up plugin-owned tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->table_names = new TableNames();
		$this->repository  = new SubscriptionAnalyticsRepository( $this->table_names );
		$this->sync_hooks  = new SyncHooks();

		( new Installer() )->install();
		$this->repository->truncate_tables();
		$this->clear_queued_sync_actions();
	}

	/**
	 * Tear down plugin-owned table data and queued sync actions.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_sync_hook_callbacks();
		$this->clear_queued_sync_actions();
		$this->repository->truncate_tables();

		parent::tear_down();
	}

	/**
	 * Test status lifecycle hooks resync the stats row.
	 *
	 * @return void
	 */
	public function test_status_change_hook_updates_stats_row(): void {
		$this->skip_if_incremental_sync_dependencies_are_unavailable();

		$product      = $this->create_subscription_product( 'Status Coffee Subscription', '25' );
		$subscription = $this->create_real_subscription( $this->create_customer(), $product, 1 );

		$this->sync_subscription_now( $subscription->get_id() );
		$this->clear_queued_sync_actions();
		$this->register_incremental_sync_hooks();

		$subscription->set_status( 'on-hold' );
		$subscription->save();

		\do_action(
			'woocommerce_subscription_status_changed',
			$subscription->get_id(),
			'active',
			'on-hold',
			$subscription
		);

		$this->process_queued_sync( $subscription->get_id() );

		$stats_row = $this->get_stats_row_from_table( $subscription->get_id() );

		$this->assertIsArray( $stats_row );
		$this->assertSame( 'on-hold', $stats_row['status'] );
	}

	/**
	 * Test date lifecycle hooks resync the next payment date.
	 *
	 * @return void
	 */
	public function test_next_payment_date_hook_updates_stats_row(): void {
		$this->skip_if_incremental_sync_dependencies_are_unavailable();

		$product              = $this->create_subscription_product( 'Date Coffee Subscription', '25' );
		$subscription         = $this->create_real_subscription( $this->create_customer(), $product, 1 );
		$next_payment_gmt     = gmdate( 'Y-m-d H:i:s', time() + ( 21 * DAY_IN_SECONDS ) );
		$previous_payment_gmt = $subscription->get_date( 'next_payment', 'gmt' );

		$this->sync_subscription_now( $subscription->get_id() );
		$this->clear_queued_sync_actions();
		$this->register_incremental_sync_hooks();

		$subscription->update_dates(
			array(
				'next_payment' => $next_payment_gmt,
			)
		);
		$subscription->save();

		\do_action(
			'woocommerce_subscription_date_updated',
			$subscription,
			'next_payment',
			$previous_payment_gmt
		);

		$this->process_queued_sync( $subscription->get_id() );

		$stats_row = $this->get_stats_row_from_table( $subscription->get_id() );

		$this->assertIsArray( $stats_row );
		$this->assertSame( $next_payment_gmt, $stats_row['next_payment_date_gmt'] );
	}

	/**
	 * Test renewal completion hooks resync subscription rows.
	 *
	 * @return void
	 */
	public function test_renewal_payment_complete_hook_resyncs_subscription_rows(): void {
		$this->skip_if_incremental_sync_dependencies_are_unavailable();

		$product          = $this->create_subscription_product( 'Renewal Coffee Subscription', '25' );
		$subscription     = $this->create_real_subscription( $this->create_customer(), $product, 1 );
		$next_payment_gmt = gmdate( 'Y-m-d H:i:s', time() + ( 28 * DAY_IN_SECONDS ) );

		$this->sync_subscription_now( $subscription->get_id() );
		$this->clear_queued_sync_actions();
		$this->register_incremental_sync_hooks();

		$subscription->update_dates(
			array(
				'next_payment' => $next_payment_gmt,
			)
		);
		$subscription->save();

		\do_action( 'woocommerce_subscription_renewal_payment_complete', $subscription, null );

		$this->process_queued_sync( $subscription->get_id() );

		$stats_row = $this->get_stats_row_from_table( $subscription->get_id() );

		$this->assertIsArray( $stats_row );
		$this->assertSame( $next_payment_gmt, $stats_row['next_payment_date_gmt'] );
	}

	/**
	 * Test failed renewal hooks resync subscription rows.
	 *
	 * @return void
	 */
	public function test_failed_renewal_hook_resyncs_subscription_rows(): void {
		$this->skip_if_incremental_sync_dependencies_are_unavailable();

		$product      = $this->create_subscription_product( 'Failed Renewal Coffee Subscription', '25' );
		$subscription = $this->create_real_subscription( $this->create_customer(), $product, 1 );

		$this->sync_subscription_now( $subscription->get_id() );
		$this->clear_queued_sync_actions();
		$this->register_incremental_sync_hooks();

		$subscription->set_status( 'on-hold' );
		$subscription->save();

		\do_action( 'woocommerce_subscription_renewal_payment_failed', $subscription, null );

		$this->process_queued_sync( $subscription->get_id() );

		$stats_row = $this->get_stats_row_from_table( $subscription->get_id() );

		$this->assertIsArray( $stats_row );
		$this->assertSame( 'on-hold', $stats_row['status'] );
	}

	/**
	 * Test duplicate lifecycle hooks queue one pending sync action.
	 *
	 * @return void
	 */
	public function test_duplicate_lifecycle_hooks_queue_one_sync_action(): void {
		$this->skip_if_incremental_sync_dependencies_are_unavailable();

		$product      = $this->create_subscription_product( 'Duplicate Event Coffee Subscription', '25' );
		$subscription = $this->create_real_subscription( $this->create_customer(), $product, 1 );

		$this->sync_subscription_now( $subscription->get_id() );
		$this->clear_queued_sync_actions();
		$this->register_incremental_sync_hooks();

		\do_action( 'woocommerce_update_subscription', $subscription->get_id(), $subscription );
		\do_action( 'woocommerce_update_subscription', $subscription->get_id(), $subscription );

		$this->assertCount( 1, $this->get_queued_sync_action_ids( $subscription->get_id() ) );

		$this->process_queued_sync( $subscription->get_id() );
	}

	/**
	 * Test line item mutation hooks resync stats and product lookup rows.
	 *
	 * @return void
	 */
	public function test_line_item_update_hook_updates_product_lookup_rows(): void {
		$this->skip_if_incremental_sync_dependencies_are_unavailable();

		$product      = $this->create_subscription_product( 'Line Item Coffee Subscription', '25' );
		$subscription = $this->create_real_subscription( $this->create_customer(), $product, 1 );

		$this->sync_subscription_now( $subscription->get_id() );
		$this->clear_queued_sync_actions();

		$item = $this->get_first_line_item( $subscription );
		$subscription->remove_item( $item->get_id() );
		$line_item_id = $subscription->add_product( $product, 4 );
		$subscription->calculate_totals();
		$subscription->save();

		$this->refresh_subscription_cache( $subscription->get_id() );
		$subscription = \wcs_get_subscription( $subscription->get_id() );

		$this->assertInstanceOf( \WC_Subscription::class, $subscription );

		$item = $subscription->get_item( $line_item_id );

		$this->assertInstanceOf( \WC_Order_Item_Product::class, $item );
		$this->assertSame( 4, $item->get_quantity() );

		$this->register_incremental_sync_hooks();

		\do_action( 'woocommerce_update_order_item', $item->get_id(), $item, $subscription->get_id() );

		$this->process_queued_sync( $subscription->get_id() );

		$stats_row   = $this->get_stats_row_from_table( $subscription->get_id() );
		$product_row = $this->get_product_row_from_table( $subscription->get_id(), $item->get_id() );

		$this->assertIsArray( $stats_row );
		$this->assertIsArray( $product_row );
		$this->assertSame( '100.00000000', $stats_row['recurring_total'] );
		$this->assertSame( '4.00000000', $product_row['product_qty'] );
		$this->assertSame( '100.00000000', $product_row['line_total'] );
	}

	/**
	 * Test subscription deletion hooks delete analytics rows and pending sync work.
	 *
	 * @return void
	 */
	public function test_delete_hook_removes_subscription_lookup_rows(): void {
		$this->skip_if_incremental_sync_dependencies_are_unavailable();

		$product      = $this->create_subscription_product( 'Delete Coffee Subscription', '25' );
		$subscription = $this->create_real_subscription( $this->create_customer(), $product, 1 );

		$this->sync_subscription_now( $subscription->get_id() );
		$this->clear_queued_sync_actions();
		$this->register_incremental_sync_hooks();

		\do_action( 'woocommerce_update_subscription', $subscription->get_id(), $subscription );
		$this->assertQueuedSyncExists( $subscription->get_id() );

		\do_action( 'woocommerce_before_delete_subscription', $subscription->get_id(), $subscription );

		$this->assertNull( $this->get_stats_row_from_table( $subscription->get_id() ) );
		$this->assertNull( $this->get_product_row_from_table( $subscription->get_id() ) );
		$this->assertQueuedSyncDoesNotExist( $subscription->get_id() );
	}

	/**
	 * Run the plugin's scheduled sync action immediately.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return void
	 */
	private function sync_subscription_now( int $subscription_id ): void {
		$this->sync_hooks->process_sync_action( $subscription_id );
	}

	/**
	 * Process a queued incremental sync and clear its pending Action Scheduler row.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return void
	 */
	private function process_queued_sync( int $subscription_id ): void {
		$this->assertQueuedSyncExists( $subscription_id );
		$this->sync_subscription_now( $subscription_id );

		if ( \function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions(
				SyncHooks::ACTION_SYNC,
				array( 'subscription_id' => $subscription_id ),
				BackfillScheduler::GROUP
			);
		}
	}

	/**
	 * Assert a sync action is queued for a subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return void
	 */
	private function assertQueuedSyncExists( int $subscription_id ): void {
		$this->assertNotEmpty( $this->get_queued_sync_action_ids( $subscription_id ) );
	}

	/**
	 * Assert no sync action is queued for a subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return void
	 */
	private function assertQueuedSyncDoesNotExist( int $subscription_id ): void {
		$this->assertSame( array(), $this->get_queued_sync_action_ids( $subscription_id ) );
	}

	/**
	 * Get pending sync action IDs for a subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return array<int, int>
	 */
	private function get_queued_sync_action_ids( int $subscription_id ): array {
		if ( ! \function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler is unavailable.' );
		}

		return array_map(
			'absint',
			\as_get_scheduled_actions(
				array(
					'hook'   => SyncHooks::ACTION_SYNC,
					'args'   => array( 'subscription_id' => $subscription_id ),
					'group'  => BackfillScheduler::GROUP,
					'status' => 'pending',
				),
				'ids'
			)
		);
	}

	/**
	 * Clear pending incremental sync actions.
	 *
	 * @return void
	 */
	private function clear_queued_sync_actions(): void {
		if ( \function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions( SyncHooks::ACTION_SYNC, null, BackfillScheduler::GROUP );
		}
	}

	/**
	 * Clear order caches so the sync action sees persisted subscription state.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return void
	 */
	private function refresh_subscription_cache( int $subscription_id ): void {
		if ( \function_exists( 'wc_delete_shop_order_transients' ) ) {
			\wc_delete_shop_order_transients( $subscription_id );
		}

		if ( \function_exists( 'clean_post_cache' ) ) {
			\clean_post_cache( $subscription_id );
		}

		if ( \function_exists( 'wp_cache_flush' ) ) {
			\wp_cache_flush();
		}
	}

	/**
	 * Register isolated incremental sync callbacks for the event under test.
	 *
	 * @return void
	 */
	private function register_incremental_sync_hooks(): void {
		$this->remove_sync_hook_callbacks();
		$this->sync_hooks = new SyncHooks();
		$this->sync_hooks->init_hooks();
	}

	/**
	 * Remove the SyncHooks callbacks registered for this test instance.
	 *
	 * @return void
	 */
	private function remove_sync_hook_callbacks(): void {
		\remove_action( SyncHooks::ACTION_SYNC, array( $this->sync_hooks, 'process_sync_action' ), 10 );

		foreach ( array_keys( $this->get_subscription_sync_hooks() ) as $hook ) {
			\remove_action( $hook, array( $this->sync_hooks, 'queue_subscription_sync_from_hook' ), 10 );
		}

		foreach ( array_keys( $this->get_subscription_delete_hooks() ) as $hook ) {
			\remove_action( $hook, array( $this->sync_hooks, 'delete_subscription_rows_from_hook' ), 10 );
		}

		\remove_action( 'woocommerce_new_order_item', array( $this->sync_hooks, 'queue_order_item_sync_from_hook' ), 10 );
		\remove_action( 'woocommerce_update_order_item', array( $this->sync_hooks, 'queue_order_item_sync_from_hook' ), 10 );
		\remove_action( 'woocommerce_before_delete_order_item', array( $this->sync_hooks, 'queue_order_item_sync_from_hook' ), 10 );
		\remove_action( 'woocommerce_delete_order_item', array( $this->sync_hooks, 'queue_order_item_sync_from_hook' ), 10 );
		\remove_action( 'wcs_user_removed_item', array( $this->sync_hooks, 'queue_subscription_item_sync_from_hook' ), 10 );
		\remove_action( 'wcs_user_readded_item', array( $this->sync_hooks, 'queue_subscription_item_sync_from_hook' ), 10 );
		\remove_action( 'woocommerce_subscriptions_switch_completed', array( $this->sync_hooks, 'queue_switch_completed_sync' ), 10 );
		\remove_action( 'woocommerce_subscriptions_switched_item', array( $this->sync_hooks, 'queue_subscription_sync_from_hook' ), 10 );
		\remove_action( 'woocommerce_subscription_item_switched', array( $this->sync_hooks, 'queue_subscription_sync_from_hook' ), 10 );
	}

	/**
	 * Get subscription hooks registered by SyncHooks.
	 *
	 * @return array<string, int>
	 */
	private function get_subscription_sync_hooks(): array {
		return array(
			'woocommerce_new_subscription'                       => 2,
			'woocommerce_update_subscription'                    => 2,
			'woocommerce_subscription_status_changed'            => 4,
			'woocommerce_subscription_date_updated'              => 3,
			'woocommerce_subscription_date_deleted'              => 2,
			'woocommerce_subscription_payment_complete'          => 1,
			'woocommerce_subscription_renewal_payment_complete'  => 2,
			'woocommerce_subscription_payment_failed'            => 2,
			'woocommerce_subscription_renewal_payment_failed'    => 2,
			'woocommerce_before_trash_subscription'              => 2,
			'woocommerce_trash_subscription'                     => 1,
			'woocommerce_subscription_trashed'                   => 1,
		);
	}

	/**
	 * Get deletion hooks registered by SyncHooks.
	 *
	 * @return array<string, int>
	 */
	private function get_subscription_delete_hooks(): array {
		return array(
			'woocommerce_before_delete_subscription' => 2,
			'woocommerce_delete_subscription'        => 1,
			'woocommerce_subscription_deleted'       => 1,
		);
	}

	/**
	 * Skip tests that require real WooCommerce Subscriptions and Action Scheduler.
	 *
	 * @return void
	 */
	private function skip_if_incremental_sync_dependencies_are_unavailable(): void {
		if (
			! \function_exists( 'wcs_create_subscription' )
			|| ! \class_exists( '\WC_Product_Subscription' )
			|| ! \class_exists( '\WC_Subscription' )
			|| ! \function_exists( 'as_next_scheduled_action' )
		) {
			$this->markTestSkipped( 'Real WooCommerce Subscriptions incremental sync dependencies are unavailable.' );
		}
	}

	/**
	 * Create a real subscription product through WooCommerce CRUD.
	 *
	 * @param string $name  Product name.
	 * @param string $price Recurring product price.
	 *
	 * @return \WC_Product_Subscription
	 */
	private function create_subscription_product( string $name, string $price ): \WC_Product_Subscription {
		$product = new \WC_Product_Subscription();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->update_meta_data( '_subscription_price', $price );
		$product->update_meta_data( '_subscription_period', 'month' );
		$product->update_meta_data( '_subscription_period_interval', '1' );
		$product->save();

		return $product;
	}

	/**
	 * Create a customer user for real subscription tests.
	 *
	 * @return int Customer user ID.
	 */
	private function create_customer(): int {
		$login = 'asa_customer_' . \wp_generate_uuid4();

		$customer_id = \wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => \wp_generate_password( 12, false ),
				'user_email' => $login . '@example.test',
				'role'       => 'customer',
			)
		);

		if ( \is_wp_error( $customer_id ) ) {
			$this->fail( $customer_id->get_error_message() );
		}

		return (int) $customer_id;
	}

	/**
	 * Create a real subscription through WooCommerce Subscriptions APIs.
	 *
	 * @param int                      $customer_id Customer user ID.
	 * @param \WC_Product_Subscription $product     Subscription product.
	 * @param int                      $quantity    Subscription line quantity.
	 *
	 * @return \WC_Subscription
	 */
	private function create_real_subscription(
		int $customer_id,
		\WC_Product_Subscription $product,
		int $quantity
	): \WC_Subscription {
		$subscription = \wcs_create_subscription(
			array(
				'status'           => 'active',
				'customer_id'      => $customer_id,
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'start_date'       => \gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);

		if ( \is_wp_error( $subscription ) ) {
			$this->fail( $subscription->get_error_message() );
		}

		$this->assertInstanceOf( \WC_Subscription::class, $subscription );

		$subscription->add_product( $product, $quantity );
		$subscription->set_payment_method( 'manual' );
		$subscription->set_requires_manual_renewal( true );
		$subscription->update_dates(
			array(
				'next_payment' => \gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) ),
			)
		);
		$subscription->calculate_totals();
		$subscription->save();

		return $subscription;
	}

	/**
	 * Get the first line item from a subscription.
	 *
	 * @param \WC_Subscription $subscription Subscription object.
	 *
	 * @return \WC_Order_Item_Product
	 */
	private function get_first_line_item( \WC_Subscription $subscription ): \WC_Order_Item_Product {
		$item = current( $subscription->get_items( 'line_item' ) );

		if ( ! $item instanceof \WC_Order_Item_Product ) {
			$this->fail( 'Expected subscription to have a product line item.' );
		}

		return $item;
	}

	/**
	 * Get a stats row from the stats table.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return array<string, string>|null
	 */
	private function get_stats_row_from_table( int $subscription_id ): ?array {
		global $wpdb;

		$stats_table = $this->table_names->subscriptions_stats();
		$query       = $wpdb->prepare(
			'SELECT * FROM %i WHERE subscription_id = %d LIMIT 1',
			$stats_table,
			$subscription_id
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $query, ARRAY_A );

		return \is_array( $row ) ? $row : null;
	}

	/**
	 * Get a product lookup row from the product lookup table.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return array<string, string>|null
	 */
	private function get_product_row_from_table( int $subscription_id, ?int $line_item_id = null ): ?array {
		global $wpdb;

		$product_lookup_table = $this->table_names->subscription_product_lookup();

		if ( null !== $line_item_id ) {
			$query = $wpdb->prepare(
				'SELECT * FROM %i WHERE subscription_id = %d AND line_item_id = %d LIMIT 1',
				$product_lookup_table,
				$subscription_id,
				$line_item_id
			);
		} else {
			$query = $wpdb->prepare(
				'SELECT * FROM %i WHERE subscription_id = %d LIMIT 1',
				$product_lookup_table,
				$subscription_id
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $query, ARRAY_A );

		return \is_array( $row ) ? $row : null;
	}
}
