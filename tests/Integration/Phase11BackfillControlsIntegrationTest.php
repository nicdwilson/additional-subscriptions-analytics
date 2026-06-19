<?php
/**
 * Integration tests for Phase 11 subscription analytics backfill controls.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Integration;

use AdditionalSubscriptionsAnalytics\Analytics\BackfillController;
use AdditionalSubscriptionsAnalytics\Database\Installer;
use AdditionalSubscriptionsAnalytics\Database\Migrator;
use AdditionalSubscriptionsAnalytics\Plugin;
use AdditionalSubscriptionsAnalytics\Sync\BackfillScheduler;
use PHPUnit\Framework\TestCase;

if ( ! \class_exists( '\WP_UnitTestCase' ) ) {
	/**
	 * Fallback test case for non-WordPress PHPUnit runs.
	 */
	final class Phase11BackfillControlsIntegrationTest extends TestCase {

		/**
		 * Mark this suite skipped when WordPress test libraries are unavailable.
		 *
		 * @return void
		 */
		public function test_requires_wordpress_test_environment(): void {
			$this->markTestSkipped( 'Phase 11 backfill control tests require the WordPress PHPUnit environment.' );
		}
	}

	return;
}

/**
 * Tests Phase 11 backfill control behavior.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Analytics\BackfillController
 * @covers \AdditionalSubscriptionsAnalytics\Plugin
 * @covers \AdditionalSubscriptionsAnalytics\Sync\BackfillScheduler
 */
final class Phase11BackfillControlsIntegrationTest extends \WP_UnitTestCase {

	/**
	 * Set up lifecycle options.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer() )->install();
		$this->reset_backfill_state();
		$this->register_controller_route();
	}

	/**
	 * Tear down lifecycle state and current user.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		\wp_set_current_user( 0 );
		$this->reset_backfill_state();

		parent::tear_down();
	}

	/**
	 * Test activation queues the first non-destructive backfill.
	 *
	 * @return void
	 */
	public function test_activation_queues_initial_backfill(): void {
		Plugin::activate();

		$this->assertSame( BackfillScheduler::STATUS_QUEUED, \get_option( Migrator::OPTION_BACKFILL_STATUS ) );

		if ( \function_exists( 'as_next_scheduled_action' ) ) {
			$next_action = \as_next_scheduled_action(
				BackfillScheduler::ACTION_INIT,
				array( 'skip_existing' => false ),
				BackfillScheduler::GROUP
			);

			$this->assertNotFalse( $next_action );
		}
	}

	/**
	 * Test activation does not requeue a completed backfill.
	 *
	 * @return void
	 */
	public function test_activation_does_not_requeue_completed_backfill(): void {
		\update_option( Migrator::OPTION_BACKFILL_STATUS, BackfillScheduler::STATUS_COMPLETED );
		\update_option( Migrator::OPTION_BACKFILL_COMPLETED_AT_GMT, '2026-06-20 00:00:00' );

		Plugin::activate();

		$this->assertSame( BackfillScheduler::STATUS_COMPLETED, \get_option( Migrator::OPTION_BACKFILL_STATUS ) );

		if ( \function_exists( 'as_next_scheduled_action' ) ) {
			$next_action = \as_next_scheduled_action(
				BackfillScheduler::ACTION_INIT,
				array( 'skip_existing' => false ),
				BackfillScheduler::GROUP
			);

			$this->assertFalse( $next_action );
		}
	}

	/**
	 * Test the backfill endpoint requires WooCommerce management capability.
	 *
	 * @return void
	 */
	public function test_backfill_endpoint_requires_manage_woocommerce(): void {
		$controller = new BackfillController(
			new BackfillControlsTestScheduler(),
			new BackfillControlsTestStatus()
		);

		\wp_set_current_user( 0 );
		$unauthenticated = $controller->permissions_check( $this->get_request( 'GET' ) );

		\wp_set_current_user( $this->create_subscriber_user() );
		$subscriber = $controller->permissions_check( $this->get_request( 'GET' ) );

		$this->assertInstanceOf( \WP_Error::class, $unauthenticated );
		$this->assertInstanceOf( \WP_Error::class, $subscriber );
		$this->assertSame( 401, $unauthenticated->get_error_data()['status'] );
		$this->assertSame( 403, $subscriber->get_error_data()['status'] );
	}

	/**
	 * Test the endpoint schedules a skip-existing backfill.
	 *
	 * @return void
	 */
	public function test_backfill_endpoint_schedules_missing_data_backfill(): void {
		$scheduler  = new BackfillControlsTestScheduler();
		$controller = new BackfillController( $scheduler, new BackfillControlsTestStatus() );

		\wp_set_current_user( $this->create_manager_user() );

		$response = $controller->create_item( $this->get_request( 'POST', array( 'mode' => 'backfill' ) ) );
		$data     = $response->get_data();

		$this->assertTrue( $scheduler->backfill_scheduled );
		$this->assertFalse( $scheduler->last_skip_existing );
		$this->assertFalse( $scheduler->regeneration_scheduled );
		$this->assertTrue( $data['isActive'] );
		$this->assertFalse( $data['canStart'] );
	}

