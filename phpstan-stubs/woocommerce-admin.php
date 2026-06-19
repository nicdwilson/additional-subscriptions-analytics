<?php
/**
 * PHPStan stubs for WooCommerce Admin Analytics classes.
 *
 * @package AdditionalSubscriptionsAnalytics
 */

namespace {
	/**
	 * WooCommerce reports REST base controller.
	 */
	class WC_REST_Reports_Controller {
	}

	/**
	 * REST request.
	 */
	class WP_REST_Request {

		/**
		 * Get a request parameter.
		 *
		 * @param string $key Parameter key.
		 *
		 * @return mixed
		 */
		public function get_param( string $key ): mixed {}
	}

	/**
	 * REST server.
	 */
	class WP_REST_Server {

		/**
		 * Readable method alias.
		 *
		 * @var string
		 */
		public const READABLE = 'GET';
	}

	/**
	 * REST response.
	 */
	class WP_REST_Response {

		/**
		 * Add response links.
		 *
		 * @param array<string, mixed> $links Links.
		 *
		 * @return void
		 */
		public function add_links( array $links ): void {}
	}

	/**
	 * WordPress error value.
	 */
	class WP_Error {

		/**
		 * Constructor.
		 *
		 * @param string              $code    Error code.
		 * @param string              $message Error message.
		 * @param array<string,mixed> $data    Error data.
		 */
		public function __construct( string $code = '', string $message = '', array $data = array() ) {}
	}
}

namespace Automattic\WooCommerce\Admin\API\Reports {
	/**
	 * WooCommerce Admin generic Analytics controller.
	 */
	abstract class GenericController extends \WC_REST_Reports_Controller {

		/**
		 * Endpoint namespace.
		 *
		 * @var string
		 */
		protected $namespace = 'wc-analytics';

		/**
		 * Route base.
		 *
		 * @var string
		 */
		protected $rest_base = '';

		/**
		 * Get collection params.
		 *
		 * @return array<string,mixed>
		 */
		public function get_collection_params() {
			return array();
		}

		/**
		 * Prepare a report item for response.
		 *
		 * @param mixed             $report_item Report item.
		 * @param \WP_REST_Request $request     Request.
		 *
		 * @return \WP_REST_Response
		 */
		public function prepare_item_for_response( $report_item, $request ) {
			unset( $report_item, $request );

			return new \WP_REST_Response();
		}

		/**
		 * Add additional field schema.
		 *
		 * @param array<string,mixed> $schema Schema.
		 *
		 * @return array<string,mixed>
		 */
		public function add_additional_fields_schema( $schema ) {
			return $schema;
		}

		/**
		 * Apply orderby enum filters.
		 *
		 * @param array<int,string> $orderby_enum Orderby enum.
		 *
		 * @return array<int,string>
		 */
		protected function apply_custom_orderby_filters( $orderby_enum ) {
			return $orderby_enum;
		}

		/**
		 * Prepare report query args.
		 *
		 * @param \WP_REST_Request $request Request.
		 *
		 * @return array<string,mixed>
		 */
		protected function prepare_reports_query( $request ) {
			unset( $request );

			return array();
		}
	}

	/**
	 * WooCommerce Analytics exportable report interface.
	 */
	interface ExportableInterface {

		/**
		 * Get the column names for export.
		 *
		 * @return array<string,string>
		 */
		public function get_export_columns();

		/**
		 * Get the column values for export.
		 *
		 * @param array<string,mixed> $item Single report item/row.
		 *
		 * @return array<string,mixed>
		 */
		public function prepare_item_for_export( $item );
	}
}

namespace Automattic\WooCommerce\Admin {
	/**
	 * WooCommerce Admin page controller.
	 */
	class PageController {

		/**
		 * Determine whether the current page is powered by WooCommerce Admin.
		 *
		 * @return bool
		 */
		public static function is_admin_or_embed_page() {
			return false;
		}
	}
}
