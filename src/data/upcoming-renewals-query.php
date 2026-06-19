<?php
/**
 * Upcoming renewals report query.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Queries aggregate upcoming renewal rows from plugin-owned lookup tables.
 *
 * @since 0.1.0
 */
final class UpcomingRenewalsQuery {

	/**
	 * Default page size.
	 *
	 * @since 0.1.0
	 */
	private const DEFAULT_PER_PAGE = 10;

	/**
	 * Maximum page size for interactive report requests.
	 *
	 * @since 0.1.0
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Table name helper.
	 *
	 * @var TableNames
	 */
	private TableNames $table_names;

	/**
	 * Date window helper.
	 *
	 * @var DateWindow
	 */
	private DateWindow $date_window;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TableNames|null $table_names Optional table name helper.
	 * @param DateWindow|null $date_window Optional date window helper.
	 */
	public function __construct( ?TableNames $table_names = null, ?DateWindow $date_window = null ) {
		$this->table_names = $table_names ?? new TableNames();
		$this->date_window = $date_window ?? new DateWindow();
	}

	/**
	 * Query upcoming renewal product aggregates.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return array{data: array<int, array<string, int|string>>, totals: array<string, int|string>, total: int, pages: int, page_no: int}
	 */
	public function get_data( array $args ): array {
		$query_args = $this->normalize_args( $args );
		$total      = $this->get_total_count( $query_args );

		return array(
			'data'    => $this->get_rows( $query_args ),
			'totals'  => $this->get_totals( $query_args ),
			'total'   => $total,
			'pages'   => 0 === $total ? 0 : (int) \ceil( $total / $query_args['per_page'] ),
			'page_no' => $query_args['page'],
		);
	}

	/**
	 * Normalize query arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Raw query arguments.
	 *
	 * @return array{after_gmt: string, before_gmt: string, statuses: array<int, string>, page: int, per_page: int, offset: int, orderby: string, order: string}
	 */
	private function normalize_args( array $args ): array {
		$window   = $this->date_window->analytics_range_to_gmt_window( $args['after'] ?? null, $args['before'] ?? null );
		$page     = \max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = \max( 1, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) );

		if ( ! $this->is_export_request( $args ) ) {
			$per_page = \min( self::MAX_PER_PAGE, $per_page );
		}

