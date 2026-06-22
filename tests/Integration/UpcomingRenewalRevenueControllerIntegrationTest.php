<?php
/**
 * Integration tests for the upcoming renewal revenue Analytics REST controller.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Integration;

use AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewalRevenue\Controller;
use AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewalRevenue\StatsController;
use AdditionalSubscriptionsAnalytics\Data\SubscriptionAnalyticsRepository;
use AdditionalSubscriptionsAnalytics\Data\TableNames;
use AdditionalSubscriptionsAnalytics\Database\Installer;
use PHPUnit\Framework\TestCase;

if ( ! \class_exists( '\WP_UnitTestCase' ) ) {
	/**
	 * Fallback test case for non-WordPress PHPUnit runs.
	 */
	final class UpcomingRenewalRevenueControllerIntegrationTest extends TestCase {

		/**
		 * Mark this suite skipped when WordPress test libraries are unavailable.
		 *
		 * @return void
		 */
		public function test_requires_wordpress_test_environment(): void {
			$this->markTestSkipped( 'Upcoming renewal revenue REST integration tests require the WordPress PHPUnit environment.' );
		}
	}

	return;
}

/**
 * Tests recurring revenue REST API behavior against the WordPress REST server.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewalRevenue\Controller
 * @covers \AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewalRevenue\StatsController
 * @covers \AdditionalSubscriptionsAnalytics\Plugin
 */
final class UpcomingRenewalRevenueControllerIntegrationTest extends \WP_UnitTestCase {

	/**
	 * Analytics repository.
	 *
	 * @var SubscriptionAnalyticsRepository
	 */
	private SubscriptionAnalyticsRepository $repository;

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

		$this->repository = new SubscriptionAnalyticsRepository( new TableNames() );

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
		$this->assertContains( 'upcoming-renewal-revenue', $slugs );
	}

	/**
	 * Test endpoint returns a schema-shaped payload with pagination headers.
	 *
	 * @return void
	 */
	public function test_rest_endpoint_returns_payload_and_pagination_headers(): void {
		$this->seed_subscription( 4001, 'active', '2026-07-01 00:00:00', '20' );
		$this->seed_subscription( 4002, 'active', '2026-07-02 00:00:00', '15' );

		\wp_set_current_user( $this->create_manager_user() );

		$response = \rest_do_request( $this->get_request() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, (int) $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 1, (int) $response->get_headers()['X-WP-TotalPages'] );
		$this->assertCount( 1, $data );
		$this->assertSame( 'month', $data[0]['grouping'] );
		$this->assertSame( 2, $data[0]['renewals_count'] );
		$this->assertSame( 2, $data[0]['subscription_count'] );
		$this->assertSame( 35.0, $data[0]['recurring_total'] );
		$this->assertArrayNotHasKey( 'subscriptions_count', $data[0] );
	}

	/**
	 * Test stats endpoint returns totals and daily interval data for charts.
	 *
	 * @return void
	 */
	public function test_stats_endpoint_returns_totals_and_daily_intervals(): void {
		$this->seed_subscription( 4101, 'active', '2026-07-02 00:00:00', '30', 'day', 2 );

		\wp_set_current_user( $this->create_manager_user() );

		$response = \rest_do_request( $this->get_stats_request() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $data['totals']['renewals_count'] );
		$this->assertSame( 1, $data['totals']['subscriptions_count'] );
		$this->assertSame( '60.00000000', $data['totals']['recurring_total'] );
		$this->assertArrayHasKey( 'intervals', $data );
		$this->assertCount( 4, $data['intervals'] );
		$this->assertSame( 'day', $data['intervals'][0]['interval'] );
		$this->assertSame( 1, $data['intervals'][1]['subtotals']->renewals_count );
		$this->assertSame( '30.00000000', $data['intervals'][1]['subtotals']->recurring_total );
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
	 * Test invalid groupby values are rejected by REST argument validation.
	 *
	 * @return void
	 */
	public function test_rest_endpoint_rejects_invalid_groupby(): void {
		\wp_set_current_user( $this->create_manager_user() );

		$response = \rest_do_request(
			$this->get_request(
				array(
					'groupby' => 'product',
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $data['code'] );
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
				'date_start'         => '2026-07-01 00:00:00',
				'date_end'           => '2026-07-31 23:59:59',
				'grouping'           => 'month',
				'renewals_count'     => 4,
				'subscription_count' => 2,
				'recurring_total'    => 19.999,
			)
		);

		$this->assertSame( 'Period start', $columns['date_start'] );
		$this->assertSame( 'Recurring total', $columns['recurring_total'] );
		$this->assertSame( '2026-07-01 00:00:00', $row['date_start'] );
		$this->assertSame( 'month', $row['grouping'] );
		$this->assertSame( 4, $row['renewals_count'] );
		$this->assertSame( 2, $row['subscription_count'] );
		$this->assertSame( '20.00', $row['recurring_total'] );
	}

	/**
	 * Register the upcoming renewal revenue route if WooCommerce has not already done so.
	 *
	 * @return void
	 */
	private function register_controller_route(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();

		if ( ! isset( $routes['/wc-analytics/reports/upcoming-renewal-revenue'] ) ) {
			( new Controller() )->register_routes();
		}

		if ( ! isset( $routes['/wc-analytics/reports/upcoming-renewal-revenue/stats'] ) ) {
			( new StatsController() )->register_routes();
		}
	}

	/**
	 * Build an upcoming renewal revenue REST request.
	 *
	 * @param array<string, mixed> $overrides Query parameter overrides.
	 *
	 * @return \WP_REST_Request
	 */
	private function get_request( array $overrides = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/wc-analytics/reports/upcoming-renewal-revenue' );
		$request->set_query_params(
			\array_merge(
				array(
					'after'    => '2026-07-01T00:00:00+00:00',
					'before'   => '2026-08-01T00:00:00+00:00',
					'groupby'  => 'month',
					'orderby'  => 'date_start',
					'order'    => 'asc',
					'per_page' => 10,
				),
				$overrides
			)
		);

		return $request;
	}

	/**
	 * Build an upcoming renewal revenue stats REST request.
	 *
	 * @param array<string, mixed> $overrides Query parameter overrides.
	 *
	 * @return \WP_REST_Request
	 */
	private function get_stats_request( array $overrides = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/wc-analytics/reports/upcoming-renewal-revenue/stats' );
		$request->set_query_params(
			\array_merge(
				array(
					'after'    => '2026-07-01T00:00:00+00:00',
					'before'   => '2026-07-05T00:00:00+00:00',
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
	 * Seed a subscription stats row.
	 *
	 * @param int    $subscription_id       Subscription ID.
	 * @param string $status                Subscription status.
	 * @param string $next_payment_date_gmt Next payment date in GMT.
	 * @param string $recurring_total       Recurring total.
	 * @param string $billing_period        Billing period.
	 * @param int    $billing_interval      Billing interval.
	 *
	 * @return void
	 */
	private function seed_subscription(
		int $subscription_id,
		string $status,
		string $next_payment_date_gmt,
		string $recurring_total,
		string $billing_period = 'month',
		int $billing_interval = 1
	): void {
		$this->repository->upsert_subscription_stats(
			array(
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
				'billing_period'           => $billing_period,
				'billing_interval'         => $billing_interval,
				'recurring_total'          => $recurring_total,
				'recurring_tax_total'      => '0.00000000',
				'recurring_shipping_total' => '0.00000000',
				'currency'                 => 'USD',
				'payment_method'           => 'manual',
				'synced_at_gmt'            => '2026-06-17 00:00:00',
			)
		);
	}
}
