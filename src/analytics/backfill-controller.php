<?php
/**
 * WooCommerce Analytics REST controller for subscription analytics backfill.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.9.1
 */

namespace AdditionalSubscriptionsAnalytics\Analytics;

use AdditionalSubscriptionsAnalytics\Admin\SyncStatus;
use AdditionalSubscriptionsAnalytics\Sync\BackfillScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Serves subscription analytics backfill status and reimport actions.
 *
 * @since 0.9.1
 */
final class BackfillController {

	/**
	 * REST namespace.
	 *
	 * @since 0.9.1
	 */
	private const REST_NAMESPACE = 'wc-analytics';

	/**
	 * REST route base.
	 *
	 * @since 0.9.1
	 */
	private const REST_BASE = 'subscription-analytics/backfill';

	/**
	 * Non-destructive backfill mode.
	 *
	 * @since 0.9.1
	 */
	private const MODE_BACKFILL = 'backfill';

	/**
	 * Destructive replace mode.
	 *
	 * @since 0.9.1
	 */
	private const MODE_REPLACE = 'replace';

	/**
	 * Backfill scheduler.
	 *
	 * @var object
	 */
	private object $scheduler;

	/**
	 * Sync status provider.
	 *
	 * @var object
	 */
	private object $sync_status;

	/**
	 * Constructor.
	 *
	 * @since 0.9.1
	 *
	 * @param object|null $scheduler   Optional backfill scheduler.
	 * @param object|null $sync_status Optional sync status provider.
	 */
	public function __construct( ?object $scheduler = null, ?object $sync_status = null ) {
		$this->scheduler   = $scheduler ?? new BackfillScheduler();
		$this->sync_status = $sync_status ?? new SyncStatus();
	}

	/**
	 * Register REST hooks.
	 *
	 * @since 0.9.1
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		\add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register backfill routes.
	 *
	 * @since 0.9.1
	 *
	 * @return void
	 */
	public function register_routes(): void {
		\register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);
	}

	/**
	 * Get endpoint args.
	 *
	 * @since 0.9.1
	 *
	 * @return array<string, mixed>
	 */
	public function get_endpoint_args(): array {
		return array(
			'mode' => array(
				'description'       => __(
					'Backfill mode. Use backfill to preserve existing rows or replace to rebuild from an empty lookup table.',
					'additional-subscriptions-analytics'
				),
				'type'              => 'string',
				'enum'              => array( self::MODE_BACKFILL, self::MODE_REPLACE ),
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Check whether the request can manage subscription analytics backfill.
	 *
	 * @since 0.9.1
	 *
	 * @param \WP_REST_Request $request Full request object.
	 *
	 * @return \WP_Error|bool True when access is allowed.
	 */
	public function permissions_check( $request ) {
		unset( $request );

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce core capability.
		if ( ! \current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error(
				'woocommerce_rest_cannot_manage',
				__( 'Sorry, you cannot manage subscription analytics backfill.', 'additional-subscriptions-analytics' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Get current backfill status.
	 *
	 * @since 0.9.1
	 *
	 * @param \WP_REST_Request $request Full request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_item( $request ) {
		unset( $request );

		return \rest_ensure_response( $this->get_response_payload() );
	}

	/**
	 * Schedule a backfill or full replacement run.
	 *
	 * @since 0.9.1
	 *
	 * @param \WP_REST_Request $request Full request object.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function create_item( $request ) {
		if ( $this->is_backfill_active() ) {
			return new \WP_Error(
				'asa_backfill_in_progress',
				__( 'Subscription analytics backfill is already queued or running.', 'additional-subscriptions-analytics' ),
				array( 'status' => 409 )
			);
		}

		$mode = (string) $request->get_param( 'mode' );

		if ( self::MODE_REPLACE === $mode ) {
			$this->schedule_regeneration();
			$message = __(
				'Subscription analytics data replacement has been scheduled.',
				'additional-subscriptions-analytics'
			);
		} else {
			$this->schedule_backfill();
			$message = __(
				'Subscription analytics backfill has been scheduled.',
				'additional-subscriptions-analytics'
			);
		}

		$payload            = $this->get_response_payload();
		$payload['message'] = $message;

		return \rest_ensure_response( $payload );
	}

	/**
	 * Get the response payload.
	 *
	 * @since 0.9.1
	 *
	 * @return array<string, mixed>
	 */
	private function get_response_payload(): array {
		$status = $this->get_status();
		$active = $this->is_backfill_active();

		return array(
			'status'      => $status,
			'isActive'    => $active,
			'canStart'    => ! $active,
			'actions'     => array(
				self::MODE_BACKFILL,
				self::MODE_REPLACE,
			),
			'settingsUrl' => \admin_url( 'admin.php?page=wc-admin&path=/analytics/settings' ),
		);
	}

	/**
	 * Get sync status from the configured provider.
	 *
	 * @since 0.9.1
	 *
	 * @return array<string, mixed>
	 */
	private function get_status(): array {
		if ( \method_exists( $this->sync_status, 'get_status' ) ) {
			return (array) $this->sync_status->get_status();
		}

		return array();
	}

	/**
	 * Determine whether a run is active.
	 *
	 * @since 0.9.1
	 *
	 * @return bool True when a run is queued or running.
	 */
	private function is_backfill_active(): bool {
		if ( \method_exists( $this->scheduler, 'is_backfill_active' ) ) {
			return (bool) $this->scheduler->is_backfill_active();
		}

		return $this->is_backfill_active_from_status( $this->get_status() );
	}

	/**
	 * Schedule a non-destructive backfill.
	 *
	 * @since 0.9.1
	 *
	 * @return void
	 */
	private function schedule_backfill(): void {
		if ( \method_exists( $this->scheduler, 'schedule_backfill' ) ) {
			$this->scheduler->schedule_backfill( true );
		}
	}

	/**
	 * Schedule full regeneration.
	 *
	 * @since 0.9.1
	 *
	 * @return void
	 */
	private function schedule_regeneration(): void {
		if ( \method_exists( $this->scheduler, 'schedule_regeneration' ) ) {
			$this->scheduler->schedule_regeneration();
		}
	}

	/**
	 * Determine whether a sync status payload describes an active run.
	 *
	 * @since 0.9.1
	 *
	 * @param array<string, mixed> $status Sync status payload.
	 *
	 * @return bool True when a run is queued or running.
	 */
	private function is_backfill_active_from_status( array $status ): bool {
		return \in_array(
			(string) ( $status['backfillStatus'] ?? '' ),
			array(
				BackfillScheduler::STATUS_QUEUED,
				BackfillScheduler::STATUS_RUNNING,
			),
			true
		);
	}
}
