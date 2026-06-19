<?php
/**
 * Subscription stats row builder.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Sync;

use AdditionalSubscriptionsAnalytics\Data\DateWindow;

defined( 'ABSPATH' ) || exit;

/**
 * Builds `wc_subscriptions_stats` rows from subscription objects.
 *
 * @since 0.1.0
 */
final class SubscriptionRowBuilder {

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
	 * Build a stats row for a subscription.
	 *
	 * @since 0.1.0
	 *
	 * @param object $subscription WooCommerce Subscriptions subscription object.
	 *
	 * @return array<string, int|string|null> Row keyed by `wc_subscriptions_stats` column.
	 */
	public function build( object $subscription ): array {
		return array(
			'subscription_id'          => $this->get_int_method_value( $subscription, 'get_id' ),
			'parent_order_id'          => $this->get_parent_order_id( $subscription ),
			'customer_id'              => $this->get_int_method_value( $subscription, 'get_customer_id' ),
			'status'                   => $this->normalize_key( $this->get_string_method_value( $subscription, 'get_status' ) ),
			'date_created_gmt'         => $this->get_subscription_date( $subscription, 'date_created' ),
			'date_updated_gmt'         => $this->get_subscription_date( $subscription, 'date_modified' ),
			'start_date_gmt'           => $this->get_subscription_date( $subscription, 'start' ),
			'trial_end_date_gmt'       => $this->get_subscription_date( $subscription, 'trial_end' ),
			'last_payment_date_gmt'    => $this->get_last_payment_date( $subscription ),
			'next_payment_date_gmt'    => $this->get_subscription_date( $subscription, 'next_payment' ),
			'end_date_gmt'             => $this->get_subscription_date( $subscription, 'end' ),
			'billing_period'           => $this->normalize_key(
				$this->get_string_method_value( $subscription, 'get_billing_period' )
			),
			'billing_interval'         => $this->get_int_method_value( $subscription, 'get_billing_interval' ),
			'recurring_total'          => $this->format_decimal( $this->get_method_value( $subscription, 'get_total' ) ),
			'recurring_tax_total'      => $this->format_decimal( $this->get_method_value( $subscription, 'get_total_tax' ) ),
			'recurring_shipping_total' => $this->format_decimal(
				$this->get_method_value( $subscription, 'get_shipping_total' )
			),
			'currency'                 => $this->normalize_currency(
				$this->get_string_method_value( $subscription, 'get_currency' )
			),
			'payment_method'           => $this->normalize_key(
				$this->get_string_method_value( $subscription, 'get_payment_method' )
			),
			'synced_at_gmt'            => $this->date_window->current_gmt_datetime(),
		);
	}

	/**
	 * Get a subscription schedule date.
	 *
	 * @since 0.1.0
	 *
	 * @param object $subscription Subscription object.
	 * @param string $date_type    Subscription date type.
	 *
	 * @return string|null GMT MySQL datetime, or null when absent.
	 */
	private function get_subscription_date( object $subscription, string $date_type ): ?string {
		if ( ! \method_exists( $subscription, 'get_date' ) ) {
			return null;
		}

		return $this->date_window->normalize_gmt_datetime( $subscription->get_date( $date_type, 'gmt' ) );
	}

	/**
	 * Get the best available last successful payment date.
	 *
	 * @since 0.1.0
	 *
	 * @param object $subscription Subscription object.
	 *
	 * @return string|null GMT MySQL datetime, or null when absent.
	 */
	private function get_last_payment_date( object $subscription ): ?string {
		$last_paid = $this->get_subscription_date( $subscription, 'last_order_date_paid' );

		if ( null !== $last_paid ) {
			return $last_paid;
		}

		return $this->get_subscription_date( $subscription, 'last_order_date_created' );
	}

	/**
	 * Get the parent order ID from a subscription.
	 *
	 * @since 0.1.0
	 *
	 * @param object $subscription Subscription object.
	 *
	 * @return int Parent order ID, or 0 when absent.
	 */
	private function get_parent_order_id( object $subscription ): int {
		$parent_id = $this->get_int_method_value( $subscription, 'get_parent_id' );

		if ( 0 !== $parent_id || ! \method_exists( $subscription, 'get_parent' ) ) {
			return $parent_id;
		}

		$parent = $subscription->get_parent();

		if ( \is_object( $parent ) ) {
			return $this->get_int_method_value( $parent, 'get_id' );
		}

		return $this->normalize_int( $parent );
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
	 * Normalize a status or key-like value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Source key.
	 *
	 * @return string Normalized key.
	 */
	private function normalize_key( string $value ): string {
		$value = \strtolower( \trim( $value ) );
		$value = (string) \preg_replace( '/^wc-/', '', $value );

		return (string) \preg_replace( '/[^a-z0-9_-]/', '', $value );
	}

	/**
	 * Normalize a currency code.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Source currency.
	 *
	 * @return string Three-character uppercase currency code.
	 */
	private function normalize_currency( string $value ): string {
		$value = \strtoupper( \trim( $value ) );
		$value = (string) \preg_replace( '/[^A-Z0-9]/', '', $value );

		return \substr( $value, 0, 3 );
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
