<?php
/**
 * WooCommerce Analytics REST controller for upcoming renewal reconciliation.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewals;

use AdditionalSubscriptionsAnalytics\Diagnostics\UpcomingRenewalsReconciler;

defined( 'ABSPATH' ) || exit;

/**
 * Serves source-vs-lookup reconciliation diagnostics for the report.
 *
 * @since 0.1.0
 */
final class ReconciliationController {

	/**
	 * REST namespace.
	 *
	 * @since 0.1.0
	 */
	private const REST_NAMESPACE = 'wc-analytics';

	/**
	 * REST route base.
	 *
	 * @since 0.1.0
	 */
	private const REST_BASE = 'reports/upcoming-renewals/reconcile';

	/**
	 * Reconciliation service.
	 *
	 * @var object
	 */
	private object $reconciler;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param object|null $reconciler Optional reconciliation service.
	 */
	public function __construct( ?object $reconciler = null ) {
		$this->reconciler = $reconciler ?? new UpcomingRenewalsReconciler();
	}

	/**
	 * Register REST hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		\add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register reconciliation routes.
	 *
	 * @since 0.1.0
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
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);
	}

	/**
	 * Get route collection params.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params(): array {
		return array(
			'after'  => array(
				'description'       => __( 'Start date for the reconciliation window.', 'additional-subscriptions-analytics' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'before' => array(
				'description'       => __( 'End date for the reconciliation window.', 'additional-subscriptions-analytics' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'status' => array(
				'description'       => __(
					'Subscription status filter. Use "any" to include all statuses.',
					'additional-subscriptions-analytics'
				),
				'type'              => 'string',
				'default'           => 'active',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'limit'  => array(
				'description'       => __(
					'Maximum number of source subscriptions to scan.',
					'additional-subscriptions-analytics'
				),
				'type'              => 'integer',
				'default'           => 5000,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Check whether a request can read reconciliation diagnostics.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 *
	 * @return \WP_Error|bool True when access is allowed.
	 */
	public function get_items_permissions_check( $request ) {
		unset( $request );

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce core capability.
		if ( ! \current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error(
				'woocommerce_rest_cannot_view',
				__( 'Sorry, you cannot view upcoming renewal reconciliation diagnostics.', 'additional-subscriptions-analytics' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Get source-vs-lookup reconciliation diagnostics.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_REST_Request $request Full request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_item( $request ) {
		return \rest_ensure_response(
			$this->reconciler->reconcile(
				array(
					'after'  => $request->get_param( 'after' ),
					'before' => $request->get_param( 'before' ),
					'status' => $request->get_param( 'status' ),
					'limit'  => $request->get_param( 'limit' ),
				)
			)
		);
	}
}
