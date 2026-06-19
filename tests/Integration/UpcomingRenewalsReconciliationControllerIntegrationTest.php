<?php
/**
 * Integration tests for upcoming renewals reconciliation REST controller.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Integration;

use AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewals\ReconciliationController;
use PHPUnit\Framework\TestCase;

if ( ! \class_exists( '\WP_UnitTestCase' ) ) {
	/**
	 * Fallback test case for non-WordPress PHPUnit runs.
	 */
	final class UpcomingRenewalsReconciliationControllerIntegrationTest extends TestCase {

		/**
		 * Mark this suite skipped when WordPress test libraries are unavailable.
		 *
		 * @return void
		 */
		public function test_requires_wordpress_test_environment(): void {
			$this->markTestSkipped( 'Upcoming renewals reconciliation tests require the WordPress PHPUnit environment.' );
		}
	}

	return;
}

/**
 * Tests Phase 9 reconciliation REST diagnostics.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewals\ReconciliationController
 */
final class UpcomingRenewalsReconciliationControllerIntegrationTest extends \WP_UnitTestCase {

	/**
	 * Tear down current user.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		\wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Test reconciliation diagnostics require WooCommerce management capability.
	 *
	 * @return void
	 */
	public function test_reconciliation_endpoint_requires_manage_woocommerce(): void {
		$controller = new ReconciliationController( new ReconciliationControllerTestReconciler() );

		\wp_set_current_user( 0 );
		$unauthenticated = $controller->get_items_permissions_check( $this->get_request() );

		\wp_set_current_user( $this->create_subscriber_user() );
		$subscriber = $controller->get_items_permissions_check( $this->get_request() );

		$this->assertInstanceOf( \WP_Error::class, $unauthenticated );
		$this->assertInstanceOf( \WP_Error::class, $subscriber );
		$this->assertSame( 401, $unauthenticated->get_error_data()['status'] );
		$this->assertSame( 403, $subscriber->get_error_data()['status'] );
	}

	/**
	 * Test reconciliation endpoint passes the selected report window to diagnostics.
	 *
	 * @return void
	 */
	public function test_reconciliation_endpoint_returns_diagnostic_payload(): void {
		$reconciler = new ReconciliationControllerTestReconciler();
		$controller = new ReconciliationController( $reconciler );

		\wp_set_current_user( $this->create_manager_user() );

		$response = $controller->get_item(
			$this->get_request(
				array(
					'after'  => '2026-07-03',
					'before' => '2026-07-03',
					'status' => 'active',
					'limit'  => 250,
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 'matched', $data['status'] );
		$this->assertSame( '2026-07-03', $reconciler->last_args['after'] );
		$this->assertSame( '2026-07-03', $reconciler->last_args['before'] );
		$this->assertSame( 'active', $reconciler->last_args['status'] );
		$this->assertSame( 250, $reconciler->last_args['limit'] );
	}

	/**
	 * Build a reconciliation REST request.
	 *
	 * @param array<string, mixed> $overrides Query param overrides.
	 *
	 * @return \WP_REST_Request
	 */
	private function get_request( array $overrides = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/wc-analytics/reports/upcoming-renewals/reconcile' );
		$request->set_query_params(
			\array_merge(
				array(
					'after'  => '2026-07-01',
					'before' => '2026-07-31',
					'status' => 'active',
					'limit'  => 5000,
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
}

/**
 * Fake reconciler for REST controller tests.
 */
final class ReconciliationControllerTestReconciler {

	/**
	 * Last arguments passed to reconcile.
	 *
	 * @var array<string, mixed>
	 */
	public array $last_args = array();

	/**
	 * Reconcile test payload.
	 *
	 * @param array<string, mixed> $args Diagnostic args.
	 *
	 * @return array<string, mixed>
	 */
	public function reconcile( array $args ): array {
		$this->last_args = $args;

		return array(
			'status'  => 'matched',
			'summary' => array(
				'mismatchCount'              => 0,
				'sourceSubscriptionsScanned' => 0,
				'sourceSubscriptionsMatched' => 0,
				'lookupRows'                 => 0,
				'sourceRows'                 => 0,
			),
		);
	}
}
