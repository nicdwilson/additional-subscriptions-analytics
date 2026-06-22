<?php
/**
 * Integration tests for upcoming renewal revenue report queries.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Integration;

use AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewalRevenue\DataStore;
use AdditionalSubscriptionsAnalytics\Data\DateWindow;
use AdditionalSubscriptionsAnalytics\Data\SubscriptionAnalyticsRepository;
use AdditionalSubscriptionsAnalytics\Data\TableNames;
use AdditionalSubscriptionsAnalytics\Data\UpcomingRenewalRevenueQuery;
use AdditionalSubscriptionsAnalytics\Database\Installer;
use PHPUnit\Framework\TestCase;

if ( ! \class_exists( '\WP_UnitTestCase' ) ) {
	/**
	 * Fallback test case for non-WordPress PHPUnit runs.
	 */
	final class UpcomingRenewalRevenueQueryIntegrationTest extends TestCase {

		/**
		 * Mark this suite skipped when WordPress test libraries are unavailable.
		 *
		 * @return void
		 */
		public function test_requires_wordpress_test_environment(): void {
			$this->markTestSkipped( 'Upcoming renewal revenue integration tests require the WordPress PHPUnit environment.' );
		}
	}

	return;
}

/**
 * Tests recurring revenue report behavior against plugin-owned lookup tables.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Data\UpcomingRenewalRevenueQuery
 * @covers \AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewalRevenue\DataStore
 */
final class UpcomingRenewalRevenueQueryIntegrationTest extends \WP_UnitTestCase {

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
	 * Test monthly subscriptions are counted for each revenue occurrence inside a longer report window.
	 *
	 * @return void
	 */
	public function test_query_counts_recurring_revenue_until_report_or_subscription_end(): void {
		$this->seed_subscription( 3001, 'active', '2026-07-01 00:00:00', '20' );
		$this->seed_subscription(
			3002,
			'active',
			'2026-07-01 00:00:00',
			'10',
			'month',
			1,
			'2026-08-15 00:00:00'
		);

		$results = $this->get_query()->get_data(
			array(
				'after'    => '2026-07-01',
				'before'   => '2026-09-30',
				'groupby'  => 'month',
				'per_page' => 10,
			)
		);

		$this->assertSame( 3, $results['total'] );
		$this->assertSame( 5, $results['totals']['renewals_count'] );
		$this->assertSame( 2, $results['totals']['subscriptions_count'] );
		$this->assertSame( '80.00000000', $results['totals']['recurring_total'] );
		$this->assertSame( '2026-07-01 00:00:00', $results['data'][0]['date_start'] );
		$this->assertSame( 2, $results['data'][0]['renewals_count'] );
		$this->assertSame( 2, $results['data'][0]['subscriptions_count'] );
		$this->assertSame( '30.00000000', $results['data'][0]['recurring_total'] );
		$this->assertSame( '2026-08-01 00:00:00', $results['data'][1]['date_start'] );
		$this->assertSame( '30.00000000', $results['data'][1]['recurring_total'] );
		$this->assertSame( '2026-09-01 00:00:00', $results['data'][2]['date_start'] );
		$this->assertSame( 1, $results['data'][2]['renewals_count'] );
		$this->assertSame( '20.00000000', $results['data'][2]['recurring_total'] );
	}

	/**
	 * Test table grouping can be changed independently of chart stats.
	 *
	 * @return void
	 */
	public function test_query_groups_table_by_selected_period(): void {
		$this->seed_subscription( 3101, 'active', '2026-07-01 00:00:00', '10' );
		$this->seed_subscription( 3102, 'active', '2026-07-02 00:00:00', '25' );

		$daily_results = $this->get_query()->get_data(
			array(
				'after'    => '2026-07-01',
				'before'   => '2026-07-07',
				'groupby'  => 'day',
				'per_page' => 20,
			)
		);
		$week_results  = $this->get_query()->get_data(
			array(
				'after'    => '2026-07-01',
				'before'   => '2026-07-07',
				'groupby'  => 'week',
				'per_page' => 20,
			)
		);

		$this->assertSame( 2, $daily_results['total'] );
		$this->assertSame( 1, $week_results['total'] );
		$this->assertSame( 2, $week_results['data'][0]['renewals_count'] );
		$this->assertSame( '35.00000000', $week_results['data'][0]['recurring_total'] );
	}

