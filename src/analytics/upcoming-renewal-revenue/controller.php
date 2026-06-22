<?php
/**
 * WooCommerce Analytics REST controller for upcoming renewal revenue.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.9.5
 */

namespace AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewalRevenue;

use Automattic\WooCommerce\Admin\API\Reports\ExportableInterface;
use Automattic\WooCommerce\Admin\API\Reports\GenericController;

defined( 'ABSPATH' ) || exit;

/**
 * Serves upcoming renewal revenue period aggregates through WooCommerce Analytics.
 *
 * @since 0.9.5
 */
final class Controller extends GenericController implements ExportableInterface {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'reports/upcoming-renewal-revenue';

	/**
	 * Get the query params for collections.
	 *
	 * @since 0.9.5
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['order']['default']   = 'asc';
		$params['orderby']['default'] = 'date_start';
		$params['orderby']['enum']    = $this->apply_custom_orderby_filters(
			array(
				'date',
				'date_start',
				'renewals_count',
				'subscription_count',
				'subscriptions_count',
				'recurring_total',
			)
		);
		$params['groupby']            = array(
			'description'       => __(
				'Table grouping period for recurring revenue rows.',
				'additional-subscriptions-analytics'
			),
			'type'              => 'string',
			'default'           => 'month',
			'enum'              => array( 'day', 'week', 'month', 'year' ),
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['status']             = array(
			'description'       => __(
				'Limit results to subscriptions with the specified status. Use "any" to include all statuses.',
				'additional-subscriptions-analytics'
			),
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);
		$params['match']              = $this->get_match_param();
		$params['status_is']          = $this->get_status_list_param(
			__( 'Limit results to subscriptions with the specified status.', 'additional-subscriptions-analytics' )
		);
		$params['status_is_not']      = $this->get_status_list_param(
			__( 'Limit results to subscriptions without the specified status.', 'additional-subscriptions-analytics' )
		);

		return $params;
	}

	/**
	 * Check whether a request can read upcoming renewal revenue report rows.
	 *
	 * @since 0.9.5
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
				__( 'Sorry, you cannot view upcoming renewal revenue analytics.', 'additional-subscriptions-analytics' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Map public REST query args to data-store query args.
	 *
	 * @since 0.9.5
	 *
	 * @param \WP_REST_Request $request Full request object.
	 *
	 * @return array<string, mixed>
	 */
	protected function prepare_reports_query( $request ) {
		$args = parent::prepare_reports_query( $request );

		if ( 'date' === ( $args['orderby'] ?? '' ) ) {
			$args['orderby'] = 'date_start';
		}

		if ( 'subscription_count' === ( $args['orderby'] ?? '' ) ) {
			$args['orderby'] = 'subscriptions_count';
		}

		$args['groupby']       = $request['groupby'];
		$args['match']         = $request['match'];
		$args['status_is']     = (array) $request['status_is'];
		$args['status_is_not'] = (array) $request['status_is_not'];

		return $args;
	}

