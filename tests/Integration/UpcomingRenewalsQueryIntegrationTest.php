<?php
/**
 * Integration tests for upcoming renewals report queries.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Integration;

use AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewals\DataStore;
use AdditionalSubscriptionsAnalytics\Data\DateWindow;
use AdditionalSubscriptionsAnalytics\Data\SubscriptionAnalyticsRepository;
use AdditionalSubscriptionsAnalytics\Data\TableNames;
use AdditionalSubscriptionsAnalytics\Data\UpcomingRenewalsQuery;
use AdditionalSubscriptionsAnalytics\Database\Installer;
use AdditionalSubscriptionsAnalytics\Sync\BackfillScheduler;
use PHPUnit\Framework\TestCase;

if ( ! \class_exists( '\WP_UnitTestCase' ) ) {
	/**
	 * Fallback test case for non-WordPress PHPUnit runs.
	 */
	final class UpcomingRenewalsQueryIntegrationTest extends TestCase {

		/**
		 * Mark this suite skipped when WordPress test libraries are unavailable.
		 *
		 * @return void
		 */
		public function test_requires_wordpress_test_environment(): void {
			$this->markTestSkipped( 'Upcoming renewals integration tests require the WordPress PHPUnit environment.' );
		}
	}

	return;
}

/**
 * Tests upcoming renewals report behavior against plugin-owned lookup tables.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Data\UpcomingRenewalsQuery
 * @covers \AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewals\DataStore
 */
final class UpcomingRenewalsQueryIntegrationTest extends \WP_UnitTestCase {

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
	 * Test rows are grouped at product and variation granularity.
	 *
	 * @return void
	 */
	public function test_query_groups_rows_by_product_and_variation(): void {
		$this->seed_subscription( 1001, 'active', '2026-07-05 00:00:00', 10, 0, 'Coffee Club', '2', '20' );
		$this->seed_subscription( 1002, 'active', '2026-07-06 00:00:00', 10, 0, 'Coffee Club', '3', '30' );
		$this->seed_subscription( 1003, 'active', '2026-07-07 00:00:00', 10, 44, 'Coffee Club - Large', '1', '12' );

		$results = $this->get_query()->get_data(
			array(
				'after'    => '2026-07-01',
				'before'   => '2026-07-31',
				'orderby'  => 'variation_id',
				'order'    => 'asc',
				'per_page' => 10,
			)
		);

		$this->assertSame( 2, $results['total'] );
		$this->assertSame( 1, $results['pages'] );
		$this->assertCount( 2, $results['data'] );
		$this->assertSame( 10, $results['data'][0]['product_id'] );
		$this->assertSame( 0, $results['data'][0]['variation_id'] );
		$this->assertSame( '5.00000000', $results['data'][0]['total_quantity'] );
		$this->assertSame( 2, $results['data'][0]['subscriptions_count'] );
		$this->assertSame( '50.00000000', $results['data'][0]['recurring_total'] );
		$this->assertSame( 44, $results['data'][1]['variation_id'] );
		$this->assertSame( '1.00000000', $results['data'][1]['total_quantity'] );
		$this->assertSame( '6.00000000', $results['totals']['total_quantity'] );
		$this->assertSame( 3, $results['totals']['subscriptions_count'] );
		$this->assertSame( '62.00000000', $results['totals']['recurring_total'] );
	}

	/**
	 * Test active subscriptions are selected by default.
	 *
	 * @return void
	 */
	public function test_query_filters_active_subscriptions_by_default(): void {
		$this->seed_subscription( 1101, 'active', '2026-07-05 00:00:00', 11, 0, 'Filtered Club', '1', '20' );
		$this->seed_subscription( 1102, 'cancelled', '2026-07-06 00:00:00', 11, 0, 'Filtered Club', '2', '40' );

		$active_results = $this->get_query()->get_data(
			array(
				'after'  => '2026-07-01',
				'before' => '2026-07-31',
			)
		);
		$any_results    = $this->get_query()->get_data(
			array(
				'after'  => '2026-07-01',
				'before' => '2026-07-31',
				'status' => 'any',
			)
		);
		$empty_results  = $this->get_query()->get_data(
			array(
				'after'  => '2026-07-01',
				'before' => '2026-07-31',
				'status' => '!!!',
			)
		);

		$this->assertSame( '1.00000000', $active_results['data'][0]['total_quantity'] );
		$this->assertSame( 1, $active_results['data'][0]['subscriptions_count'] );
		$this->assertSame( '3.00000000', $any_results['data'][0]['total_quantity'] );
		$this->assertSame( 2, $any_results['data'][0]['subscriptions_count'] );
		$this->assertSame( '1.00000000', $empty_results['data'][0]['total_quantity'] );
		$this->assertSame( 1, $empty_results['data'][0]['subscriptions_count'] );
	}

