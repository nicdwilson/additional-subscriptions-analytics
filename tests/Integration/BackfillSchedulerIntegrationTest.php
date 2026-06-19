<?php
/**
 * Integration tests for backfill and regeneration persistence.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Integration;

use AdditionalSubscriptionsAnalytics\Data\SubscriptionAnalyticsRepository;
use AdditionalSubscriptionsAnalytics\Data\TableNames;
use AdditionalSubscriptionsAnalytics\Database\Installer;
use AdditionalSubscriptionsAnalytics\Sync\BackfillScheduler;
use PHPUnit\Framework\TestCase;

if ( ! \class_exists( '\WP_UnitTestCase' ) ) {
	/**
	 * Fallback test case for non-WordPress PHPUnit runs.
	 */
	final class BackfillSchedulerIntegrationTest extends TestCase {

		/**
		 * Mark this suite skipped when WordPress test libraries are unavailable.
		 *
		 * @return void
		 */
		public function test_requires_wordpress_test_environment(): void {
			$this->markTestSkipped( 'Backfill integration tests require the WordPress PHPUnit environment.' );
		}
	}

	return;
}

/**
 * Tests Phase 4 backfill and regeneration behavior against the WordPress database.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Sync\BackfillScheduler
 * @covers \AdditionalSubscriptionsAnalytics\Data\SubscriptionAnalyticsRepository
 */
final class BackfillSchedulerIntegrationTest extends \WP_UnitTestCase {

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
	 * Set up plugin-owned tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->table_names = new TableNames();
		$this->repository  = new SubscriptionAnalyticsRepository( $this->table_names );