	/**
	 * Test the endpoint schedules full regeneration for replace mode.
	 *
	 * @return void
	 */
	public function test_backfill_endpoint_schedules_replace_regeneration(): void {
		$scheduler  = new BackfillControlsTestScheduler();
		$controller = new BackfillController( $scheduler, new BackfillControlsTestStatus() );

		\wp_set_current_user( $this->create_manager_user() );

		$response = $controller->create_item( $this->get_request( 'POST', array( 'mode' => 'replace' ) ) );
		$data     = $response->get_data();

		$this->assertTrue( $scheduler->regeneration_scheduled );
		$this->assertFalse( $scheduler->backfill_scheduled );
		$this->assertTrue( $data['isActive'] );
		$this->assertFalse( $data['canStart'] );
	}

	/**
	 * Test the endpoint rejects new work while a run is active.
	 *
	 * @return void
	 */
	public function test_backfill_endpoint_rejects_concurrent_runs(): void {
		$scheduler  = new BackfillControlsTestScheduler();
		$controller = new BackfillController( $scheduler, new BackfillControlsTestStatus() );
		$scheduler->active = true;

		\wp_set_current_user( $this->create_manager_user() );

		$response = $controller->create_item( $this->get_request( 'POST', array( 'mode' => 'replace' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 409, $response->get_error_data()['status'] );
		$this->assertFalse( $scheduler->regeneration_scheduled );
	}

	/**
	 * Register the backfill route if it is not already registered.
	 *
	 * @return void
	 */
	private function register_controller_route(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();

		if ( ! isset( $routes['/wc-analytics/subscription-analytics/backfill'] ) ) {
			( new BackfillController() )->register_routes();
		}
	}

	/**
	 * Build a REST request.
	 *
	 * @param string               $method Request method.
	 * @param array<string, mixed> $params Request params.
	 *
	 * @return \WP_REST_Request
	 */
	private function get_request( string $method, array $params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, '/wc-analytics/subscription-analytics/backfill' );
		$request->set_body_params( $params );

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
	 * Reset backfill lifecycle state.
	 *
	 * @return void
	 */
	private function reset_backfill_state(): void {
		( new BackfillScheduler() )->clear_queued_actions();

		\update_option( Migrator::OPTION_BACKFILL_STATUS, Installer::BACKFILL_STATUS_NOT_STARTED );
		\update_option( Migrator::OPTION_BACKFILL_STARTED_AT_GMT, '' );
		\update_option( Migrator::OPTION_BACKFILL_COMPLETED_AT_GMT, '' );
		\update_option( Migrator::OPTION_LAST_SYNC_AT_GMT, '' );
		\delete_option( BackfillScheduler::OPTION_BACKFILL_FAILURE );
		\delete_option( BackfillScheduler::OPTION_BACKFILL_LAST_PAGE );
	}
}

/**
 * Test backfill scheduler.
 */
final class BackfillControlsTestScheduler {

	/**
	 * Whether the scheduler should report active work.
	 *
	 * @var bool
	 */
	public bool $active = false;

	/**
	 * Whether a backfill was scheduled.
	 *
	 * @var bool
	 */
	public bool $backfill_scheduled = false;

	/**
	 * Whether a regeneration was scheduled.
	 *
	 * @var bool
	 */
	public bool $regeneration_scheduled = false;

	/**
	 * Last skip-existing value.
	 *
	 * @var bool
	 */
	public bool $last_skip_existing = false;

	/**
	 * Schedule a backfill.
	 *
	 * @param bool $skip_existing Whether existing rows should be skipped.
	 *
	 * @return void
	 */
	public function schedule_backfill( bool $skip_existing = true ): void {
		$this->backfill_scheduled = true;
		$this->last_skip_existing = $skip_existing;
		$this->active             = true;
	}

	/**
	 * Schedule regeneration.
	 *
	 * @return void
	 */
	public function schedule_regeneration(): void {
		$this->regeneration_scheduled = true;
		$this->active                 = true;
	}

	/**
	 * Determine whether work is active.
	 *
	 * @return bool
	 */
	public function is_backfill_active(): bool {
		return $this->active;
	}
}

/**
 * Test sync status provider.
 */
final class BackfillControlsTestStatus {

	/**
	 * Get status payload.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		return array(
			'state'          => 'needs_backfill',
			'severity'       => 'warning',
			'message'        => 'Needs backfill.',
			'actionRequired' => true,
			'backfillStatus' => Installer::BACKFILL_STATUS_NOT_STARTED,
			'startedAtGmt'   => '',
			'completedAtGmt' => '',
			'lastPage'       => 0,
		);
	}
}