	/**
	 * Test sorting and pagination are applied to grouped rows.
	 *
	 * @return void
	 */
	public function test_query_sorts_and_paginates_grouped_rows(): void {
		$this->seed_subscription( 1201, 'active', '2026-07-05 00:00:00', 21, 0, 'Small Plan', '3', '30' );
		$this->seed_subscription( 1202, 'active', '2026-07-06 00:00:00', 22, 0, 'Large Plan', '8', '80' );
		$this->seed_subscription( 1203, 'active', '2026-07-07 00:00:00', 23, 0, 'Medium Plan', '5', '50' );

		$results = $this->get_query()->get_data(
			array(
				'after'    => '2026-07-01',
				'before'   => '2026-07-31',
				'orderby'  => 'total_quantity',
				'order'    => 'desc',
				'per_page' => 2,
				'page'     => 2,
			)
		);

		$this->assertSame( 3, $results['total'] );
		$this->assertSame( 2, $results['pages'] );
		$this->assertSame( 2, $results['page_no'] );
		$this->assertCount( 1, $results['data'] );
		$this->assertSame( 21, $results['data'][0]['product_id'] );
		$this->assertSame( '3.00000000', $results['data'][0]['total_quantity'] );
	}

	/**
	 * Test stored product names remain available when products are deleted.
	 *
	 * @return void
	 */
	public function test_query_uses_stored_product_name_for_deleted_products(): void {
		$this->seed_subscription(
			1301,
			'active',
			'2026-07-05 00:00:00',
			9999,
			0,
			'Archived Veg Box',
			'1',
			'20'
		);

		$results = $this->get_query()->get_data(
			array(
				'after'  => '2026-07-01',
				'before' => '2026-07-31',
			)
		);

		$this->assertSame( 9999, $results['data'][0]['product_id'] );
		$this->assertSame( 'Archived Veg Box', $results['data'][0]['product_name'] );
	}

	/**
	 * Test data store returns the shape expected by WooCommerce Analytics.
	 *
	 * @return void
	 */
	public function test_data_store_returns_generic_controller_shape(): void {
		$this->seed_subscription( 1401, 'active', '2026-07-05 00:00:00', 14, 0, 'Controller Club', '1', '20' );

		$data_store = new DataStore( $this->get_query() );
		$results    = $data_store->get_data(
			array(
				'after'  => '2026-07-01',
				'before' => '2026-07-31',
			)
		);

		$this->assertInstanceOf( \stdClass::class, $results );
		$this->assertTrue( \property_exists( $results, 'data' ) );
		$this->assertTrue( \property_exists( $results, 'totals' ) );
		$this->assertTrue( \property_exists( $results, 'page_no' ) );
		$this->assertTrue( \property_exists( $results, 'pages' ) );
		$this->assertTrue( \property_exists( $results, 'total' ) );
		$this->assertContainsOnlyInstancesOf( \stdClass::class, $results->data );
		$this->assertSame( 'Controller Club', $results->data[0]->product_name );
	}

	/**
	 * Test query results reconcile against rows generated from seeded source subscriptions.
	 *
	 * @return void
	 */
	public function test_query_matches_rows_generated_from_seeded_source_subscriptions(): void {
		$source = new UpcomingRenewalsQueryIntegrationSubscriptionSource(
			array(
				1 => array( 1501, 1502 ),
			),
			array(
				1501 => new UpcomingRenewalsQueryIntegrationSubscription( 1501, 'Source Coffee', 2 ),
				1502 => new UpcomingRenewalsQueryIntegrationSubscription( 1502, 'Source Coffee', 3 ),
			)
		);

		$scheduler = new BackfillScheduler( $source, null, $this->repository );
		$scheduler->process_backfill_batch( 1, false );

		$results = $this->get_query()->get_data(
			array(
				'after'  => '2026-07-01',
				'before' => '2026-07-31',
			)
		);

		$this->assertSame( 1, $results['total'] );
		$this->assertSame( 'Source Coffee', $results['data'][0]['product_name'] );
		$this->assertSame( '5.00000000', $results['data'][0]['total_quantity'] );
		$this->assertSame( 2, $results['data'][0]['subscriptions_count'] );
		$this->assertSame( '40.00000000', $results['data'][0]['recurring_total'] );
	}

