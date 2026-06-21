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
	 * Renewal occurrence calculator.
	 *
	 * @var RenewalOccurrenceCalculator
	 */
	private RenewalOccurrenceCalculator $renewal_occurrences;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 * @since 0.9.4 Added renewal occurrence calculator dependency.
	 *
	 * @param TableNames|null                  $table_names         Optional table name helper.
	 * @param DateWindow|null                  $date_window         Optional date window helper.
	 * @param RenewalOccurrenceCalculator|null $renewal_occurrences Optional renewal occurrence calculator.
	 */
	public function __construct(
		?TableNames $table_names = null,
		?DateWindow $date_window = null,
		?RenewalOccurrenceCalculator $renewal_occurrences = null
	) {
		$this->table_names         = $table_names ?? new TableNames();
		$this->date_window         = $date_window ?? new DateWindow();
		$this->renewal_occurrences = $renewal_occurrences ?? new RenewalOccurrenceCalculator();
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
		$query_args     = $this->normalize_args( $args );
		$aggregate_rows = $this->get_aggregate_rows( $query_args );
		$public_rows    = $this->get_public_aggregate_rows( $aggregate_rows );
		$total          = \count( $public_rows );

		return array(
			'data'    => \array_slice( $public_rows, $query_args['offset'], $query_args['per_page'] ),
			'totals'  => $this->get_totals_from_aggregate_rows( $aggregate_rows ),
			'total'   => $total,
			'pages'   => 0 === $total ? 0 : (int) \ceil( $total / $query_args['per_page'] ),
			'page_no' => $query_args['page'],
		);
	}

	/**
	 * Query upcoming renewal stats for WooCommerce Analytics charts.
	 *
	 * @since 0.9.2
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return array{totals: array<string, int|string>, intervals: array<int, array<string, mixed>>, total: int, pages: int, page_no: int}
	 */
	public function get_stats_data( array $args ): array {
		$query_args = $this->normalize_args( $args );
		$interval   = $this->normalize_interval( $args['interval'] ?? 'day' );
		$buckets    = $this->get_interval_buckets( $query_args, $interval );
		$totals     = $this->get_empty_stats_totals();

		foreach ( $this->get_subscription_stats_rows( $query_args ) as $row ) {
			$occurrences = $this->renewal_occurrences->get_occurrences(
				$row,
				$query_args['after_gmt'],
				$query_args['before_gmt']
			);

			foreach ( $occurrences as $occurrence ) {
				$bucket_key = $this->get_bucket_key_for_row(
					array_merge( $row, array( 'next_payment_date_gmt' => $occurrence ) ),
					$buckets
				);

				if ( null === $bucket_key ) {
					continue;
				}

				$buckets[ $bucket_key ]['subtotals']['renewals_count']  += 1;
				$buckets[ $bucket_key ]['subtotals']['renewal_quantity'] = $this->add_decimal_strings(
					$buckets[ $bucket_key ]['subtotals']['renewal_quantity'],
					$row['renewal_quantity']
				);
				$buckets[ $bucket_key ]['subtotals']['recurring_total']  = $this->add_decimal_strings(
					$buckets[ $bucket_key ]['subtotals']['recurring_total'],
					$row['recurring_total']
				);
				$totals['renewals_count']                               += 1;
				$totals['renewal_quantity']                              = $this->add_decimal_strings(
					$totals['renewal_quantity'],
					$row['renewal_quantity']
				);
				$totals['recurring_total']                               = $this->add_decimal_strings(
					$totals['recurring_total'],
					$row['recurring_total']
				);
			}
		}

		$intervals = array_values(
			array_map(
				function ( array $bucket ): array {
					$bucket['subtotals']['renewal_quantity'] = $this->format_decimal( $bucket['subtotals']['renewal_quantity'] );
					$bucket['subtotals']['recurring_total']  = $this->format_decimal( $bucket['subtotals']['recurring_total'] );

					return $bucket;
				},
				$buckets
			)
		);

		$totals['renewal_quantity'] = $this->format_decimal( $totals['renewal_quantity'] );
		$totals['recurring_total']  = $this->format_decimal( $totals['recurring_total'] );

		return array(
			'totals'    => $totals,
			'intervals' => $intervals,
			'total'     => \count( $intervals ),
			'pages'     => 1,
			'page_no'   => 1,
		);
	}

	/**
	 * Normalize query arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Raw query arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function normalize_args( array $args ): array {
		$local_window        = $this->date_window->analytics_range_to_local_window( $args['after'] ?? null, $args['before'] ?? null );
		$gmt_window          = $this->date_window->analytics_range_to_gmt_window( $args['after'] ?? null, $args['before'] ?? null );
		$page                = \max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page            = \max( 1, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) );
		$has_legacy_statuses = \array_key_exists( 'status', $args ) || \array_key_exists( 'statuses', $args );
		$statuses            = $has_legacy_statuses
			? $this->normalize_statuses( $args['status'] ?? $args['statuses'] ?? 'active' )
			: array();
		$status_is           = $this->normalize_statuses( $args['status_is'] ?? array(), false );
		$status_is_not       = $this->normalize_statuses( $args['status_is_not'] ?? array(), false );

		if ( ! $this->is_export_request( $args ) ) {
			$per_page = \min( self::MAX_PER_PAGE, $per_page );
		}

		if ( ! $has_legacy_statuses && array() === $status_is && array() === $status_is_not ) {
			$statuses = array( 'active' );
		}

		return array(
			'after_gmt'          => $gmt_window['start'],
			'before_gmt'         => $gmt_window['end'],
			'after_local'        => $local_window['start'],
			'before_local'       => $local_window['end'],
			'statuses'           => $statuses,
			'status_is'          => $status_is,
			'status_is_not'      => $status_is_not,
			'product_includes'   => $this->normalize_id_list( $args['product_includes'] ?? array() ),
			'product_excludes'   => $this->normalize_id_list( $args['product_excludes'] ?? array() ),
			'variation_includes' => $this->normalize_id_list( $args['variation_includes'] ?? array() ),
			'variation_excludes' => $this->normalize_id_list( $args['variation_excludes'] ?? array() ),
			'match'              => $this->normalize_match( $args['match'] ?? 'all' ),
			'page'               => $page,
			'per_page'           => $per_page,
			'offset'             => ( $page - 1 ) * $per_page,
			'orderby'            => $this->normalize_orderby( $args['orderby'] ?? 'product_name' ),
			'order'              => $this->normalize_order( $args['order'] ?? 'asc' ),
		);
	}

	/**
	 * Build aggregate report rows from candidate subscription product rows.
	 *
	 * @since 0.9.4
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_aggregate_rows( array $query_args ): array {
		$rows = array();

		foreach ( $this->get_candidate_product_rows( $query_args ) as $candidate ) {
			$occurrences = $this->renewal_occurrences->get_occurrences(
				$candidate,
				$query_args['after_gmt'],
				$query_args['before_gmt']
			);

			if ( array() === $occurrences ) {
				continue;
			}

			$key = $this->get_aggregate_key( $candidate );

			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'product_id'                  => $candidate['product_id'],
					'variation_id'                => $candidate['variation_id'],
					'product_name'                => $candidate['product_name'],
					'currency'                    => $candidate['currency'],
					'total_quantity'              => '0.00000000',
					'subscriptions_count'         => 0,
					'recurring_total'             => '0.00000000',
					'first_next_payment_date_gmt' => $occurrences[0],
					'last_next_payment_date_gmt'  => $occurrences[ \count( $occurrences ) - 1 ],
					'subscription_ids'            => array(),
				);
			}

			$rows[ $key ]['product_name']                = \max( $rows[ $key ]['product_name'], $candidate['product_name'] );
			$rows[ $key ]['total_quantity']              = $this->add_decimal_strings(
				(string) $rows[ $key ]['total_quantity'],
				$this->multiply_decimal_string( $candidate['product_qty'], \count( $occurrences ) )
			);
			$rows[ $key ]['recurring_total']             = $this->add_decimal_strings(
				(string) $rows[ $key ]['recurring_total'],
				$this->multiply_decimal_string( $candidate['line_total'], \count( $occurrences ) )
			);
			$rows[ $key ]['first_next_payment_date_gmt'] = \min(
				(string) $rows[ $key ]['first_next_payment_date_gmt'],
				$occurrences[0]
			);
			$rows[ $key ]['last_next_payment_date_gmt']  = \max(
				(string) $rows[ $key ]['last_next_payment_date_gmt'],
				$occurrences[ \count( $occurrences ) - 1 ]
			);

			if ( 0 !== $candidate['subscription_id'] ) {
				$rows[ $key ]['subscription_ids'][ $candidate['subscription_id'] ] = true;
			}
		}

		foreach ( $rows as $key => $row ) {
			$rows[ $key ]['subscriptions_count'] = \count( (array) $row['subscription_ids'] );
		}

		return $this->sort_aggregate_rows( \array_values( $rows ), $query_args );
	}

	/**
	 * Query candidate product rows that could renew in the report window.
	 *
	 * @since 0.9.4
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return array<int, array<string, int|string|null>>
	 */
	private function get_candidate_product_rows( array $query_args ): array {
		global $wpdb;

		$query = 'SELECT
				stats.subscription_id,
				stats.next_payment_date_gmt,
				stats.end_date_gmt,
				stats.billing_period,
				stats.billing_interval,
				stats.currency,
				product_lookup.product_id,
				product_lookup.variation_id,
				product_lookup.product_name,
				product_lookup.product_qty,
				product_lookup.line_total
			' . $this->get_from_where_sql( $query_args ) . '
			ORDER BY stats.next_payment_date_gmt ASC, product_lookup.product_id ASC, product_lookup.variation_id ASC';
		$args  = $this->get_base_args( $query_args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare( $query, ...$args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $sql );

		return \array_map( array( $this, 'normalize_candidate_product_row' ), $rows );
	}

	/**
	 * Build report-wide totals from aggregate rows.
	 *
	 * @since 0.9.4
	 *
	 * @param array<int, array<string, mixed>> $rows Aggregate rows.
	 *
	 * @return array<string, int|string>
	 */
	private function get_totals_from_aggregate_rows( array $rows ): array {
		$total_quantity   = '0.00000000';
		$recurring_total  = '0.00000000';
		$subscription_ids = array();

		foreach ( $rows as $row ) {
			$total_quantity  = $this->add_decimal_strings( $total_quantity, (string) ( $row['total_quantity'] ?? '0' ) );
			$recurring_total = $this->add_decimal_strings( $recurring_total, (string) ( $row['recurring_total'] ?? '0' ) );

			foreach ( (array) ( $row['subscription_ids'] ?? array() ) as $subscription_id => $matched ) {
				if ( $matched ) {
					$subscription_ids[ \max( 0, (int) $subscription_id ) ] = true;
				}
			}
		}

		return array(
			'total_quantity'      => $this->format_decimal( $total_quantity ),
			'subscriptions_count' => \count( $subscription_ids ),
			'recurring_total'     => $this->format_decimal( $recurring_total ),
		);
	}

	/**
	 * Remove internal aggregate state before returning rows.
	 *
	 * @since 0.9.4
	 *
	 * @param array<int, array<string, mixed>> $rows Aggregate rows.
	 *
	 * @return array<int, array<string, int|string>>
	 */
	private function get_public_aggregate_rows( array $rows ): array {
		foreach ( $rows as $index => $row ) {
			unset( $row['subscription_ids'] );
			$rows[ $index ] = $row;
		}

		return $rows;
	}

	/**
	 * Normalize one candidate product row.
	 *
	 * @since 0.9.4
	 *
	 * @param mixed $row Raw database row.
	 *
	 * @return array<string, int|string|null>
	 */
	private function normalize_candidate_product_row( mixed $row ): array {
		$row = \is_object( $row ) ? $row : (object) array();

		return array(
			'subscription_id'       => \max( 0, (int) ( $row->subscription_id ?? 0 ) ),
			'next_payment_date_gmt' => (string) ( $row->next_payment_date_gmt ?? '' ),
			'end_date_gmt'          => null === ( $row->end_date_gmt ?? null ) ? null : (string) $row->end_date_gmt,
			'billing_period'        => (string) ( $row->billing_period ?? '' ),
			'billing_interval'      => \max( 1, (int) ( $row->billing_interval ?? 1 ) ),
			'currency'              => (string) ( $row->currency ?? '' ),
			'product_id'            => \max( 0, (int) ( $row->product_id ?? 0 ) ),
			'variation_id'          => \max( 0, (int) ( $row->variation_id ?? 0 ) ),
			'product_name'          => (string) ( $row->product_name ?? '' ),
			'product_qty'           => $this->format_decimal( $row->product_qty ?? '0' ),
			'line_total'            => $this->format_decimal( $row->line_total ?? '0' ),
		);
	}

	/**
	 * Get the product/currency aggregate key for a candidate row.
	 *
	 * @since 0.9.4
	 *
	 * @param array<string, int|string|null> $row Candidate row.
	 *
	 * @return string Aggregate key.
	 */
	private function get_aggregate_key( array $row ): string {
		return (int) $row['product_id'] . ':' . (int) $row['variation_id'] . ':' . (string) $row['currency'];
	}

	/**
	 * Sort aggregate rows using the normalized report order.
	 *
	 * @since 0.9.4
	 *
	 * @param array<int, array<string, mixed>> $rows       Aggregate rows.
	 * @param array<string, mixed>             $query_args Normalized query arguments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sort_aggregate_rows( array $rows, array $query_args ): array {
		$orderby = $this->get_orderby_field( (string) $query_args['orderby'] );
		$order   = (string) $query_args['order'];

		\usort(
			$rows,
			function ( array $left, array $right ) use ( $orderby, $order ): int {
				$comparison = $this->compare_aggregate_values( $left[ $orderby ] ?? '', $right[ $orderby ] ?? '', $orderby );

				if ( 0 === $comparison ) {
					$comparison = $this->compare_aggregate_values( $left['product_name'] ?? '', $right['product_name'] ?? '', 'product_name' );
				}

				if ( 0 === $comparison ) {
					$comparison = $this->compare_aggregate_values( $left['product_id'] ?? 0, $right['product_id'] ?? 0, 'product_id' );
				}

				return 'DESC' === $order ? -$comparison : $comparison;
			}
		);

		return $rows;
	}

	/**
	 * Compare aggregate row values for sorting.
	 *
	 * @since 0.9.4
	 *
	 * @param mixed  $left  Left value.
	 * @param mixed  $right Right value.
	 * @param string $field Sort field.
	 *
	 * @return int Comparison result.
	 */
	private function compare_aggregate_values( mixed $left, mixed $right, string $field ): int {
		if ( \in_array( $field, array( 'product_id', 'variation_id', 'total_quantity', 'subscriptions_count', 'recurring_total' ), true ) ) {
			return (float) $left <=> (float) $right;
		}

		return \strnatcasecmp( (string) $left, (string) $right );
	}

	/**
	 * Multiply a decimal string by an integer.
	 *
	 * @since 0.9.4
	 *
	 * @param string $value      Decimal value.
	 * @param int    $multiplier Multiplier.
	 *
	 * @return string Product decimal.
	 */
	private function multiply_decimal_string( string $value, int $multiplier ): string {
		return $this->format_decimal( (float) $value * \max( 0, $multiplier ) );
	}

	/**
	 * Query per-subscription stats rows for interval bucketing.
	 *
	 * @since 0.9.2
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return array<int, array<string, int|string|null>>
	 */
	private function get_subscription_stats_rows( array $query_args ): array {
		global $wpdb;

		$query = 'SELECT
				stats.subscription_id,
				stats.next_payment_date_gmt,
				stats.end_date_gmt,
				stats.billing_period,
				stats.billing_interval,
				COALESCE(SUM(product_lookup.product_qty), 0) AS renewal_quantity,
				COALESCE(SUM(product_lookup.line_total), 0) AS recurring_total
			' . $this->get_from_where_sql( $query_args ) . '
			GROUP BY stats.subscription_id, stats.next_payment_date_gmt, stats.end_date_gmt, stats.billing_period, stats.billing_interval
			ORDER BY stats.next_payment_date_gmt ASC';
		$args  = $this->get_base_args( $query_args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare( $query, ...$args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $sql );

		return \array_map(
			function ( mixed $row ): array {
				$row = \is_object( $row ) ? $row : (object) array();

				return array(
					'subscription_id'       => \max( 0, (int) ( $row->subscription_id ?? 0 ) ),
					'next_payment_date_gmt' => (string) ( $row->next_payment_date_gmt ?? '' ),
					'end_date_gmt'          => null === ( $row->end_date_gmt ?? null ) ? null : (string) $row->end_date_gmt,
					'billing_period'        => (string) ( $row->billing_period ?? '' ),
					'billing_interval'      => \max( 1, (int) ( $row->billing_interval ?? 1 ) ),
					'renewal_quantity'      => $this->format_decimal( $row->renewal_quantity ?? '0' ),
					'recurring_total'       => $this->format_decimal( $row->recurring_total ?? '0' ),
				);
			},
			$rows
		);
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
		return 'FROM %i product_lookup
			INNER JOIN %i stats
				ON stats.subscription_id = product_lookup.subscription_id
			WHERE stats.next_payment_date_gmt IS NOT NULL
				AND stats.next_payment_date_gmt < %s
				AND (stats.end_date_gmt IS NULL OR stats.end_date_gmt > %s)' . $this->get_filter_sql( $query_args );
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
				$query_args['before_gmt'],
				$query_args['after_gmt'],
			),
			$this->get_filter_args( $query_args )
		);
	}

	/**
	 * Build SQL for normalized filters.
	 *
	 * @since 0.9.2
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return string SQL with placeholders.
	 */
	private function get_filter_sql( array $query_args ): string {
		$base_clauses     = $this->get_base_filter_clauses( $query_args );
		$advanced_clauses = $this->get_advanced_filter_clauses( $query_args );
		$sql              = '';

		if ( array() !== $base_clauses ) {
			$sql .= ' AND ' . \implode( ' AND ', \array_column( $base_clauses, 'sql' ) );
		}

		if ( array() !== $advanced_clauses ) {
			$glue = 'any' === $query_args['match'] ? ' OR ' : ' AND ';
			$sql .= ' AND (' . \implode( $glue, \array_column( $advanced_clauses, 'sql' ) ) . ')';
		}

		return $sql;
	}

	/**
	 * Get placeholder args for normalized filters.
	 *
	 * @since 0.9.2
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return array<int, mixed>
	 */
	private function get_filter_args( array $query_args ): array {
		$args = array();

		foreach ( \array_merge( $this->get_base_filter_clauses( $query_args ), $this->get_advanced_filter_clauses( $query_args ) ) as $clause ) {
			$args = \array_merge( $args, $clause['args'] );
		}

		return $args;
	}

	/**
	 * Get base filter SQL clauses and args.
	 *
	 * @since 0.9.2
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return array<int, array{sql: string, args: array<int, mixed>}>
	 */
	private function get_base_filter_clauses( array $query_args ): array {
		$statuses = $query_args['statuses'] ?? array();

		if ( ! \is_array( $statuses ) || array() === $statuses ) {
			return array();
		}

		return array(
			array(
				'sql'  => 'stats.status IN (' . \implode( ', ', \array_fill( 0, \count( $statuses ), '%s' ) ) . ')',
				'args' => $statuses,
			),
		);
	}

	/**
	 * Get advanced filter SQL clauses and args.
	 *
	 * @since 0.9.2
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 *
	 * @return array<int, array{sql: string, args: array<int, mixed>}>
	 */
	private function get_advanced_filter_clauses( array $query_args ): array {
		$clauses    = array();
		$filter_map = array(
			'status_is'          => array(
				'column'      => 'stats.status',
				'operator'    => 'IN',
				'placeholder' => '%s',
			),
			'status_is_not'      => array(
				'column'      => 'stats.status',
				'operator'    => 'NOT IN',
				'placeholder' => '%s',
			),
			'product_includes'   => array(
				'column'      => 'product_lookup.product_id',
				'operator'    => 'IN',
				'placeholder' => '%d',
			),
			'product_excludes'   => array(
				'column'      => 'product_lookup.product_id',
				'operator'    => 'NOT IN',
				'placeholder' => '%d',
			),
			'variation_includes' => array(
				'column'      => 'product_lookup.variation_id',
				'operator'    => 'IN',
				'placeholder' => '%d',
			),
			'variation_excludes' => array(
				'column'      => 'product_lookup.variation_id',
				'operator'    => 'NOT IN',
				'placeholder' => '%d',
			),
		);

		foreach ( $filter_map as $arg_key => $filter_config ) {
			$values = $query_args[ $arg_key ] ?? array();

			if ( ! \is_array( $values ) || array() === $values ) {
				continue;
			}

			$clauses[] = array(
				'sql'  => $filter_config['column'] . ' ' . $filter_config['operator'] . ' (' . \implode(
					', ',
					\array_fill( 0, \count( $values ), $filter_config['placeholder'] )
				) . ')',
				'args' => $values,
			);
		}

		return $clauses;
	}

	/**
	 * Normalize statuses for safe SQL placeholders.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $status_arg     Raw status or statuses.
	 * @param bool  $default_active Whether active status should be used when the list is empty.
	 *
	 * @return array<int, string> Sanitized statuses, or empty for any status.
	 */
	private function normalize_statuses( mixed $status_arg, bool $default_active = true ): array {
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

		if ( array() === $statuses && $default_active ) {
			return array( 'active' );
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Normalize a comma-separated or array ID list.
	 *
	 * @since 0.9.2
	 *
	 * @param mixed $ids Raw ID list.
	 *
	 * @return array<int, int> Positive IDs.
	 */
	private function normalize_id_list( mixed $ids ): array {
		$ids = \is_array( $ids ) ? $ids : \explode( ',', (string) $ids );
		$ids = \array_values(
			\array_filter(
				\array_map(
					static function ( mixed $id ): int {
						return \max( 0, (int) $id );
					},
					$ids
				)
			)
		);

		return \array_values( \array_unique( $ids ) );
	}

	/**
	 * Normalize advanced filter match mode.
	 *
	 * @since 0.9.2
	 *
	 * @param mixed $match_mode Raw match mode.
	 *
	 * @return string Match mode.
	 */
	private function normalize_match( mixed $match_mode ): string {
		return 'any' === \strtolower( \trim( (string) $match_mode ) ) ? 'any' : 'all';
	}

	/**
	 * Normalize stats interval.
	 *
	 * @since 0.9.2
	 *
	 * @param mixed $interval Raw interval.
	 *
	 * @return string Supported interval.
	 */
	private function normalize_interval( mixed $interval ): string {
		$interval = \strtolower( \trim( (string) $interval ) );

		return \in_array( $interval, array( 'hour', 'day', 'week', 'month', 'quarter', 'year' ), true )
			? $interval
			: 'day';
	}

	/**
	 * Build empty stats totals.
	 *
	 * @since 0.9.2
	 *
	 * @return array{renewals_count: int, renewal_quantity: string, recurring_total: string}
	 */
	private function get_empty_stats_totals(): array {
		return array(
			'renewals_count'   => 0,
			'renewal_quantity' => '0.00000000',
			'recurring_total'  => '0.00000000',
		);
	}

	/**
	 * Build chart interval buckets.
	 *
	 * @since 0.9.2
	 *
	 * @param array<string, mixed> $query_args Normalized query arguments.
	 * @param string               $interval   Interval unit.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_interval_buckets( array $query_args, string $interval ): array {
		$buckets = array();
		$cursor  = $query_args['after_local'];
		$end     = $query_args['before_local'];

		while ( $cursor < $end ) {
			$bucket_end = $this->get_next_interval_boundary( $cursor, $interval );

			if ( $bucket_end > $end ) {
				$bucket_end = $end;
			}

			$key             = $cursor->format( DateWindow::MYSQL_FORMAT );
			$buckets[ $key ] = array(
				'interval'       => $interval,
				'date_start'     => $cursor->format( DateWindow::MYSQL_FORMAT ),
				'date_start_gmt' => $cursor->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DateWindow::MYSQL_FORMAT ),
				'date_end'       => $bucket_end->modify( '-1 second' )->format( DateWindow::MYSQL_FORMAT ),
				'date_end_gmt'   => $bucket_end->modify( '-1 second' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DateWindow::MYSQL_FORMAT ),
				'subtotals'      => $this->get_empty_stats_totals(),
			);
			$cursor          = $bucket_end;
		}

		return $buckets;
	}

	/**
	 * Get the next bucket boundary.
	 *
	 * @since 0.9.2
	 *
	 * @param \DateTimeImmutable $start    Current bucket start.
	 * @param string             $interval Interval unit.
	 *
	 * @return \DateTimeImmutable Next bucket boundary.
	 */
	private function get_next_interval_boundary( \DateTimeImmutable $start, string $interval ): \DateTimeImmutable {
		if ( 'quarter' === $interval ) {
			return $start->modify( '+3 months' );
		}

		return $start->modify( '+1 ' . $interval );
	}

	/**
	 * Find the interval bucket for a stats row.
	 *
	 * @since 0.9.2
	 *
	 * @param array<string, mixed>                $row     Stats row.
	 * @param array<string, array<string, mixed>> $buckets Interval buckets.
	 *
	 * @return string|null Bucket key.
	 */
	private function get_bucket_key_for_row( array $row, array $buckets ): ?string {
		try {
			$row_date = ( new \DateTimeImmutable(
				$row['next_payment_date_gmt'],
				new \DateTimeZone( 'UTC' )
			) )->setTimezone( $this->date_window->get_site_timezone() );
		} catch ( \Exception ) {
			return null;
		}

		foreach ( $buckets as $key => $bucket ) {
			$start = new \DateTimeImmutable( $bucket['date_start'], $this->date_window->get_site_timezone() );
			$end   = ( new \DateTimeImmutable( $bucket['date_end'], $this->date_window->get_site_timezone() ) )->modify( '+1 second' );

			if ( $row_date >= $start && $row_date < $end ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Add decimal strings without losing report precision.
	 *
	 * @since 0.9.2
	 *
	 * @param string $left  Left decimal.
	 * @param string $right Right decimal.
	 *
	 * @return string Decimal sum.
	 */
	private function add_decimal_strings( string $left, string $right ): string {
		return $this->format_decimal( (float) $left + (float) $right );
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
	 * Get the aggregate row field for an allowed orderby value.
	 *
	 * @since 0.9.4
	 *
	 * @param string $orderby Orderby field.
	 *
	 * @return string Aggregate row field.
	 */
	private function get_orderby_field( string $orderby ): string {
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
			'product_id'                  => 'product_id',
			'variation_id'                => 'variation_id',
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
