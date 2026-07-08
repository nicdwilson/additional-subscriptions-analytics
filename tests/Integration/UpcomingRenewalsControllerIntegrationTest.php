<?php
/**
 * Integration tests for the upcoming renewals Analytics REST controller.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Integration;

use AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewals\Controller;
use AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewals\StatsController;
use AdditionalSubscriptionsAnalytics\Data\SubscriptionAnalyticsRepository;
use AdditionalSubscriptionsAnalytics\Data\TableNames;
use AdditionalSubscriptionsAnalytics\Database\Installer;
use PHPUnit\Framework\TestCase;

if ( ! \class_exists( '\WP_UnitTestCase' ) ) {
	/**
	 * Fallback test case for non-WordPress PHPUnit runs.
	 */
	final class UpcomingRenewalsControllerIntegrationTest extends TestCase {

		/**
		 * Mark this suite skipped when WordPress test libraries are unavailable.
		 *
		 * @return void
		 */
		public function test_requires_wordpress_test_environment(): void {
			$this->markTestSkipped( 'Upcoming renewals REST integration tests require the WordPress PHPUnit environment.' );
		}
	}

	return;
}

/**
 * Tests Phase 7 REST API behavior against the WordPress REST server.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewals\Controller
 * @covers \AdditionalSubscriptionsAnalytics\Plugin
 */