		return array(
			'after_gmt'  => $window['start'],
			'before_gmt' => $window['end'],
			'statuses'   => $this->normalize_statuses( $args['status'] ?? ( $args['statuses'] ?? 'active' ) ),
			'page'       => $page,
			'per_page'   => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
			'orderby'    => $this->normalize_orderby( $args['orderby'] ?? 'product_name' ),
			'order'      => $this->normalize_order( $args['order'] ?? 'asc' ),
		);
	}

	/**
	 * Query aggregate rows.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return array<int, array<string, int|string>>
	 */
	private function get_rows( array $query_args ): array {
		global $wpdb;

		$query = $this->get_select_sql( $query_args );
		$args  = $this->get_select_args( $query_args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $query, ...$args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $sql );

		return \array_map( array( $this, 'normalize_result_row' ), $rows );
	}

	/**
	 * Query the total number of aggregate groups.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return int
	 */
	private function get_total_count( array $query_args ): int {
		global $wpdb;

		$query = 'SELECT COUNT(*)
			FROM (
				SELECT product_lookup.product_id, product_lookup.variation_id, stats.currency
				' . $this->get_from_where_sql( $query_args ) . '
				GROUP BY product_lookup.product_id, product_lookup.variation_id, stats.currency
			) grouped';
		$args  = $this->get_base_args( $query_args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare( $query, ...$args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return \max( 0, (int) $wpdb->get_var( $sql ) );
	}

	/**
	 * Query report-wide totals.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return array<string, int|string>
	 */
	private function get_totals( array $query_args ): array {
		global $wpdb;

		$query = 'SELECT
				COALESCE(SUM(product_lookup.product_qty), 0) AS total_quantity,
				COUNT(DISTINCT stats.subscription_id) AS subscriptions_count,
				COALESCE(SUM(product_lookup.line_total), 0) AS recurring_total
			' . $this->get_from_where_sql( $query_args );
		$args  = $this->get_base_args( $query_args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare( $query, ...$args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $sql );

		if ( ! \is_object( $row ) ) {
			return array(
				'total_quantity'      => '0.00000000',
				'subscriptions_count' => 0,
				'recurring_total'     => '0.00000000',
			);
		}

		return array(
			'total_quantity'      => $this->format_decimal( $row->total_quantity ?? '0' ),
			'subscriptions_count' => \max( 0, (int) ( $row->subscriptions_count ?? 0 ) ),
			'recurring_total'     => $this->format_decimal( $row->recurring_total ?? '0' ),
		);
	}

	/**
	 * Build the select SQL.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return string SQL with placeholders.
	 */
	private function get_select_sql( array $query_args ): string {
		return 'SELECT
				product_lookup.product_id,
				product_lookup.variation_id,
				MAX(product_lookup.product_name) AS product_name,
				stats.currency,
				COALESCE(SUM(product_lookup.product_qty), 0) AS total_quantity,
				COUNT(DISTINCT stats.subscription_id) AS subscriptions_count,
				COALESCE(SUM(product_lookup.line_total), 0) AS recurring_total,
				MIN(stats.next_payment_date_gmt) AS first_next_payment_date_gmt,
				MAX(stats.next_payment_date_gmt) AS last_next_payment_date_gmt
			' . $this->get_from_where_sql( $query_args ) . '
			GROUP BY product_lookup.product_id, product_lookup.variation_id, stats.currency
			ORDER BY ' . $this->get_orderby_sql( $query_args['orderby'] ) . ' ' . $query_args['order'] . '
			LIMIT %d OFFSET %d';
	}

	/**
	 * Build common FROM and WHERE SQL.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return string SQL with placeholders.
	 */
	private function get_from_where_sql( array $query_args ): string {
		$status_sql = '';

		if ( array() !== $query_args['statuses'] ) {
			$status_sql = ' AND stats.status IN (' . \implode( ', ', \array_fill( 0, \count( $query_args['statuses'] ), '%s' ) ) . ')';
		}

		return 'FROM %i product_lookup
			INNER JOIN %i stats
				ON stats.subscription_id = product_lookup.subscription_id
			WHERE stats.next_payment_date_gmt IS NOT NULL
				AND stats.next_payment_date_gmt >= %s
				AND stats.next_payment_date_gmt < %s' . $status_sql;
	}

	/**
	 * Get placeholder args for SELECT queries.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return array<int, mixed>
	 */
	private function get_select_args( array $query_args ): array {
		return \array_merge(
			$this->get_base_args( $query_args ),
			array(
				$query_args['per_page'],
				$query_args['offset'],
			)
		);
	}

	/**
	 * Get common placeholder args.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return array<int, mixed>
	 */
	private function get_base_args( array $query_args ): array {
		return \array_merge(
			array(
				$this->table_names->subscription_product_lookup(),
				$this->table_names->subscriptions_stats(),
				$query_args['after_gmt'],
				$query_args['before_gmt'],
			),
			$query_args['statuses']
		);
	}

	/**
	 * Normalize statuses for safe SQL placeholders.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $status_arg Raw status or statuses.
	 *
	 * @return array<int, string> Sanitized statuses, or empty for any status.
	 */
	private function normalize_statuses( mixed $status_arg ): array {
		$statuses = \is_array( $status_arg ) ? $status_arg : \explode( ',', (string) $status_arg );
		$statuses = \array_values(
			\array_filter(
				\array_map(
					function ( mixed $status ): string {
						$status = \strtolower( \trim( (string) $status ) );
						$status = (string) \preg_replace( '/^wc-/', '', $status );

						if ( \function_exists( 'sanitize_key' ) ) {
							return \sanitize_key( $status );
						}

						return (string) \preg_replace( '/[^a-z0-9_-]/', '', $status );
					},
					$statuses
				)
			)
		);

		if ( \in_array( 'any', $statuses, true ) ) {
			return array();
		}

		if ( array() === $statuses ) {
			return array( 'active' );
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Normalize the order by field.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $orderby Raw orderby field.
	 *
	 * @return string Allowed orderby field key.
	 */
	private function normalize_orderby( mixed $orderby ): string {
		$orderby = \strtolower( \trim( (string) $orderby ) );

		return \array_key_exists( $orderby, $this->get_orderby_map() ) ? $orderby : 'product_name';
	}

	/**
	 * Normalize sort direction.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $order Raw order direction.
	 *
	 * @return string SQL sort direction.
	 */
	private function normalize_order( mixed $order ): string {
		return 'desc' === \strtolower( \trim( (string) $order ) ) ? 'DESC' : 'ASC';
	}

	/**
	 * Get SQL for an allowed orderby field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $orderby Orderby field.
	 *
	 * @return string Safe SQL expression.
	 */
	private function get_orderby_sql( string $orderby ): string {
		return $this->get_orderby_map()[ $orderby ];
	}

	/**
	 * Get allowed orderby field map.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	private function get_orderby_map(): array {
		return array(
			'product_name'                => 'product_name',
			'product_id'                  => 'product_lookup.product_id',
			'variation_id'                => 'product_lookup.variation_id',
			'total_quantity'              => 'total_quantity',
			'quantity'                    => 'total_quantity',
			'subscriptions_count'         => 'subscriptions_count',
			'recurring_total'             => 'recurring_total',
			'first_next_payment_date_gmt' => 'first_next_payment_date_gmt',
			'last_next_payment_date_gmt'  => 'last_next_payment_date_gmt',
		);
	}

	/**
	 * Determine whether a request is for CSV/export batching.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return bool True for export-style requests.
	 */
	private function is_export_request( array $args ): bool {
		return ! empty( $args['export'] ) || ! empty( $args['download'] ) || ! empty( $args['csv_export'] );
	}

	/**
	 * Normalize one database result row.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $row Raw database row.
	 *
	 * @return array<string, int|string>
	 */
	private function normalize_result_row( mixed $row ): array {
		$row = \is_object( $row ) ? $row : (object) array();

		return array(
			'product_id'                  => \max( 0, (int) ( $row->product_id ?? 0 ) ),
			'variation_id'                => \max( 0, (int) ( $row->variation_id ?? 0 ) ),
			'product_name'                => (string) ( $row->product_name ?? '' ),
			'currency'                    => (string) ( $row->currency ?? '' ),
			'total_quantity'              => $this->format_decimal( $row->total_quantity ?? '0' ),
			'subscriptions_count'         => \max( 0, (int) ( $row->subscriptions_count ?? 0 ) ),
			'recurring_total'             => $this->format_decimal( $row->recurring_total ?? '0' ),
			'first_next_payment_date_gmt' => (string) ( $row->first_next_payment_date_gmt ?? '' ),
			'last_next_payment_date_gmt'  => (string) ( $row->last_next_payment_date_gmt ?? '' ),
		);
	}

	/**
	 * Format a decimal value for report output.
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
