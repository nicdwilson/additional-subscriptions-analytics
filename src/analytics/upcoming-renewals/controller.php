<?php
/**
 * WooCommerce Analytics REST controller for upcoming renewals.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Analytics\UpcomingRenewals;

use Automattic\WooCommerce\Admin\API\Reports\ExportableInterface;
use Automattic\WooCommerce\Admin\API\Reports\GenericController;

defined( 'ABSPATH' ) || exit;

/**
 * Serves upcoming renewal product aggregates through WooCommerce Analytics.
 *
 * @since 0.1.0
 */
final class Controller extends GenericController implements ExportableInterface {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'reports/upcoming-renewals';

	/**
	 * Get the query params for collections.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['order']['default']   = 'asc';
		$params['orderby']['default'] = 'product_name';
		$params['orderby']['enum']    = $this->apply_custom_orderby_filters(
			array(
				'product_name',
				'product_id',
				'variation_id',
				'total_qty',
				'total_quantity',
				'quantity',
				'subscription_count',
				'subscriptions_count',
				'recurring_total',
				'first_next_payment_date_gmt',
				'last_next_payment_date_gmt',
			)
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
	 * Check whether a request can read upcoming renewal report rows.
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
				__( 'Sorry, you cannot view upcoming renewal analytics.', 'additional-subscriptions-analytics' ),
				array( 'status' => \rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Map public REST query args to data-store query args.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_REST_Request $request Full request object.
	 *
	 * @return array<string, mixed>
	 */
	protected function prepare_reports_query( $request ) {
		$args = parent::prepare_reports_query( $request );

		if ( 'total_qty' === ( $args['orderby'] ?? '' ) ) {
			$args['orderby'] = 'total_quantity';
		}

		if ( 'subscription_count' === ( $args['orderby'] ?? '' ) ) {
			$args['orderby'] = 'subscriptions_count';
		}

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
	 * Prepare a report data item for serialization.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed            $report_item Report data item from the data store.
	 * @param \WP_REST_Request $request     Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function prepare_item_for_response( $report_item, $request ) {
		$data                = $this->prepare_report_item( $report_item );
		$product             = $this->get_product_for_item( $data );
		$data['product_sku'] = $this->get_product_sku( $product );
		$response            = parent::prepare_item_for_response( $data, $request );
		$response->add_links( $this->prepare_links( $data, $product ) );

		/**
		 * Filter a prepared upcoming renewals report item.
		 *
		 * @since 0.1.0
		 *
		 * @param \WP_REST_Response $response    The response object.
		 * @param mixed             $report_item The original report item.
		 * @param \WP_REST_Request  $request     Request used to generate the response.
		 */
		return \apply_filters( 'woocommerce_rest_prepare_report_upcoming_renewals', $response, $report_item, $request );
	}

	/**
	 * Get the Report's schema, conforming to JSON Schema.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'report_upcoming_renewals',
			'type'       => 'object',
			'properties' => array(
				'product_id'                  => array(
					'description' => __( 'Product ID.', 'additional-subscriptions-analytics' ),
					'type'        => 'integer',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'variation_id'                => array(
					'description' => __( 'Variation ID, or 0 for simple products.', 'additional-subscriptions-analytics' ),
					'type'        => 'integer',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'product_name'                => array(
					'description' => __( 'Product name snapshot from the subscription line item.', 'additional-subscriptions-analytics' ),
					'type'        => 'string',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'product_sku'                 => array(
					'description' => __( 'Current product or variation SKU, resolved live from the product.', 'additional-subscriptions-analytics' ),
					'type'        => 'string',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'total_qty'                   => array(
					'description' => __( 'Total recurring product quantity due to renew.', 'additional-subscriptions-analytics' ),
					'type'        => 'number',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'subscription_count'          => array(
					'description' => __( 'Number of subscriptions due to renew.', 'additional-subscriptions-analytics' ),
					'type'        => 'integer',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'recurring_total'             => array(
					'description' => __( 'Recurring revenue due for the renewal window.', 'additional-subscriptions-analytics' ),
					'type'        => 'number',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
					'format'      => 'currency',
				),
				'currency'                    => array(
					'description' => __( 'Subscription currency code.', 'additional-subscriptions-analytics' ),
					'type'        => 'string',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'first_next_payment_date_gmt' => array(
					'description' => __( 'First matching next payment date in GMT.', 'additional-subscriptions-analytics' ),
					'type'        => 'string',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
				'last_next_payment_date_gmt'  => array(
					'description' => __( 'Last matching next payment date in GMT.', 'additional-subscriptions-analytics' ),
					'type'        => 'string',
					'readonly'    => true,
					'context'     => array( 'view', 'edit' ),
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Get the column names for CSV export.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Key value pair of column ID => label.
	 */
	public function get_export_columns() {
		$export_columns = array(
			'product_name'       => __( 'Product title', 'additional-subscriptions-analytics' ),
			'product_sku'        => __( 'SKU', 'additional-subscriptions-analytics' ),
			'product_id'         => __( 'Product ID', 'additional-subscriptions-analytics' ),
			'variation_id'       => __( 'Variation ID', 'additional-subscriptions-analytics' ),
			'total_qty'          => __( 'Renewal quantity', 'additional-subscriptions-analytics' ),
			'subscription_count' => __( 'Subscriptions', 'additional-subscriptions-analytics' ),
			'recurring_total'    => __( 'Recurring total', 'additional-subscriptions-analytics' ),
			'currency'           => __( 'Currency', 'additional-subscriptions-analytics' ),
		);

		/**
		 * Filter upcoming renewals export columns.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, string> $export_columns Key value pair of column ID => label.
		 */
		return \apply_filters( 'woocommerce_report_upcoming_renewals_export_columns', $export_columns );
	}

	/**
	 * Get CSV column values for an upcoming renewals report item.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $item Single report item/row.
	 *
	 * @return array<string, int|string> Key value pair of column ID => row value.
	 */
	public function prepare_item_for_export( $item ) {
		$item        = \is_array( $item ) ? $item : (array) $item;
		$export_item = array(
			'product_name'       => (string) ( $item['product_name'] ?? '' ),
			'product_sku'        => $this->get_product_sku( $this->get_product_for_item( $item ) ),
			'product_id'         => \max( 0, (int) ( $item['product_id'] ?? 0 ) ),
			'variation_id'       => \max( 0, (int) ( $item['variation_id'] ?? 0 ) ),
			'total_qty'          => $this->format_quantity( $item['total_qty'] ?? 0 ),
			'subscription_count' => \max( 0, (int) ( $item['subscription_count'] ?? 0 ) ),
			'recurring_total'    => $this->format_currency_amount( $item['recurring_total'] ?? 0 ),
			'currency'           => (string) ( $item['currency'] ?? '' ),
		);

		/**
		 * Filter an upcoming renewals export row.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, int|string> $export_item Prepared export item.
		 * @param array<string, mixed>      $item        Original report item.
		 */
		return \apply_filters( 'woocommerce_report_upcoming_renewals_prepare_export_item', $export_item, $item );
	}

	/**
	 * Normalize one data-store row for public REST output.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $report_item Report item from the data store.
	 *
	 * @return array<string, float|int|string>
	 */
	private function prepare_report_item( mixed $report_item ): array {
		$item = \is_object( $report_item ) ? \get_object_vars( $report_item ) : (array) $report_item;

		return array(
			'product_id'                  => \max( 0, (int) ( $item['product_id'] ?? 0 ) ),
			'variation_id'                => \max( 0, (int) ( $item['variation_id'] ?? 0 ) ),
			'product_name'                => (string) ( $item['product_name'] ?? '' ),
			'total_qty'                   => (float) ( $item['total_quantity'] ?? $item['total_qty'] ?? 0 ),
			'subscription_count'          => \max(
				0,
				(int) ( $item['subscriptions_count'] ?? $item['subscription_count'] ?? 0 )
			),
			'recurring_total'             => (float) ( $item['recurring_total'] ?? 0 ),
			'currency'                    => (string) ( $item['currency'] ?? '' ),
			'first_next_payment_date_gmt' => (string) ( $item['first_next_payment_date_gmt'] ?? '' ),
			'last_next_payment_date_gmt'  => (string) ( $item['last_next_payment_date_gmt'] ?? '' ),
		);
	}

	/**
	 * Prepare REST links for a report row.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, float|int|string> $item    Prepared report item.
	 * @param object|null                     $product Optional pre-loaded product to avoid a second lookup.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function prepare_links( array $item, ?object $product = null ): array {
		$product = $product ?? $this->get_product_for_item( $item );

		if ( null === $product ) {
			return array();
		}

		$product_id = \max( 0, (int) $product->get_id() );

		if ( 0 === $product_id ) {
			return array();
		}

		$links = array(
			'product' => array(
				'href' => \rest_url( \sprintf( '/%s/products/%d', $this->namespace, $product_id ) ),
			),
		);

		if ( \function_exists( 'get_edit_post_link' ) ) {
			$edit_link = \get_edit_post_link( $product_id, 'raw' );

			if ( \is_string( $edit_link ) && '' !== $edit_link ) {
				$links['edit'] = array( 'href' => $edit_link );
			}
		}

		if ( \function_exists( 'get_permalink' ) ) {
			$view_link = \get_permalink( $product_id );

			if ( \is_string( $view_link ) && '' !== $view_link ) {
				$links['view'] = array( 'href' => $view_link );
			}
		}

		return $links;
	}

	/**
	 * Get the best product ID for REST links.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, float|int|string> $item Prepared report item.
	 *
	 * @return int Product or variation ID.
	 */
	private function get_linked_product_id( array $item ): int {
		$variation_id = \max( 0, (int) ( $item['variation_id'] ?? 0 ) );

		if ( 0 !== $variation_id ) {
			return $variation_id;
		}

		return \max( 0, (int) ( $item['product_id'] ?? 0 ) );
	}

	/**
	 * Load the live product for a report item.
	 *
	 * The SKU is resolved from the current product rather than snapshotted into
	 * the lookup table, so it is always fresh. See
	 * documentation/DECISION_RECORD_PRODUCT_META.md.
	 *
	 * @since 0.9.6
	 *
	 * @param array<string, float|int|string> $item Report item (prepared or raw store row).
	 *
	 * @return object|null Product object, or null when it cannot be resolved.
	 */
	private function get_product_for_item( array $item ): ?object {
		$product_id = $this->get_linked_product_id( $item );

		if ( 0 === $product_id || ! \function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = \wc_get_product( $product_id );

		if ( ! \is_object( $product ) || ! \method_exists( $product, 'get_id' ) ) {
			return null;
		}

		return $product;
	}

	/**
	 * Get the current SKU for a resolved product.
	 *
	 * @since 0.9.6
	 *
	 * @param object|null $product Product object, or null.
	 *
	 * @return string Current SKU, or an empty string when unavailable.
	 */
	private function get_product_sku( ?object $product ): string {
		if ( null === $product || ! \method_exists( $product, 'get_sku' ) ) {
			return '';
		}

		$sku = $product->get_sku();

		return \is_scalar( $sku ) ? (string) $sku : '';
	}

	/**
	 * Format a quantity value for CSV output.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Quantity value.
	 *
	 * @return string Formatted quantity.
	 */
	private function format_quantity( mixed $value ): string {
		return \number_format( (float) $value, 8, '.', '' );
	}

	/**
	 * Format a currency amount for CSV output.
	 *
	 * @since 0.1.0
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
