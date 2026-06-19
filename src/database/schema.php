<?php
/**
 * Database schema definitions.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Provides authoritative table names and schema SQL.
 *
 * @since 0.1.0
 */
final class Schema {

	/**
	 * Current plugin database schema version.
	 *
	 * @since 0.1.0
	 */
	public const DB_VERSION = '1.0.0';

	/**
	 * Subscription stats table suffix.
	 *
	 * @since 0.1.0
	 */
	public const SUBSCRIPTIONS_STATS_TABLE = 'wc_subscriptions_stats';

	/**
	 * Subscription product lookup table suffix.
	 *
	 * @since 0.1.0
	 */
	public const SUBSCRIPTION_PRODUCT_LOOKUP_TABLE = 'wc_subscription_product_lookup';

	/**
	 * Get plugin-owned table names.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix WordPress database table prefix.
	 *
	 * @return array<string, string> Table names keyed by logical table identifier.
	 */
	public static function get_table_names( string $prefix ): array {
		return array(
			'subscriptions_stats'         => $prefix . self::SUBSCRIPTIONS_STATS_TABLE,
			'subscription_product_lookup' => $prefix . self::SUBSCRIPTION_PRODUCT_LOOKUP_TABLE,
		);
	}

	/**
	 * Get full schema SQL for dbDelta.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix          WordPress database table prefix.
	 * @param string $charset_collate Database charset/collation clause.
	 *
	 * @return string SQL containing all CREATE TABLE statements.
	 */
	public static function get_schema_sql( string $prefix, string $charset_collate ): string {
		return implode( "\n\n", self::get_table_schema_sql( $prefix, $charset_collate ) );
	}

	/**
	 * Get table-level schema SQL.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix          WordPress database table prefix.
	 * @param string $charset_collate Database charset/collation clause.
	 *
	 * @return array<string, string> CREATE TABLE statements keyed by logical table identifier.
	 */
	public static function get_table_schema_sql( string $prefix, string $charset_collate ): array {
		$tables = self::get_table_names( $prefix );

		return array(
			'subscriptions_stats'         => self::get_subscriptions_stats_schema_sql(
				$tables['subscriptions_stats'],
				$charset_collate
			),
			'subscription_product_lookup' => self::get_subscription_product_lookup_schema_sql(
				$tables['subscription_product_lookup'],
				$charset_collate
			),
		);
	}

	/**
	 * Get the subscription stats table schema.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table_name      Full database table name.
	 * @param string $charset_collate Database charset/collation clause.
	 *
	 * @return string CREATE TABLE statement.
	 */
	private static function get_subscriptions_stats_schema_sql( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
	subscription_id bigint(20) unsigned NOT NULL,
	parent_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
	customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
	status varchar(20) NOT NULL DEFAULT '',
	date_created_gmt datetime DEFAULT NULL,
	date_updated_gmt datetime DEFAULT NULL,
	start_date_gmt datetime DEFAULT NULL,
	trial_end_date_gmt datetime DEFAULT NULL,
	last_payment_date_gmt datetime DEFAULT NULL,
	next_payment_date_gmt datetime DEFAULT NULL,
	end_date_gmt datetime DEFAULT NULL,
	billing_period varchar(10) NOT NULL DEFAULT '',
	billing_interval smallint(5) unsigned NOT NULL DEFAULT 1,
	recurring_total decimal(26,8) NOT NULL DEFAULT 0,
	recurring_tax_total decimal(26,8) NOT NULL DEFAULT 0,
	recurring_shipping_total decimal(26,8) NOT NULL DEFAULT 0,
	currency char(3) NOT NULL DEFAULT '',
	payment_method varchar(100) NOT NULL DEFAULT '',
	synced_at_gmt datetime DEFAULT NULL,
	PRIMARY KEY  (subscription_id),
	KEY status_next_payment (status,next_payment_date_gmt,subscription_id),
	KEY next_payment (next_payment_date_gmt),
	KEY customer (customer_id),
	KEY synced (synced_at_gmt)
) {$charset_collate};";
	}

	/**
	 * Get the subscription product lookup table schema.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table_name      Full database table name.
	 * @param string $charset_collate Database charset/collation clause.
	 *
	 * @return string CREATE TABLE statement.
	 */
	private static function get_subscription_product_lookup_schema_sql( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
	subscription_id bigint(20) unsigned NOT NULL,
	line_item_id bigint(20) unsigned NOT NULL,
	product_id bigint(20) unsigned NOT NULL DEFAULT 0,
	variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
	product_name varchar(255) NOT NULL DEFAULT '',
	product_qty decimal(26,8) NOT NULL DEFAULT 0,
	line_subtotal decimal(26,8) NOT NULL DEFAULT 0,
	line_total decimal(26,8) NOT NULL DEFAULT 0,
	line_tax decimal(26,8) NOT NULL DEFAULT 0,
	synced_at_gmt datetime DEFAULT NULL,
	PRIMARY KEY  (subscription_id,line_item_id),
	KEY subscription (subscription_id),
	KEY product_variation (product_id,variation_id),
	KEY product (product_id)
) {$charset_collate};";
	}
}
