<?php
/**
 * Subscription product lookup row builder.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Sync;

use AdditionalSubscriptionsAnalytics\Data\DateWindow;

defined( 'ABSPATH' ) || exit;

/**
 * Builds `wc_subscription_product_lookup` rows from subscription line items.
 *
 * @since 0.1.0
 */
final class ProductLookupRowBuilder {

	/**
	 * Date normalization helper.
	 *
	 * @var DateWindow
	 */
	private DateWindow $date_window;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param DateWindow|null $date_window Optional date normalization helper.
	 */
	public function __construct( ?DateWindow $date_window = null ) {
		$this->date_window = $date_window ?? new DateWindow();
	}

	/**
	 * Build product lookup rows for a subscription.
	 *
	 * @since 0.1.0
	 *
	 * @param object $subscription WooCommerce Subscriptions subscription object.
	 *
	 * @return array<int, array<string, int|string|null>> Rows keyed numerically.
	 */
	public function build( object $subscription ): array {
		$subscription_id = $this->get_int_method_value( $subscription, 'get_id' );
		$rows            = array();

		foreach ( $this->get_line_items( $subscription ) as $item_id => $item ) {
			if ( ! \is_object( $item ) ) {
				continue;
			}

			$rows[] = array(
				'subscription_id' => $subscription_id,
				'line_item_id'    => $this->get_line_item_id( $item, $item_id ),
				'product_id'      => $this->get_int_method_value( $item, 'get_product_id' ),
				'variation_id'    => $this->get_int_method_value( $item, 'get_variation_id' ),
				'product_name'    => $this->normalize_text( $this->get_string_method_value( $item, 'get_name' ), 255 ),
				'product_qty'     => $this->format_decimal( $this->get_method_value( $item, 'get_quantity' ) ),
				'line_subtotal'   => $this->format_decimal( $this->get_method_value( $item, 'get_subtotal' ) ),
				'line_total'      => $this->format_decimal( $this->get_method_value( $item, 'get_total' ) ),
				'line_tax'        => $this->format_decimal( $this->get_method_value( $item, 'get_total_tax' ) ),
				'synced_at_gmt'   => $this->date_window->current_gmt_datetime(),
			);
		}

		return $rows;
	}

	/**
	 * Get line items from a subscription object.
	 *
	 * @since 0.1.0
	 *
	 * @param object $subscription Subscription object.
	 *
	 * @return iterable<int|string, mixed> Subscription line items.
	 */
	private function get_line_items( object $subscription ): iterable {
		if ( ! \method_exists( $subscription, 'get_items' ) ) {
			return array();
		}

		return $subscription->get_items( 'line_item' );
	}

	/**
	 * Get a line item ID.
	 *
	 * @since 0.1.0
	 *
	 * @param object     $item     Line item object.
	 * @param int|string $fallback Fallback item array key.
	 *
	 * @return int Line item ID.
	 */
	private function get_line_item_id( object $item, int|string $fallback ): int {
		$item_id = $this->get_int_method_value( $item, 'get_id' );

		if ( 0 !== $item_id ) {
			return $item_id;
		}

		return $this->normalize_int( $fallback );
	}

	/**
	 * Get a method value.
	 *
	 * @since 0.1.0
	 *
	 * @param object $source_object Object to inspect.
	 * @param string $method        Method name.
	 *
	 * @return mixed Method return value, or null when the method does not exist.
	 */
	private function get_method_value( object $source_object, string $method ): mixed {
		if ( ! \method_exists( $source_object, $method ) ) {
			return null;
		}

		return $source_object->{$method}();
	}

	/**
	 * Get a method value as an integer.
	 *
	 * @since 0.1.0
	 *
	 * @param object $source_object Object to inspect.
	 * @param string $method        Method name.
	 *
	 * @return int Normalized integer.
	 */
	private function get_int_method_value( object $source_object, string $method ): int {
		return $this->normalize_int( $this->get_method_value( $source_object, $method ) );
	}

	/**
	 * Get a method value as a string.
	 *
	 * @since 0.1.0
	 *
	 * @param object $source_object Object to inspect.
	 * @param string $method        Method name.
	 *
	 * @return string Normalized string.
	 */
	private function get_string_method_value( object $source_object, string $method ): string {
		$value = $this->get_method_value( $source_object, $method );

		return \is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Normalize an ID or count value.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Source value.
	 *
	 * @return int Non-negative integer.
	 */
	private function normalize_int( mixed $value ): int {
		return \max( 0, (int) $value );
	}

	/**
	 * Normalize item text for lookup-table storage.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value  Source text.
	 * @param int    $length Maximum byte length.
	 *
	 * @return string Normalized text.
	 */
	private function normalize_text( string $value, int $length ): string {
		if ( \function_exists( 'wp_strip_all_tags' ) ) {
			$value = \wp_strip_all_tags( $value, true );
		} else {
			$value = (string) \preg_replace( '/<[^>]*>/', '', $value );
		}

		$value = \trim( $value );

		return \substr( $value, 0, $length );
	}

	/**
	 * Format a decimal for lookup-table storage.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Source decimal.
	 *
	 * @return string Decimal formatted to eight places.
	 */
	private function format_decimal( mixed $value ): string {
		if ( \function_exists( 'wc_format_decimal' ) ) {
			return \wc_format_decimal( $value, 8 );
		}

		if ( \is_string( $value ) ) {
			$value = \str_replace( ',', '', $value );
		}

		return \number_format( (float) $value, 8, '.', '' );
	}
}