	/**
	 * Prepare a report data item for serialization.
	 *
	 * @since 0.9.5
	 *
	 * @param mixed            $report_item Report data item from the data store.
	 * @param \WP_REST_Request $request     Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function prepare_item_for_response( $report_item, $request ) {
		$data     = $this->prepare_report_item( $report_item );
		$response = parent::prepare_item_for_response( $data, $request );

		/**
		 * Filter a prepared upcoming renewal revenue report item.
		 *
		 * @since 0.9.5
		 *
		 * @param \WP_REST_Response $response    The response object.
		 * @param mixed             $report_item The original report item.
		 * @param \WP_REST_Request  $request     Request used to generate the response.
		 */
		return \apply_filters( 'woocommerce_rest_prepare_report_upcoming_renewal_revenue', $response, $report_item, $request );
	}

	/**
	 * Get the Report's schema, conforming to JSON Schema.
	 *
	 * @since 0.9.5
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'report_upcoming_renewal_revenue',
			'type'       => 'object',
			'properties' => array(
				'period'             => array(
					'description' => __( 'Period label source for the grouped row.', 'additional-subscriptions-analytics' ),
					'type'        => 'string',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'grouping'           => array(
					'description' => __( 'Table grouping period.', 'additional-subscriptions-analytics' ),
					'type'        => 'string',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'date_start'         => array(
					'description' => __( 'Grouped period start in the site timezone.', 'additional-subscriptions-analytics' ),
					'type'        => 'string',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'date_start_gmt'     => array(
					'description' => __( 'Grouped period start in GMT.', 'additional-subscriptions-analytics' ),
					'type'        => 'string',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'date_end'           => array(
					'description' => __( 'Grouped period end in the site timezone.', 'additional-subscriptions-analytics' ),
					'type'        => 'string',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'date_end_gmt'       => array(
					'description' => __( 'Grouped period end in GMT.', 'additional-subscriptions-analytics' ),
					'type'        => 'string',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'renewals_count'     => array(
					'description' => __( 'Number of renewal occurrences in the period.', 'additional-subscriptions-analytics' ),
					'type'        => 'integer',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'subscription_count' => array(
					'description' => __( 'Number of subscriptions with renewal occurrences in the period.', 'additional-subscriptions-analytics' ),
					'type'        => 'integer',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'recurring_total'    => array(
					'description' => __( 'Recurring revenue due for the period.', 'additional-subscriptions-analytics' ),
					'type'        => 'number',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
					'format'      => 'currency',
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Get the column names for CSV export.
	 *
	 * @since 0.9.5
	 *
	 * @return array<string, string> Key value pair of column ID => label.
	 */
	public function get_export_columns() {
		$export_columns = array(
			'date_start'         => __( 'Period start', 'additional-subscriptions-analytics' ),
			'date_end'           => __( 'Period end', 'additional-subscriptions-analytics' ),
			'grouping'           => __( 'Grouping', 'additional-subscriptions-analytics' ),
			'renewals_count'     => __( 'Renewals', 'additional-subscriptions-analytics' ),
			'subscription_count' => __( 'Subscriptions', 'additional-subscriptions-analytics' ),
			'recurring_total'    => __( 'Recurring total', 'additional-subscriptions-analytics' ),
		);

		/**
		 * Filter upcoming renewal revenue export columns.
		 *
		 * @since 0.9.5
		 *
		 * @param array<string, string> $export_columns Key value pair of column ID => label.
		 */
		return \apply_filters( 'woocommerce_report_upcoming_renewal_revenue_export_columns', $export_columns );
	}

	/**
	 * Get CSV column values for an upcoming renewal revenue report item.
	 *
	 * @since 0.9.5
	 *
	 * @param array<string, mixed> $item Single report item/row.
	 *
	 * @return array<string, int|string> Key value pair of column ID => row value.
	 */
	public function prepare_item_for_export( $item ) {
		$item        = \is_array( $item ) ? $item : (array) $item;
		$export_item = array(
			'date_start'         => (string) ( $item['date_start'] ?? '' ),
			'date_end'           => (string) ( $item['date_end'] ?? '' ),
			'grouping'           => (string) ( $item['grouping'] ?? '' ),
			'renewals_count'     => \max( 0, (int) ( $item['renewals_count'] ?? 0 ) ),
			'subscription_count' => \max( 0, (int) ( $item['subscription_count'] ?? 0 ) ),
			'recurring_total'    => $this->format_currency_amount( $item['recurring_total'] ?? 0 ),
		);

		/**
		 * Filter an upcoming renewal revenue export row.
		 *
		 * @since 0.9.5
		 *
		 * @param array<string, int|string> $export_item Prepared export item.
		 * @param array<string, mixed>      $item        Original report item.
		 */
		return \apply_filters( 'woocommerce_report_upcoming_renewal_revenue_prepare_export_item', $export_item, $item );
	}

	/**
	 * Normalize one data-store row for public REST output.
	 *
	 * @since 0.9.5
	 *
	 * @param mixed $report_item Report item from the data store.
	 *
	 * @return array<string, float|int|string>
	 */
	private function prepare_report_item( mixed $report_item ): array {
		$item = \is_object( $report_item ) ? \get_object_vars( $report_item ) : (array) $report_item;

		return array(
			'period'             => (string) ( $item['period'] ?? $item['date_start'] ?? '' ),
			'grouping'           => (string) ( $item['grouping'] ?? '' ),
			'date_start'         => (string) ( $item['date_start'] ?? '' ),
			'date_start_gmt'     => (string) ( $item['date_start_gmt'] ?? '' ),
			'date_end'           => (string) ( $item['date_end'] ?? '' ),
			'date_end_gmt'       => (string) ( $item['date_end_gmt'] ?? '' ),
			'renewals_count'     => \max( 0, (int) ( $item['renewals_count'] ?? 0 ) ),
			'subscription_count' => \max(
				0,
				(int) ( $item['subscriptions_count'] ?? $item['subscription_count'] ?? 0 )
			),
			'recurring_total'    => (float) ( $item['recurring_total'] ?? 0 ),
		);
	}

	/**
	 * Format a currency amount for CSV output.
	 *
	 * @since 0.9.5
	 *
	 * @param mixed $value Currency amount.
	 *
	 * @return string Formatted amount.
	 */
	private function format_currency_amount( mixed $value ): string {
		$decimals = \function_exists( 'wc_get_price_decimals' ) ? \wc_get_price_decimals() : 2;

		return \number_format( (float) $value, \max( 0, (int) $decimals ), '.', '' );
	}

	/**
	 * Get a match-mode REST parameter definition.
	 *
	 * @since 0.9.5
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
	 * @since 0.9.5
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
}
