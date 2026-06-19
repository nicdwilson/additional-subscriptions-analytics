<?php
/**
 * Analytics table name helper.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Data;

use AdditionalSubscriptionsAnalytics\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes plugin-owned database table names.
 *
 * @since 0.1.0
 */
final class TableNames {

	/**
	 * Optional database prefix override.
	 *
	 * @var string|null
	 */
	private ?string $prefix;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $prefix Optional WordPress database prefix override.
	 */
	public function __construct( ?string $prefix = null ) {
		$this->prefix = $prefix;
	}

	/**
	 * Get the subscription stats table name.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function subscriptions_stats(): string {
		return $this->get_table_names()['subscriptions_stats'];
	}

	/**
	 * Get the subscription product lookup table name.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function subscription_product_lookup(): string {
		return $this->get_table_names()['subscription_product_lookup'];
	}

	/**
	 * Get all plugin-owned table names.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public function all(): array {
		return $this->get_table_names();
	}

	/**
	 * Resolve table names for the current prefix.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	private function get_table_names(): array {
		return Schema::get_table_names( $this->get_prefix() );
	}

	/**
	 * Resolve the WordPress database prefix.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function get_prefix(): string {
		if ( null !== $this->prefix ) {
			return $this->prefix;
		}

		global $wpdb;

		return (string) $wpdb->prefix;
	}
}
