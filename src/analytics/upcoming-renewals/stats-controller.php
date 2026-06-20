<?php
/**
 * WooCommerce Analytics REST controller for upcoming renewal stats.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.9.2
 */

namespace AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewals;

use AdditionalSubscriptionsAnalytics\Data\UpcomingRenewalsQuery;
use Automattic\WooCommerce\Admin\API\Reports\GenericStatsController;

defined( 'ABSPATH' ) || exit;

/**
 * Serves upcoming renewal interval stats through WooCommerce Analytics.
 *
 * @since 0.9.2
 */
final class StatsController extends GenericStatsController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'reports/upcoming-renewals/stats';

	/**
	 * Get data from the upcoming renewals query.
	 *
	 * @since 0.9.2
	 *
	 * @param array<string, mixed> $query_args Query arguments.
	 *
	 * @return \stdClass Results from the data store.
	 */
	protected function get_datastore_data( $query_args = array() ) {
		$results = ( new UpcomingRenewalsQuery() )->get_stats_data( \is_array( $query_args ) ? $query_args : array() );

		return (object) array(
			'totals'    => (object) $results['totals'],
			'intervals' => \array_map(
				static function ( array $interval ): \stdClass {
					$interval['subtotals'] = (object) $interval['subtotals'];

					return (object) $interval;
				},
				$results['intervals']
			),
			'total'     => $results['total'],
			'pages'     => $results['pages'],
			'page_no'   => $results['page_no'],
		);
	}

	/**
	 * Map public REST query args to data-store query args.
	 *
	 * @since 0.9.2
	 *
	 * @param \WP_REST_Request $request Full request object.
	 *
	 * @return array<string, mixed>
	 */
	protected function prepare_reports_query( $request ) {
		$args = parent::prepare_reports_query( $request );

		$args['match']              = $request['match'];
		$args['status_is']          = (array) $request['status_is'];
		$args['status_is_not']      = (array) $request['status_is_not'];
		$args['product_includes']   = (array) $request['product_includes'];
		$args['product_excludes']   = (array) $request['product_excludes'];
		$args['variation_includes'] = (array) $request['variation_includes'];
		$args['variation_excludes'] = (array) $request['variation_excludes'];

		return $args;
	}

	/**
	 * Check whether a request can read upcoming renewal stats.
	 *
	 * @since 0.9.2
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
				__( 'Sorry, you cannot view upcoming renewal analytics.', 'additional-subscriptions-analytics' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Prepare a stats interval for serialization.
	 *
	 * @since 0.9.2
	 *
	 * @param mixed            $report_item Report data item from the data store.
	 * @param \WP_REST_Request $request     Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function prepare_item_for_response( $report_item, $request ) {
		$response = parent::prepare_item_for_response( $report_item, $request );

		/**
		 * Filter a prepared upcoming renewals stats interval.
		 *
		 * @since 0.9.2
		 *
		 * @param \WP_REST_Response $response    The response object.
		 * @param mixed             $report_item The original report item.
		 * @param \WP_REST_Request  $request     Request used to generate the response.
		 */
		return \apply_filters( 'woocommerce_rest_prepare_report_upcoming_renewals_stats', $response, $report_item, $request );
	}

	/**
	 * Get the report's stats schema.
	 *
	 * @since 0.9.2
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		$schema          = parent::get_item_schema();
		$schema['title'] = 'report_upcoming_renewals_stats';

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Get the query params for collections.
	 *
	 * @since 0.9.2
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params() {
		$params                       = parent::get_collection_params();
		$params['orderby']['enum']    = $this->apply_custom_orderby_filters(
			array(
				'date',
				'renewals_count',
				'renewal_quantity',
				'recurring_total',
			)
		);
		$params['match']              = $this->get_match_param();
		$params['status_is']          = $this->get_status_list_param(
			__( 'Limit results to subscriptions with the specified status.', 'additional-subscriptions-analytics' )
		);
		$params['status_is_not']      = $this->get_status_list_param(
			__( 'Limit results to subscriptions without the specified status.', 'additional-subscriptions-analytics' )
		);
		$params['product_includes']   = $this->get_id_list_param(
			__( 'Limit results to the specified products.', 'additional-subscriptions-analytics' )
		);
		$params['product_excludes']   = $this->get_id_list_param(
			__( 'Limit results to exclude the specified products.', 'additional-subscriptions-analytics' )
		);
		$params['variation_includes'] = $this->get_id_list_param(
			__( 'Limit results to the specified product variations.', 'additional-subscriptions-analytics' )
		);
		$params['variation_excludes'] = $this->get_id_list_param(
			__( 'Limit results to exclude the specified product variations.', 'additional-subscriptions-analytics' )
		);

		return $params;
	}

	/**
	 * Get the stats properties schema.
	 *
	 * @since 0.9.2
	 *
	 * @return array<string, mixed>
	 */
	protected function get_item_properties_schema() {
		return array(
			'renewals_count'   => array(
				'title'       => __( 'Renewals', 'additional-subscriptions-analytics' ),
				'description' => __( 'Number of subscriptions due to renew.', 'additional-subscriptions-analytics' ),
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'indicator'   => true,
			),
			'renewal_quantity' => array(
				'description' => __( 'Total recurring product quantity due to renew.', 'additional-subscriptions-analytics' ),
				'type'        => 'number',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'recurring_total'  => array(
				'description' => __( 'Recurring revenue due for the renewal window.', 'additional-subscriptions-analytics' ),
				'type'        => 'number',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'format'      => 'currency',
			),
		);
	}

	/**
	 * Get a match-mode REST parameter definition.
	 *
	 * @since 0.9.2
	 *
	 * @return array<string, mixed> Parameter definition.
	 */
	private function get_match_param(): array {
		return array(
			'description'       => __(
				'Indicates whether all filter conditions or any filter condition should match.',
				'additional-subscriptions-analytics'
			),
			'type'              => 'string',
			'default'           => 'all',
			'enum'              => array( 'all', 'any' ),
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Get a status-list REST parameter definition.
	 *
	 * @since 0.9.2
	 *
	 * @param string $description Parameter description.
	 *
	 * @return array<string, mixed> Parameter definition.
	 */
	private function get_status_list_param( string $description ): array {
		return array(
			'description'       => $description,
			'type'              => 'array',
			'sanitize_callback' => 'wp_parse_slug_list',
			'validate_callback' => 'rest_validate_request_arg',
			'items'             => array(
				'type' => 'string',
			),
		);
	}

	/**
	 * Get an ID-list REST parameter definition.
	 *
	 * @since 0.9.2
	 *
	 * @param string $description Parameter description.
	 *
	 * @return array<string, mixed> Parameter definition.
	 */
	private function get_id_list_param( string $description ): array {
		return array(
			'description'       => $description,
			'type'              => 'array',
			'items'             => array(
				'type' => 'integer',
			),
			'default'           => array(),
			'sanitize_callback' => 'wp_parse_id_list',
			'validate_callback' => 'rest_validate_request_arg',
		);
	}
}