	/**
	 * Get a query instance with deterministic UTC report dates.
	 *
	 * @return UpcomingRenewalsQuery
	 */
	private function get_query(): UpcomingRenewalsQuery {
		return new UpcomingRenewalsQuery(
			$this->table_names,
			new DateWindow( new \DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Seed a subscription and product lookup row.
	 *
	 * @param int    $subscription_id       Subscription ID.
	 * @param string $status                Subscription status.
	 * @param string $next_payment_date_gmt Next payment date in GMT.
	 * @param int    $product_id            Product ID.
	 * @param int    $variation_id          Variation ID.
	 * @param string $product_name          Product name.
	 * @param string $quantity              Product quantity.
	 * @param string $line_total            Line total.
	 *
	 * @return void
	 */
	private function seed_subscription(
		int $subscription_id,
		string $status,
		string $next_payment_date_gmt,
		int $product_id,
		int $variation_id,
		string $product_name,
		string $quantity,
		string $line_total
	): void {
		$this->repository->upsert_subscription_stats(
			$this->get_stats_row( $subscription_id, $status, $next_payment_date_gmt, $line_total )
		);
		$this->repository->replace_product_lookup_rows(
			$subscription_id,
			array(
				$this->get_product_row(
					$subscription_id,
					$product_id,
					$variation_id,
					$product_name,
					$quantity,
					$line_total
				),
			)
		);
	}

	/**
	 * Build a stats row.
	 *
	 * @param int    $subscription_id       Subscription ID.
	 * @param string $status                Subscription status.
	 * @param string $next_payment_date_gmt Next payment date in GMT.
	 * @param string $recurring_total       Recurring total.
	 *
	 * @return array<string, int|string|null>
	 */
	private function get_stats_row(
		int $subscription_id,
		string $status,
		string $next_payment_date_gmt,
		string $recurring_total
	): array {
		return array(
			'subscription_id'          => $subscription_id,
			'parent_order_id'          => 0,
			'customer_id'              => 1,
			'status'                   => $status,
			'date_created_gmt'         => '2026-06-01 00:00:00',
			'date_updated_gmt'         => '2026-06-01 00:00:00',
			'start_date_gmt'           => '2026-06-01 00:00:00',
			'trial_end_date_gmt'       => null,
			'last_payment_date_gmt'    => null,
			'next_payment_date_gmt'    => $next_payment_date_gmt,
			'end_date_gmt'             => null,
			'billing_period'           => 'month',
			'billing_interval'         => 1,
			'recurring_total'          => $recurring_total,
			'recurring_tax_total'      => '0.00000000',
			'recurring_shipping_total' => '0.00000000',
			'currency'                 => 'USD',
			'payment_method'           => 'manual',
			'synced_at_gmt'            => '2026-06-17 00:00:00',
		);
	}

	/**
	 * Build a product lookup row.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param int    $product_id      Product ID.
	 * @param int    $variation_id    Variation ID.
	 * @param string $product_name    Product name.
	 * @param string $quantity        Product quantity.
	 * @param string $line_total      Line total.
	 *
	 * @return array<string, int|string|null>
	 */
	private function get_product_row(
		int $subscription_id,
		int $product_id,
		int $variation_id,
		string $product_name,
		string $quantity,
		string $line_total
	): array {
		return array(
			'subscription_id' => $subscription_id,
			'line_item_id'    => $subscription_id,
			'product_id'      => $product_id,
			'variation_id'    => $variation_id,
			'product_name'    => $product_name,
			'product_qty'     => $quantity,
			'line_subtotal'   => $line_total,
			'line_total'      => $line_total,
			'line_tax'        => '0.00000000',
			'synced_at_gmt'   => '2026-06-17 00:00:00',
		);
	}
}

/**
 * Test subscription source for report reconciliation.
 */
final class UpcomingRenewalsQueryIntegrationSubscriptionSource {

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
 * Test subscription object for report reconciliation.
 */
final class UpcomingRenewalsQueryIntegrationSubscription {

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
	 * @return array<int, UpcomingRenewalsQueryIntegrationSubscriptionItem>
	 */
	public function get_items( string $type = 'line_item' ): array {
		if ( 'line_item' !== $type ) {
			return array();
		}

		return array(
			$this->subscription_id => new UpcomingRenewalsQueryIntegrationSubscriptionItem(
				$this->subscription_id,
				$this->product_name,
				$this->quantity
			),
		);
	}
}

/**
 * Test subscription line item object for report reconciliation.
 */
final class UpcomingRenewalsQueryIntegrationSubscriptionItem {

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