final class UpcomingRenewalsControllerIntegrationTest extends \WP_UnitTestCase {

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
	 * Set up plugin-owned tables and REST routes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! \class_exists( '\Automattic\WooCommerce\Admin\API\Reports\GenericController' ) ) {
			$this->markTestSkipped( 'WooCommerce Admin Analytics controllers are unavailable.' );
		}

		$this->table_names = new TableNames();
		$this->repository  = new SubscriptionAnalyticsRepository( $this->table_names );

		( new Installer() )->install();
		$this->repository->truncate_tables();
		$this->register_controller_route();
	}

	/**
	 * Tear down plugin-owned table data and current user.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		\wp_set_current_user( 0 );
		$this->repository->truncate_tables();

		parent::tear_down();
	}

	/**
	 * Test plugin filters register the controller and report index entry.
	 *
	 * @return void
	 */
	public function test_plugin_registers_controller_and_report_index_entry(): void {
		$controllers = \apply_filters( 'woocommerce_admin_rest_controllers', array() );
		$reports     = \apply_filters( 'woocommerce_admin_reports', array() );
		$slugs       = \array_column( $reports, 'slug' );

		$this->assertContains( Controller::class, $controllers );
		$this->assertContains( StatsController::class, $controllers );
		$this->assertContains( 'upcoming-renewals', $slugs );
	}

	/**
	 * Test endpoint returns a schema-shaped payload with pagination headers and product links.
	 *
	 * @return void
	 */
	public function test_rest_endpoint_returns_payload_pagination_headers_and_links(): void {
		$product_id = $this->create_product( 'REST Coffee Subscription' );

		$this->seed_subscription(
			2001,
			'active',
			'2026-07-05 00:00:00',
			$product_id,
			0,
			'REST Coffee Subscription',
			'2',
			'20'
		);
		$this->seed_subscription(
			2002,
			'active',
			'2026-07-06 00:00:00',
			9999,
			0,
			'Archived Tea Subscription',
			'1',
			'12'
		);

		\wp_set_current_user( $this->create_manager_user() );

		$response = \rest_do_request(
			$this->get_request(
				array(
					'per_page' => 1,
					'orderby'  => 'product_name',
					'order'    => 'desc',
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, (int) $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 2, (int) $response->get_headers()['X-WP-TotalPages'] );
		$this->assertCount( 1, $data );
		$this->assertSame( $product_id, $data[0]['product_id'] );
		$this->assertSame( 0, $data[0]['variation_id'] );
		$this->assertSame( 'REST Coffee Subscription', $data[0]['product_name'] );
		$this->assertSame( 2.0, $data[0]['total_qty'] );
		$this->assertSame( 1, $data[0]['subscription_count'] );
		$this->assertSame( 20.0, $data[0]['recurring_total'] );
		$this->assertSame( 'USD', $data[0]['currency'] );
		$this->assertArrayNotHasKey( 'total_quantity', $data[0] );
		$this->assertArrayNotHasKey( 'subscriptions_count', $data[0] );
		$this->assertArrayHasKey( '_links', $data[0] );
		$this->assertArrayHasKey( 'product', $data[0]['_links'] );
		$this->assertArrayHasKey( 'edit', $data[0]['_links'] );
		$this->assertArrayHasKey( 'view', $data[0]['_links'] );
	}

	/**
	 * Test unauthenticated and under-capability requests are rejected.
	 *
	 * @return void
	 */
	public function test_rest_endpoint_rejects_unauthenticated_and_under_capability_requests(): void {
		\wp_set_current_user( 0 );

		$unauthenticated_response = \rest_do_request( $this->get_request() );

		\wp_set_current_user( $this->create_subscriber_user() );

		$subscriber_response = \rest_do_request( $this->get_request() );

		$this->assertSame( 401, $unauthenticated_response->get_status() );
		$this->assertSame( 403, $subscriber_response->get_status() );
	}

	/**
	 * Test invalid orderby values are rejected by REST argument validation.
	 *
	 * @return void
	 */
	public function test_rest_endpoint_rejects_invalid_orderby(): void {
		\wp_set_current_user( $this->create_manager_user() );

		$response = \rest_do_request(
			$this->get_request(
				array(
					'orderby' => 'line_total;drop',
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $data['code'] );
	}

	/**
	 * Test endpoint returns an empty successful response before lookup rows are backfilled.
	 *
	 * @return void
	 */
	public function test_rest_endpoint_returns_empty_success_when_tables_need_backfill(): void {
		\wp_set_current_user( $this->create_manager_user() );

		$response = \rest_do_request( $this->get_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
		$this->assertSame( 0, (int) $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 0, (int) $response->get_headers()['X-WP-TotalPages'] );
	}

	/**
	 * Test stats endpoint returns totals and interval data for charts.
	 *
	 * @return void
	 */
	public function test_stats_endpoint_returns_totals_intervals_and_respects_filters(): void {
		$this->seed_subscription(
			2011,
			'active',
			'2026-07-05 00:00:00',
			81,
			0,
			'Stats Coffee',
			'2',
			'20'
		);
		$this->seed_subscription(
			2012,
			'active',
			'2026-07-06 00:00:00',
			82,
			0,
			'Other Stats Coffee',
			'3',
			'30'
		);
		$this->seed_subscription(
			2013,
			'on-hold',
			'2026-07-06 00:00:00',
			81,
			0,
			'Stats Coffee',
			'4',
			'40'
		);

		\wp_set_current_user( $this->create_manager_user() );

		$response = \rest_do_request(
			$this->get_stats_request(
				array(
					'product_includes' => array( 81 ),
					'interval'         => 'day',
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $data['totals']['renewals_count'] );
		$this->assertSame( '2.00000000', $data['totals']['renewal_quantity'] );
		$this->assertSame( '20.00000000', $data['totals']['recurring_total'] );
		$this->assertArrayHasKey( 'intervals', $data );
		$this->assertCount( 6, $data['intervals'] );
		$this->assertSame( 'day', $data['intervals'][0]['interval'] );
		$this->assertSame( 1, $data['intervals'][4]['subtotals']->renewals_count );
	}

	/**
	 * Test export columns and item mapping.
	 *
	 * @return void
	 */
	public function test_controller_export_columns_and_item_mapping(): void {
		$controller = new Controller();

		$columns = $controller->get_export_columns();
		$row     = $controller->prepare_item_for_export(
			array(
				'product_name'       => 'Export Coffee',
				'product_id'         => 15,
				'variation_id'       => 3,
				'total_qty'          => 2.5,
				'subscription_count' => 4,
				'recurring_total'    => 19.999,
				'currency'           => 'USD',
			)
		);

		$this->assertSame( 'Product title', $columns['product_name'] );
		$this->assertSame( 'SKU', $columns['product_sku'] );
		$this->assertSame( 'Renewal quantity', $columns['total_qty'] );
		$this->assertSame( 'Export Coffee', $row['product_name'] );
		$this->assertArrayHasKey( 'product_sku', $row );
		$this->assertSame( 15, $row['product_id'] );
		$this->assertSame( 3, $row['variation_id'] );
		$this->assertSame( '2.50000000', $row['total_qty'] );
		$this->assertSame( 4, $row['subscription_count'] );
		$this->assertSame( '20.00', $row['recurring_total'] );
		$this->assertSame( 'USD', $row['currency'] );
	}

	/**
	 * Test the export row resolves the current SKU from the live product.
	 *
	 * @return void
	 */
	public function test_controller_export_resolves_current_product_sku(): void {
		$product_id = $this->create_product( 'Export SKU Coffee', 'EXP-COF-01' );
		$controller = new Controller();

		$row = $controller->prepare_item_for_export(
			array(
				'product_name' => 'Export SKU Coffee',
				'product_id'   => $product_id,
				'variation_id' => 0,
			)
		);

		$this->assertSame( 'EXP-COF-01', $row['product_sku'] );
	}

	/**
	 * Test the report row exposes the current product SKU.
	 *
	 * @return void
	 */
	public function test_rest_endpoint_returns_current_product_sku(): void {
		$product_id = $this->create_product( 'SKU Coffee Subscription', 'RET-SKU-01' );

		$this->seed_subscription(
			2101,
			'active',
			'2026-07-05 00:00:00',
			$product_id,
			0,
			'SKU Coffee Subscription',
			'2',
			'20'
		);

		\wp_set_current_user( $this->create_manager_user() );

		$response = \rest_do_request( $this->get_request() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( 'RET-SKU-01', $data[0]['product_sku'] );
	}

	/**
	 * Test the SKU reflects live product edits without re-syncing lookup rows.
	 *
	 * This is the freshness guarantee: the SKU is resolved from the current
	 * product at read time, not snapshotted into the lookup table.
	 *
	 * @return void
	 */
	public function test_rest_endpoint_sku_reflects_live_product_updates(): void {
		$product_id = $this->create_product( 'Fresh SKU Coffee', 'FRESH-SKU-01' );

		$this->seed_subscription(
			2111,
			'active',
			'2026-07-05 00:00:00',
			$product_id,
			0,
			'Fresh SKU Coffee',
			'1',
			'10'
		);

		\wp_set_current_user( $this->create_manager_user() );

		$first = \rest_do_request( $this->get_request() )->get_data();
		$this->assertSame( 'FRESH-SKU-01', $first[0]['product_sku'] );

		// Change the product SKU without touching the lookup table.
		$product = \wc_get_product( $product_id );
		$product->set_sku( 'FRESH-SKU-02' );
		$product->save();

		$second = \rest_do_request( $this->get_request() )->get_data();
		$this->assertSame( 'FRESH-SKU-02', $second[0]['product_sku'] );
	}

	/**
	 * Test a deleted product yields a blank SKU while the name snapshot survives.
	 *
	 * @return void
	 */
	public function test_rest_endpoint_returns_blank_sku_for_deleted_product(): void {
		$this->seed_subscription(
			2121,
			'active',
			'2026-07-05 00:00:00',
			424242,
			0,
			'Archived SKU Subscription',
			'1',
			'10'
		);

		\wp_set_current_user( $this->create_manager_user() );

		$data = \rest_do_request( $this->get_request() )->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'Archived SKU Subscription', $data[0]['product_name'] );
		$this->assertSame( '', $data[0]['product_sku'] );
	}

	/**
	 * Test the report item schema advertises the product SKU property.
	 *
	 * @return void
	 */
	public function test_controller_schema_advertises_product_sku(): void {
		$schema = ( new Controller() )->get_item_schema();

		$this->assertArrayHasKey( 'product_sku', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['product_sku']['type'] );
	}

	/**
	 * Register the upcoming renewals route if WooCommerce has not already done so.
	 *
	 * @return void
	 */
	private function register_controller_route(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();

		if ( ! isset( $routes['/wc-analytics/reports/upcoming-renewals'] ) ) {
			( new Controller() )->register_routes();
		}

		if ( ! isset( $routes['/wc-analytics/reports/upcoming-renewals/stats'] ) ) {
			( new StatsController() )->register_routes();
		}
	}

	/**
	 * Build an upcoming renewals REST request.
	 *
	 * @param array<string, mixed> $overrides Query parameter overrides.
	 *
	 * @return \WP_REST_Request
	 */
	private function get_request( array $overrides = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/wc-analytics/reports/upcoming-renewals' );
		$request->set_query_params(
			\array_merge(
				array(
					'after'    => '2026-07-01T00:00:00+00:00',
					'before'   => '2026-08-01T00:00:00+00:00',
					'orderby'  => 'product_name',
					'order'    => 'asc',
					'per_page' => 10,
				),
				$overrides
			)
		);

		return $request;
	}

	/**
	 * Build an upcoming renewals stats REST request.
	 *
	 * @param array<string, mixed> $overrides Query parameter overrides.
	 *
	 * @return \WP_REST_Request
	 */
	private function get_stats_request( array $overrides = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/wc-analytics/reports/upcoming-renewals/stats' );
		$request->set_query_params(
			\array_merge(
				array(
					'after'    => '2026-07-01T00:00:00+00:00',
					'before'   => '2026-07-07T00:00:00+00:00',
					'interval' => 'day',
					'per_page' => 100,
				),
				$overrides
			)
		);

		return $request;
	}

	/**
	 * Create a manager-capable user.
	 *
	 * @return int User ID.
	 */
	private function create_manager_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = \get_user_by( 'id', $user_id );

		if ( $user instanceof \WP_User ) {
			$user->add_cap( 'manage_woocommerce' );
		}

		return (int) $user_id;
	}

	/**
	 * Create a subscriber user with no WooCommerce management capability.
	 *
	 * @return int User ID.
	 */
	private function create_subscriber_user(): int {
		return (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Create a simple WooCommerce product.
	 *
	 * @param string $name Product name.
	 * @param string $sku  Optional product SKU.
	 *
	 * @return int Product ID.
	 */
	private function create_product( string $name, string $sku = '' ): int {
		if ( ! \class_exists( '\WC_Product_Simple' ) ) {
			$this->markTestSkipped( 'WooCommerce product CRUD is unavailable.' );
		}

		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '10' );
		$product->set_price( '10' );

		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}

		$product->save();

		return (int) $product->get_id();
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