	/**
	 * Test stats data returns daily revenue intervals for the chart.
	 *
	 * @return void
	 */
	public function test_stats_data_returns_daily_revenue_intervals(): void {
		$this->seed_subscription( 3201, 'active', '2026-07-02 00:00:00', '45', 'day', 2 );

		$stats = $this->get_query()->get_stats_data(
			array(
				'after'    => '2026-07-01',
				'before'   => '2026-07-05',
				'interval' => 'day',
			)
		);

		$this->assertSame( 2, $stats['totals']['renewals_count'] );
		$this->assertSame( 1, $stats['totals']['subscriptions_count'] );
		$this->assertSame( '90.00000000', $stats['totals']['recurring_total'] );
		$this->assertCount( 5, $stats['intervals'] );
		$this->assertSame( 0, $stats['intervals'][0]['subtotals']['renewals_count'] );
		$this->assertSame( 1, $stats['intervals'][1]['subtotals']['renewals_count'] );
		$this->assertSame( '45.00000000', $stats['intervals'][1]['subtotals']['recurring_total'] );
		$this->assertSame( 1, $stats['intervals'][3]['subtotals']['renewals_count'] );
	}

	/**
	 * Test stats data returns empty totals when no renewals match.
	 *
	 * @return void
	 */
	public function test_stats_data_returns_empty_totals_when_no_renewals_match(): void {
		$stats = $this->get_query()->get_stats_data(
			array(
				'after'    => '2026-07-01',
				'before'   => '2026-07-03',
				'interval' => 'day',
			)
		);

		$this->assertSame( 0, $stats['totals']['renewals_count'] );
		$this->assertSame( 0, $stats['totals']['subscriptions_count'] );
		$this->assertSame( '0.00000000', $stats['totals']['recurring_total'] );
		$this->assertCount( 3, $stats['intervals'] );
	}

	/**
	 * Test active subscriptions are selected by default.
	 *
	 * @return void
	 */
	public function test_query_filters_active_subscriptions_by_default(): void {
		$this->seed_subscription( 3301, 'active', '2026-07-05 00:00:00', '20' );
		$this->seed_subscription( 3302, 'cancelled', '2026-07-06 00:00:00', '40' );

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

		$this->assertSame( '20.00000000', $active_results['totals']['recurring_total'] );
		$this->assertSame( 1, $active_results['totals']['subscriptions_count'] );
		$this->assertSame( '60.00000000', $any_results['totals']['recurring_total'] );
		$this->assertSame( 2, $any_results['totals']['subscriptions_count'] );
	}

	/**
	 * Test advanced status filters override the default active subscription scope.
	 *
	 * @return void
	 */
	public function test_query_supports_advanced_status_filters(): void {
		$this->seed_subscription( 3401, 'active', '2026-07-05 00:00:00', '20' );
		$this->seed_subscription( 3402, 'on-hold', '2026-07-05 00:00:00', '60' );

		$results = $this->get_query()->get_data(
			array(
				'after'     => '2026-07-01',
				'before'    => '2026-07-31',
				'status_is' => array( 'on-hold' ),
			)
		);

		$this->assertSame( 1, $results['total'] );
		$this->assertSame( '60.00000000', $results['data'][0]['recurring_total'] );
		$this->assertSame( 1, $results['data'][0]['subscriptions_count'] );
	}

	/**
	 * Test data store returns the shape expected by WooCommerce Analytics.
	 *
	 * @return void
	 */
	public function test_data_store_returns_generic_controller_shape(): void {
		$this->seed_subscription( 3501, 'active', '2026-07-05 00:00:00', '20' );

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
		$this->assertSame( '20.00000000', $results->data[0]->recurring_total );
	}

	/**
	 * Get a query instance with deterministic UTC report dates.
	 *
	 * @return UpcomingRenewalRevenueQuery
	 */
	private function get_query(): UpcomingRenewalRevenueQuery {
		return new UpcomingRenewalRevenueQuery(
			$this->table_names,
			new DateWindow( new \DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Seed a subscription stats row.
	 *
	 * @param int         $subscription_id       Subscription ID.
	 * @param string      $status                Subscription status.
	 * @param string      $next_payment_date_gmt Next payment date in GMT.
	 * @param string      $recurring_total       Recurring total.
	 * @param string      $billing_period        Billing period.
	 * @param int         $billing_interval      Billing interval.
	 * @param string|null $end_date_gmt          End date in GMT.
	 *
	 * @return void
	 */
	private function seed_subscription(
		int $subscription_id,
		string $status,
		string $next_payment_date_gmt,
		string $recurring_total,
		string $billing_period = 'month',
		int $billing_interval = 1,
		?string $end_date_gmt = null
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
				'end_date_gmt'             => $end_date_gmt,
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