		( new Installer() )->install();
		$this->repository->truncate_tables();
	}

	/**
	 * Tear down plugin-owned table data.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->repository->truncate_tables();

		parent::tear_down();
	}

	/**
	 * Test regeneration batch writes stats and product lookup rows.
	 *
	 * @return void
	 */
	public function test_regeneration_batch_writes_lookup_rows(): void {
		$scheduler = new BackfillScheduler(
			new IntegrationSubscriptionSource(
				array(
					1 => array( 101, 102 ),
				),
				array(
					101 => new IntegrationSubscription( 101, 'Coffee Club', 2 ),
					102 => new IntegrationSubscription( 102, 'Tea Club', 3 ),
				)
			),
			null,
			$this->repository
		);

		$scheduler->process_regeneration_batch( 1 );

		$this->assertSame( 2, $this->get_table_count( $this->table_names->subscriptions_stats() ) );
		$this->assertSame( 2, $this->get_table_count( $this->table_names->subscription_product_lookup() ) );
		$this->assertSame( 'completed', \get_option( 'asa_backfill_status' ) );
	}

	/**
	 * Test backfill refreshes subscriptions that already have stats rows.
	 *
	 * @return void
	 */
	public function test_backfill_batch_refreshes_existing_rows(): void {
		$this->repository->upsert_subscription_stats(
			$this->get_stats_row( 201, '2026-06-01 00:00:00' )
		);
		$this->repository->replace_product_lookup_rows(
			201,
			array(
				$this->get_product_row( 201, 'Existing Product' ),
			)
		);

		$scheduler = new BackfillScheduler(
			new IntegrationSubscriptionSource(
				array(
					1 => array( 201, 202 ),
				),
				array(
					201 => new IntegrationSubscription( 201, 'Should Not Replace', 1 ),
					202 => new IntegrationSubscription( 202, 'New Product', 1 ),
				)
			),
			null,
			$this->repository
		);

		$scheduler->process_backfill_batch( 1, true );

		$this->assertSame( 2, $this->get_table_count( $this->table_names->subscriptions_stats() ) );
		$this->assertSame( 'Should Not Replace', $this->get_product_name( 201 ) );
		$this->assertSame( 'New Product', $this->get_product_name( 202 ) );
	}

	/**
	 * Test missing source subscriptions remove stale lookup-table rows.
	 *
	 * @return void
	 */
	public function test_missing_subscription_removes_lookup_rows(): void {
		$this->repository->upsert_subscription_stats( $this->get_stats_row( 301 ) );
		$this->repository->replace_product_lookup_rows(
			301,
			array(
				$this->get_product_row( 301, 'Missing Product' ),
			)
		);

		$scheduler = new BackfillScheduler(
			new IntegrationSubscriptionSource(
				array(
					1 => array( 301 ),
				),
				array()
			),
			null,
			$this->repository
		);

		$scheduler->process_regeneration_batch( 1 );

		$this->assertSame( 0, $this->get_table_count( $this->table_names->subscriptions_stats() ) );
		$this->assertSame( 0, $this->get_table_count( $this->table_names->subscription_product_lookup() ) );
	}

	/**
	 * Test regeneration init truncates existing analytics rows.
	 *
	 * @return void
	 */
	public function test_regeneration_init_truncates_existing_rows(): void {
		$this->repository->upsert_subscription_stats( $this->get_stats_row( 401 ) );
		$this->repository->replace_product_lookup_rows(
			401,
			array(
				$this->get_product_row( 401, 'Product To Remove' ),
			)
		);

		$scheduler = new BackfillScheduler(
			new IntegrationSubscriptionSource( array(), array() ),
			null,
			$this->repository
		);

		$scheduler->process_regeneration_init();

		$this->assertSame( 0, $this->get_table_count( $this->table_names->subscriptions_stats() ) );
		$this->assertSame( 0, $this->get_table_count( $this->table_names->subscription_product_lookup() ) );
	}

	/**
	 * Test orphan product lookup rows are cleaned up.
	 *
	 * @return void
	 */
	public function test_orphan_product_lookup_cleanup(): void {
		$this->repository->upsert_subscription_stats( $this->get_stats_row( 501 ) );
		$this->repository->replace_product_lookup_rows(
			501,
			array(
				$this->get_product_row( 501, 'Orphan Product' ),
			)
		);

		$this->repository->delete_subscription( 501 );
		$this->repository->replace_product_lookup_rows(
			501,
			array(
				$this->get_product_row( 501, 'Orphan Product' ),
			)
		);

		$this->assertSame( 1, $this->repository->cleanup_orphan_product_lookup_rows() );
		$this->assertSame( 0, $this->get_table_count( $this->table_names->subscription_product_lookup() ) );
	}

	/**
	 * Test default backfill reads real WooCommerce Subscriptions storage.
	 *
	 * @return void
	 */
	public function test_default_backfill_reads_real_woocommerce_subscriptions_storage(): void {
		$this->skip_if_real_subscription_storage_unavailable();

		$product          = $this->create_subscription_product( 'Real Coffee Subscription', '25' );
		$customer_id      = $this->create_customer();
		$next_payment_gmt = gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) );
		$subscription     = $this->create_real_subscription(
			$customer_id,
			$product,
			3,
			$next_payment_gmt
		);

		$scheduler = new BackfillScheduler( null, null, $this->repository );
		$scheduler->process_backfill_batch( 1, false );

		$stats_row  = $this->get_stats_row_from_table( $subscription->get_id() );
		$product_row = $this->get_product_row_from_table( $subscription->get_id() );

		$this->assertIsArray( $stats_row );
		$this->assertIsArray( $product_row );
		$this->assertSame( 'active', $stats_row['status'] );
		$this->assertSame( $next_payment_gmt, $stats_row['next_payment_date_gmt'] );
		$this->assertSame( '75.00000000', $stats_row['recurring_total'] );
		$this->assertSame( (string) $product->get_id(), $product_row['product_id'] );
		$this->assertSame( 'Real Coffee Subscription', $product_row['product_name'] );
		$this->assertSame( '3.00000000', $product_row['product_qty'] );
		$this->assertSame( '75.00000000', $product_row['line_total'] );
	}

	/**
	 * Test default backfill reaches real WooCommerce Subscriptions page two.
	 *
	 * @return void
	 */
	public function test_default_backfill_reads_real_woocommerce_subscriptions_second_page(): void {
		$this->skip_if_real_subscription_storage_unavailable();

		$product             = $this->create_subscription_product( 'Paged Coffee Subscription', '10' );
		$customer_id         = $this->create_customer();
		$subscription_ids    = array();
		$next_payment_gmt    = gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) );
		$subscription_count  = BackfillScheduler::BATCH_SIZE + 1;

		for ( $index = 1; $index <= $subscription_count; ++$index ) {
			$subscription       = $this->create_real_subscription(
				$customer_id,
				$product,
				1,
				$next_payment_gmt
			);
			$subscription_ids[] = $subscription->get_id();
		}

		$last_subscription_id = (int) end( $subscription_ids );
		$scheduler            = new BackfillScheduler( null, null, $this->repository );

		$scheduler->process_backfill_batch( 1, false );
		$scheduler->process_backfill_batch( 2, false );

		foreach ( $subscription_ids as $subscription_id ) {
			$this->assertIsArray( $this->get_stats_row_from_table( (int) $subscription_id ) );
		}

		$this->assertIsArray( $this->get_stats_row_from_table( $last_subscription_id ) );
		$this->assertIsArray( $this->get_product_row_from_table( $last_subscription_id ) );
	}

	/**
	 * Get a table row count.
	 *
	 * @param string $table_name Table name.
	 *
	 * @return int
	 */
	private function get_table_count( string $table_name ): int {
		global $wpdb;

		$query = $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $query );
	}

	/**
	 * Get a product name from the product lookup table.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return string
	 */
	private function get_product_name( int $subscription_id ): string {
		global $wpdb;

		$product_lookup_table = $this->table_names->subscription_product_lookup();
		$query                = $wpdb->prepare(
			'SELECT product_name FROM %i WHERE subscription_id = %d LIMIT 1',
			$product_lookup_table,
			$subscription_id
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $query );
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

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get a product lookup row from the product lookup table.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return array<string, string>|null
	 */
	private function get_product_row_from_table( int $subscription_id ): ?array {
		global $wpdb;

		$product_lookup_table = $this->table_names->subscription_product_lookup();
		$query                = $wpdb->prepare(
			'SELECT * FROM %i WHERE subscription_id = %d LIMIT 1',
			$product_lookup_table,
			$subscription_id
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $query, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Skip tests that require real WooCommerce Subscriptions classes.
	 *
	 * @return void
	 */
	private function skip_if_real_subscription_storage_unavailable(): void {
		if (
			! function_exists( 'wcs_create_subscription' )
			|| ! class_exists( '\WC_Product_Subscription' )
			|| ! class_exists( '\WC_Subscription' )
		) {
			$this->markTestSkipped( 'Real WooCommerce Subscriptions storage is unavailable.' );
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
		$login = 'asa_customer_' . wp_generate_uuid4();

		$customer_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 12, false ),
				'user_email' => $login . '@example.test',
				'role'       => 'customer',
			)
		);

		if ( is_wp_error( $customer_id ) ) {
			$this->fail( $customer_id->get_error_message() );
		}

		return (int) $customer_id;
	}

	/**
	 * Create a real subscription through WooCommerce Subscriptions APIs.
	 *
	 * @param int                      $customer_id      Customer user ID.
	 * @param \WC_Product_Subscription $product          Subscription product.
	 * @param int                      $quantity         Subscription line quantity.
	 * @param string                   $next_payment_gmt Next payment date in GMT.
	 *
	 * @return \WC_Subscription
	 */
	private function create_real_subscription(
		int $customer_id,
		\WC_Product_Subscription $product,
		int $quantity,
		string $next_payment_gmt
	): \WC_Subscription {
		$subscription = wcs_create_subscription(
			array(
				'status'           => 'active',
				'customer_id'      => $customer_id,
				'billing_period'   => 'month',
				'billing_interval' => 1,
				'start_date'       => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);

		if ( is_wp_error( $subscription ) ) {
			$this->fail( $subscription->get_error_message() );
		}

		$this->assertInstanceOf( \WC_Subscription::class, $subscription );

		$subscription->add_product( $product, $quantity );
		$subscription->set_payment_method( 'manual' );
		$subscription->set_requires_manual_renewal( true );
		$subscription->update_dates(
			array(
				'next_payment' => $next_payment_gmt,
			)
		);
		$subscription->calculate_totals();
		$subscription->save();

		return $subscription;
	}

	/**
	 * Build a stats row.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $synced_at_gmt   Sync timestamp.
	 *
	 * @return array<string, int|string|null>
	 */
	private function get_stats_row( int $subscription_id, string $synced_at_gmt = '2026-06-17 00:00:00' ): array {
		return array(
			'subscription_id'          => $subscription_id,
			'parent_order_id'          => 0,
			'customer_id'              => 1,
			'status'                   => 'active',
			'date_created_gmt'         => '2026-06-01 00:00:00',
			'date_updated_gmt'         => '2026-06-01 00:00:00',
			'start_date_gmt'           => '2026-06-01 00:00:00',
			'trial_end_date_gmt'       => null,
			'last_payment_date_gmt'    => null,
			'next_payment_date_gmt'    => '2026-07-01 00:00:00',
			'end_date_gmt'             => null,
			'billing_period'           => 'month',
			'billing_interval'         => 1,
			'recurring_total'          => '20.00000000',
			'recurring_tax_total'      => '0.00000000',
			'recurring_shipping_total' => '0.00000000',
			'currency'                 => 'USD',
			'payment_method'           => 'manual',
			'synced_at_gmt'            => $synced_at_gmt,
		);
	}

	/**
	 * Build a product lookup row.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $product_name    Product name.
	 *
	 * @return array<string, int|string|null>
	 */
	private function get_product_row( int $subscription_id, string $product_name ): array {
		return array(
			'subscription_id' => $subscription_id,
			'line_item_id'    => 1,
			'product_id'      => 10,
			'variation_id'    => 0,
			'product_name'    => $product_name,
			'product_qty'     => '1.00000000',
			'line_subtotal'   => '20.00000000',
			'line_total'      => '20.00000000',
			'line_tax'        => '0.00000000',
			'synced_at_gmt'   => '2026-06-17 00:00:00',
		);
	}
}

/**
 * Integration subscription source.
 */
final class IntegrationSubscriptionSource {

	/**
	 * Subscription IDs keyed by page.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $pages;

	/**
	 * Subscriptions keyed by ID.
	 *
	 * @var array<int, object>
	 */
	private array $subscriptions;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<int, int>> $pages         Subscription IDs keyed by page.
	 * @param array<int, object>          $subscriptions Subscriptions keyed by ID.
	 */
	public function __construct( array $pages, array $subscriptions ) {
		$this->pages         = $pages;
		$this->subscriptions = $subscriptions;
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
 * Integration subscription object.
 */
final class IntegrationSubscription {

	/**
	 * Subscription ID.
	 *
	 * @var int
	 */
	private int $subscription_id;

	/**
	 * Product name.
	 *
	 * @var string
	 */
	private string $product_name;

	/**
	 * Product quantity.
	 *
	 * @var int
	 */
	private int $quantity;

	/**
	 * Constructor.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $product_name    Product name.
	 * @param int    $quantity        Product quantity.
	 */
	public function __construct( int $subscription_id, string $product_name, int $quantity ) {
		$this->subscription_id = $subscription_id;
		$this->product_name    = $product_name;
		$this->quantity        = $quantity;
	}

	/**
	 * Get subscription ID.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->subscription_id;
	}

	/**
	 * Get parent order ID.
	 *
	 * @return int
	 */
	public function get_parent_id(): int {
		return 0;
	}

	/**
	 * Get customer ID.
	 *
	 * @return int
	 */
	public function get_customer_id(): int {
		return 1;
	}

	/**
	 * Get subscription status.
	 *
	 * @return string
	 */
	public function get_status(): string {
		return 'active';
	}

	/**
	 * Get a subscription date.
	 *
	 * @param string $date_type Date type.
	 * @param string $timezone  Timezone.
	 *
	 * @return string|null
	 */
	public function get_date( string $date_type, string $timezone = 'gmt' ): ?string {
		unset( $timezone );

		$dates = array(
			'date_created'            => '2026-06-01 00:00:00',
			'date_modified'           => '2026-06-02 00:00:00',
			'start'                   => '2026-06-01 00:00:00',
			'last_order_date_created' => '2026-06-10 00:00:00',
			'next_payment'            => '2026-07-01 00:00:00',
		);

		return $dates[ $date_type ] ?? null;
	}

	/**
	 * Get billing period.
	 *
	 * @return string
	 */
	public function get_billing_period(): string {
		return 'month';
	}

	/**
	 * Get billing interval.
	 *
	 * @return int
	 */
	public function get_billing_interval(): int {
		return 1;
	}

	/**
	 * Get recurring total.
	 *
	 * @return string
	 */
	public function get_total(): string {
		return '20';
	}

	/**
	 * Get recurring tax total.
	 *
	 * @return string
	 */
	public function get_total_tax(): string {
		return '0';
	}

	/**
	 * Get shipping total.
	 *
	 * @return string
	 */
	public function get_shipping_total(): string {
		return '0';
	}

	/**
	 * Get currency.
	 *
	 * @return string
	 */
	public function get_currency(): string {
		return 'USD';
	}

	/**
	 * Get payment method.
	 *
	 * @return string
	 */
	public function get_payment_method(): string {
		return 'manual';
	}

	/**
	 * Get subscription line items.
	 *
	 * @param string $type Item type.
	 *
	 * @return array<int, IntegrationSubscriptionItem>
	 */
	public function get_items( string $type = 'line_item' ): array {
		if ( 'line_item' !== $type ) {
			return array();
		}

		return array(
			1 => new IntegrationSubscriptionItem( 1, $this->product_name, $this->quantity ),
		);
	}
}

/**
 * Integration subscription line item object.
 */
final class IntegrationSubscriptionItem {

	/**
	 * Line item ID.
	 *
	 * @var int
	 */
	private int $item_id;

	/**
	 * Product name.
	 *
	 * @var string
	 */
	private string $product_name;

	/**
	 * Product quantity.
	 *
	 * @var int
	 */
	private int $quantity;

	/**
	 * Constructor.
	 *
	 * @param int    $item_id      Line item ID.
	 * @param string $product_name Product name.
	 * @param int    $quantity     Product quantity.
	 */
	public function __construct( int $item_id, string $product_name, int $quantity ) {
		$this->item_id      = $item_id;
		$this->product_name = $product_name;
		$this->quantity     = $quantity;
	}

	/**
	 * Get line item ID.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->item_id;
	}

	/**
	 * Get product ID.
	 *
	 * @return int
	 */
	public function get_product_id(): int {
		return 10;
	}

	/**
	 * Get variation ID.
	 *
	 * @return int
	 */
	public function get_variation_id(): int {
		return 0;
	}

	/**
	 * Get product name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->product_name;
	}

	/**
	 * Get quantity.
	 *
	 * @return int
	 */
	public function get_quantity(): int {
		return $this->quantity;
	}

	/**
	 * Get line subtotal.
	 *
	 * @return string
	 */
	public function get_subtotal(): string {
		return '20';
	}

	/**
	 * Get line total.
	 *
	 * @return string
	 */
	public function get_total(): string {
		return '20';
	}

	/**
	 * Get line tax.
	 *
	 * @return string
	 */
	public function get_total_tax(): string {
		return '0';
	}
}
