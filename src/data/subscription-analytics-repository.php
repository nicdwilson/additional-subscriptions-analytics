<?php
/**
 * Subscription analytics table repository.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Persists data into plugin-owned analytics lookup tables.
 *
 * @since 0.1.0
 */
final class SubscriptionAnalyticsRepository {

	/**
	 * Table name helper.
	 *
	 * @var TableNames
	 */
	private TableNames $table_names;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TableNames|null $table_names Optional table name helper.
	 */
	public function __construct( ?TableNames $table_names = null ) {
		$this->table_names = $table_names ?? new TableNames();
	}

	/**
	 * Upsert a subscription stats row.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|null> $row Stats row keyed by table column.
	 *
	 * @return bool True when the row was written.
	 */
	public function upsert_subscription_stats( array $row ): bool {
		global $wpdb;

		$prepared_row = $this->prepare_subscription_stats_row( $row );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->replace(
			$this->table_names->subscriptions_stats(),
			$prepared_row,
			$this->get_subscription_stats_formats()
		);

		return false !== $result;
	}

	/**
	 * Replace all product lookup rows for a subscription.
	 *
	 * @since 0.1.0
	 *
	 * @param int                                        $subscription_id Subscription ID.
	 * @param array<int, array<string, int|string|null>> $rows            Product lookup rows.
	 *
	 * @return int Number of rows inserted.
	 */
	public function replace_product_lookup_rows( int $subscription_id, array $rows ): int {
		global $wpdb;

		$subscription_id = \max( 0, $subscription_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$this->table_names->subscription_product_lookup(),
			array( 'subscription_id' => $subscription_id ),
			array( '%d' )
		);

		$inserted = 0;

		foreach ( $rows as $row ) {
			$prepared_row                    = $this->prepare_product_lookup_row( $row );
			$prepared_row['subscription_id'] = $subscription_id;

			if ( 0 >= $prepared_row['line_item_id'] ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->insert(
				$this->table_names->subscription_product_lookup(),
				$prepared_row,
				$this->get_product_lookup_formats()
			);

			if ( false !== $result ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Delete all analytics rows for a subscription.
	 *
	 * @since 0.1.0
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return void
	 */
	public function delete_subscription( int $subscription_id ): void {
		global $wpdb;

		$subscription_id = \max( 0, $subscription_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$this->table_names->subscription_product_lookup(),
			array( 'subscription_id' => $subscription_id ),
			array( '%d' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$this->table_names->subscriptions_stats(),
			array( 'subscription_id' => $subscription_id ),
			array( '%d' )
		);
	}

	/**
	 * Check whether a stats row exists for a subscription.
	 *
	 * @since 0.1.0
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return bool
	 */
	public function subscription_exists( int $subscription_id ): bool {
		global $wpdb;

		$stats_table = $this->table_names->subscriptions_stats();
		$query       = $wpdb->prepare(
			'SELECT subscription_id FROM %i WHERE subscription_id = %d LIMIT 1',
			$stats_table,
			\max( 0, $subscription_id )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var( $query );
	}

	/**
	 * Truncate plugin-owned analytics tables.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function truncate_tables(): void {
		global $wpdb;

		$product_lookup_table = $this->table_names->subscription_product_lookup();
		$stats_table          = $this->table_names->subscriptions_stats();

		$product_lookup_query = $wpdb->prepare( 'TRUNCATE TABLE %i', $product_lookup_table );
		$stats_query          = $wpdb->prepare( 'TRUNCATE TABLE %i', $stats_table );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $product_lookup_query );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $stats_query );
	}

	/**
	 * Find stale subscription analytics rows.
	 *
	 * @since 0.1.0
	 *
	 * @param string $before_gmt GMT timestamp threshold.
	 * @param int    $limit      Maximum number of IDs to return.
	 *
	 * @return array<int, int> Subscription IDs.
	 */
	public function find_stale_subscription_ids( string $before_gmt, int $limit = 100 ): array {
		global $wpdb;

		$stats_table = $this->table_names->subscriptions_stats();
		$limit       = \max( 1, \min( 1000, $limit ) );
		$query       = $wpdb->prepare(
			'SELECT subscription_id
			FROM %i
			WHERE synced_at_gmt IS NULL OR synced_at_gmt < %s
			ORDER BY subscription_id ASC
			LIMIT %d',
			$stats_table,
			$before_gmt,
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$subscription_ids = (array) $wpdb->get_col( $query );

		return \array_map(
			static function ( mixed $subscription_id ): int {
				return \max( 0, (int) $subscription_id );
			},
			$subscription_ids
		);
	}

	/**
	 * Delete product lookup rows without a matching stats row.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of rows deleted.
	 */
	public function cleanup_orphan_product_lookup_rows(): int {
		global $wpdb;

		$product_lookup_table = $this->table_names->subscription_product_lookup();
		$stats_table          = $this->table_names->subscriptions_stats();

		$query = $wpdb->prepare(
			'DELETE product_lookup
			FROM %i product_lookup
			LEFT JOIN %i stats
				ON stats.subscription_id = product_lookup.subscription_id
			WHERE stats.subscription_id IS NULL',
			$product_lookup_table,
			$stats_table
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( $query );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Normalize a stats row for database persistence.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|null> $row Source row.
	 *
	 * @return array<string, int|string|null>
	 */
	private function prepare_subscription_stats_row( array $row ): array {
		return array(
			'subscription_id'          => \max( 0, (int) ( $row['subscription_id'] ?? 0 ) ),
			'parent_order_id'          => \max( 0, (int) ( $row['parent_order_id'] ?? 0 ) ),
			'customer_id'              => \max( 0, (int) ( $row['customer_id'] ?? 0 ) ),
			'status'                   => $this->prepare_string( $row['status'] ?? '', 20 ),
			'date_created_gmt'         => $this->prepare_nullable_datetime( $row['date_created_gmt'] ?? null ),
			'date_updated_gmt'         => $this->prepare_nullable_datetime( $row['date_updated_gmt'] ?? null ),
			'start_date_gmt'           => $this->prepare_nullable_datetime( $row['start_date_gmt'] ?? null ),
			'trial_end_date_gmt'       => $this->prepare_nullable_datetime( $row['trial_end_date_gmt'] ?? null ),
			'last_payment_date_gmt'    => $this->prepare_nullable_datetime( $row['last_payment_date_gmt'] ?? null ),
			'next_payment_date_gmt'    => $this->prepare_nullable_datetime( $row['next_payment_date_gmt'] ?? null ),
			'end_date_gmt'             => $this->prepare_nullable_datetime( $row['end_date_gmt'] ?? null ),
			'billing_period'           => $this->prepare_string( $row['billing_period'] ?? '', 10 ),
			'billing_interval'         => \max( 1, (int) ( $row['billing_interval'] ?? 1 ) ),
			'recurring_total'          => $this->prepare_decimal( $row['recurring_total'] ?? '0' ),
			'recurring_tax_total'      => $this->prepare_decimal( $row['recurring_tax_total'] ?? '0' ),
			'recurring_shipping_total' => $this->prepare_decimal( $row['recurring_shipping_total'] ?? '0' ),
			'currency'                 => $this->prepare_string( $row['currency'] ?? '', 3 ),
			'payment_method'           => $this->prepare_string( $row['payment_method'] ?? '', 100 ),
			'synced_at_gmt'            => $this->prepare_nullable_datetime( $row['synced_at_gmt'] ?? null ),
		);
	}

	/**
	 * Normalize a product lookup row for database persistence.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|null> $row Source row.
	 *
	 * @return array<string, int|string|null>
	 */
	private function prepare_product_lookup_row( array $row ): array {
		return array(
			'subscription_id' => \max( 0, (int) ( $row['subscription_id'] ?? 0 ) ),
			'line_item_id'    => \max( 0, (int) ( $row['line_item_id'] ?? 0 ) ),
			'product_id'      => \max( 0, (int) ( $row['product_id'] ?? 0 ) ),
			'variation_id'    => \max( 0, (int) ( $row['variation_id'] ?? 0 ) ),
			'product_name'    => $this->prepare_string( $row['product_name'] ?? '', 255 ),
			'product_qty'     => $this->prepare_decimal( $row['product_qty'] ?? '0' ),
			'line_subtotal'   => $this->prepare_decimal( $row['line_subtotal'] ?? '0' ),
			'line_total'      => $this->prepare_decimal( $row['line_total'] ?? '0' ),
			'line_tax'        => $this->prepare_decimal( $row['line_tax'] ?? '0' ),
			'synced_at_gmt'   => $this->prepare_nullable_datetime( $row['synced_at_gmt'] ?? null ),
		);
	}

	/**
	 * Get stats row database formats.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	private function get_subscription_stats_formats(): array {
		return array(
			'%d',
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		);
	}

	/**
	 * Get product lookup row database formats.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	private function get_product_lookup_formats(): array {
		return array(
			'%d',
			'%d',
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		);
	}

	/**
	 * Prepare a bounded string value.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value  Source value.
	 * @param int   $length Maximum byte length.
	 *
	 * @return string
	 */
	private function prepare_string( mixed $value, int $length ): string {
		$value = \is_scalar( $value ) ? (string) $value : '';

		return \substr( $value, 0, $length );
	}

	/**
	 * Prepare a nullable datetime value.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Source value.
	 *
	 * @return string|null
	 */
	private function prepare_nullable_datetime( mixed $value ): ?string {
		if ( null === $value || '' === $value || '0' === $value || 0 === $value ) {
			return null;
		}

		return $this->prepare_string( $value, 19 );
	}

	/**
	 * Prepare a decimal string.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Source value.
	 *
	 * @return string
	 */
	private function prepare_decimal( mixed $value ): string {
		if ( \function_exists( 'wc_format_decimal' ) ) {
			return \wc_format_decimal( $value, 8 );
		}

		if ( \is_string( $value ) ) {
			$value = \str_replace( ',', '', $value );
		}

		return \number_format( (float) $value, 8, '.', '' );
	}
}
