<?php
/**
 * Upcoming renewals source-vs-lookup reconciliation diagnostics.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Diagnostics;

use AdditionalSubscriptionsAnalytics\Data\DateWindow;
use AdditionalSubscriptionsAnalytics\Data\UpcomingRenewalsQuery;
use AdditionalSubscriptionsAnalytics\Sync\ProductLookupRowBuilder;
use AdditionalSubscriptionsAnalytics\Sync\SubscriptionRowBuilder;
use AdditionalSubscriptionsAnalytics\Sync\SubscriptionSource;

defined( 'ABSPATH' ) || exit;

/**
 * Compares report lookup-table aggregates with live source subscription data.
 *
 * @since 0.1.0
 */
final class UpcomingRenewalsReconciler {

	/**
	 * Default number of source subscriptions to inspect per page.
	 *
	 * @since 0.1.0
	 */
	private const DEFAULT_SOURCE_PAGE_SIZE = 100;

	/**
	 * Default maximum source subscriptions to scan during an interactive diagnostic.
	 *
	 * @since 0.1.0
	 */
	private const DEFAULT_SOURCE_LIMIT = 5000;

	/**
	 * Maximum lookup aggregate rows to request per page.
	 *
	 * @since 0.1.0
	 */
	private const LOOKUP_PAGE_SIZE = 1000;

	/**
	 * Lookup-table report query.
	 *
	 * @var object
	 */
	private object $lookup_query;

	/**
	 * Source subscription accessor.
	 *
	 * @var object
	 */
	private object $source;

	/**
	 * Subscription stats row builder.
	 *
	 * @var object
	 */
	private object $subscription_row_builder;

	/**
	 * Product lookup row builder.
	 *
	 * @var object
	 */
	private object $product_lookup_row_builder;

	/**
	 * Date helper.
	 *
	 * @var DateWindow
	 */
	private DateWindow $date_window;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param object|null     $lookup_query               Optional lookup query.
	 * @param object|null     $source                     Optional source subscription accessor.
	 * @param object|null     $subscription_row_builder   Optional stats row builder.
	 * @param object|null     $product_lookup_row_builder Optional product lookup row builder.
	 * @param DateWindow|null $date_window                Optional date helper.
	 */
	public function __construct(
		?object $lookup_query = null,
		?object $source = null,
		?object $subscription_row_builder = null,
		?object $product_lookup_row_builder = null,
		?DateWindow $date_window = null
	) {
		$this->lookup_query               = $lookup_query ?? new UpcomingRenewalsQuery();
		$this->source                     = $source ?? new SubscriptionSource();
		$this->subscription_row_builder   = $subscription_row_builder ?? new SubscriptionRowBuilder();
		$this->product_lookup_row_builder = $product_lookup_row_builder ?? new ProductLookupRowBuilder();
		$this->date_window                = $date_window ?? new DateWindow();
	}

	/**
	 * Reconcile lookup-table aggregates against source subscription aggregates.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Diagnostic arguments.
	 *
	 * @return array<string, mixed> Diagnostic result.
	 */
	public function reconcile( array $args ): array {
		$query_args = $this->normalize_args( $args );
		$lookup     = $this->build_lookup_snapshot( $query_args );
		$source     = $this->build_source_snapshot( $query_args );
		$mismatches = $this->compare_snapshots( $lookup['rows'], $source['rows'] );
		$status     = array() === $mismatches ? 'matched' : 'mismatched';

		if ( $source['isTruncated'] ) {
			$status = 'incomplete';
		}

		return array(
			'status'      => $status,
			'window'      => array(
				'afterGmt'  => $query_args['after_gmt'],
				'beforeGmt' => $query_args['before_gmt'],
				'statuses'  => $query_args['statuses'],
			),
			'lookup'      => $lookup,
			'source'      => $source,
			'mismatches'  => $mismatches,
			'summary'     => array(
				'mismatchCount'              => \count( $mismatches ),
				'sourceSubscriptionsScanned' => $source['subscriptionsScanned'],
				'sourceSubscriptionsMatched' => $source['subscriptionsMatched'],
				'lookupRows'                 => $lookup['rowCount'],
				'sourceRows'                 => $source['rowCount'],
			),
			'recommended' => array(
				'primary'   => 'data_sync',
				'nextSteps' => array(
					'Resync subscriptions listed in row-level mismatches.',
					'Run a full analytics regeneration when many rows differ.',
					'Treat rendering bugs as secondary after source and lookup data reconcile.',
				),
			),
		);
	}

	/**
	 * Normalize diagnostic query arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args Raw diagnostic arguments.
	 *
	 * @return array<string, mixed> Normalized arguments.
	 */
	private function normalize_args( array $args ): array {
		$window       = $this->date_window->analytics_range_to_gmt_window(
			$args['after'] ?? null,
			$args['before'] ?? null
		);
		$statuses     = $this->normalize_statuses( $args['status'] ?? ( $args['statuses'] ?? 'active' ) );
		$source_limit = \max( 1, \min( 50000, (int) ( $args['limit'] ?? self::DEFAULT_SOURCE_LIMIT ) ) );
		$page_size    = \max( 1, \min( 500, (int) ( $args['page_size'] ?? self::DEFAULT_SOURCE_PAGE_SIZE ) ) );

		return array(
			'after'            => $args['after'] ?? null,
			'before'           => $args['before'] ?? null,
			'after_gmt'        => $window['start'],
			'before_gmt'       => $window['end'],
			'statuses'         => $statuses,
			'status_arg'       => array() === $statuses ? 'any' : \implode( ',', $statuses ),
			'source_limit'     => $source_limit,
			'source_page_size' => $page_size,
		);
	}

	/**
	 * Build a lookup-table aggregate snapshot.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $query_args Normalized arguments.
	 *
	 * @return array<string, mixed> Lookup snapshot.
	 */
	private function build_lookup_snapshot( array $query_args ): array {
		$page       = 1;
		$rows       = array();
		$totals     = array(
			'total_quantity'      => '0.00000000',
			'subscriptions_count' => 0,
			'recurring_total'     => '0.00000000',
		);
		$total_rows = 0;

		do {
			$results = $this->lookup_query->get_data(
				array(
					'after'    => $query_args['after'],
					'before'   => $query_args['before'],
					'status'   => $query_args['status_arg'],
					'orderby'  => 'product_id',
					'order'    => 'asc',
					'page'     => $page,
					'per_page' => self::LOOKUP_PAGE_SIZE,
					'export'   => true,
				)
			);

			if ( isset( $results['totals'] ) && \is_array( $results['totals'] ) ) {
				$totals = $this->normalize_totals( $results['totals'] );
			}

			foreach ( (array) ( $results['data'] ?? array() ) as $row ) {
				$row                 = $this->normalize_aggregate_row( $row );
				$rows[ $row['key'] ] = $row;
			}

			$total_rows = \max( $total_rows, (int) ( $results['total'] ?? \count( $rows ) ) );
			$pages      = \max( 1, (int) ( $results['pages'] ?? 1 ) );
			++$page;
		} while ( $page <= $pages );

		return array(
			'rows'     => $this->remove_internal_keys( $rows ),
			'totals'   => $totals,
			'rowCount' => \count( $rows ),
			'total'    => $total_rows,
		);
	}

	/**
	 * Build a live source aggregate snapshot.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $query_args Normalized arguments.
	 *
	 * @return array<string, mixed> Source snapshot.
	 */
	private function build_source_snapshot( array $query_args ): array {
		$page                     = 1;
		$scanned                  = 0;
		$matched_subscription_ids = array();
		$all_subscription_ids     = array();
		$is_truncated             = false;
		$rows                     = array();

		while ( $scanned < $query_args['source_limit'] ) {
			$subscription_ids = $this->source->get_subscription_ids( $page, $query_args['source_page_size'] );

			if ( array() === $subscription_ids ) {
				break;
			}

			foreach ( $subscription_ids as $subscription_id ) {
				if ( $scanned >= $query_args['source_limit'] ) {
					$is_truncated = true;
					break 2;
				}

				++$scanned;
				$subscription = $this->source->get_subscription( \max( 0, (int) $subscription_id ) );

				if ( ! \is_object( $subscription ) ) {
					continue;
				}

				$stats_row = $this->subscription_row_builder->build( $subscription );

				if ( ! $this->source_row_matches_window( $stats_row, $query_args ) ) {
					continue;
				}

				$product_rows = $this->product_lookup_row_builder->build( $subscription );

				if ( array() === $product_rows ) {
					continue;
				}

				$matched_subscription_id                              = \max( 0, (int) ( $stats_row['subscription_id'] ?? 0 ) );
				$matched_subscription_ids[ $matched_subscription_id ] = true;

				foreach ( $product_rows as $product_row ) {
					$this->add_source_product_row( $rows, $all_subscription_ids, $stats_row, $product_row );
				}
			}

			if ( \count( $subscription_ids ) < $query_args['source_page_size'] ) {
				break;
			}

			if ( $scanned >= $query_args['source_limit'] ) {
				$is_truncated = true;
				break;
			}

			++$page;
		}

		$final_rows = $this->finalize_source_rows( $rows );

		return array(
			'rows'                 => $final_rows,
			'totals'               => $this->build_source_totals( $final_rows, $all_subscription_ids ),
			'rowCount'             => \count( $final_rows ),
			'subscriptionsScanned' => $scanned,
			'subscriptionsMatched' => \count( $matched_subscription_ids ),
			'isTruncated'          => $is_truncated,
		);
	}

	/**
	 * Add one source product row into the aggregate state.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, mixed>> $rows                 Aggregate rows.
	 * @param array<int, bool>                    $all_subscription_ids Distinct matching subscription IDs.
	 * @param array<string, int|string|null>      $stats_row            Source stats row.
	 * @param array<string, int|string|null>      $product_row          Source product row.
	 *
	 * @return void
	 */
	private function add_source_product_row(
		array &$rows,
		array &$all_subscription_ids,
		array $stats_row,
		array $product_row
	): void {
		$subscription_id = \max( 0, (int) ( $stats_row['subscription_id'] ?? 0 ) );
		$row             = $this->normalize_aggregate_row(
			array(
				'product_id'                  => $product_row['product_id'] ?? 0,
				'variation_id'                => $product_row['variation_id'] ?? 0,
				'product_name'                => $product_row['product_name'] ?? '',
				'currency'                    => $stats_row['currency'] ?? '',
				'total_quantity'              => $product_row['product_qty'] ?? '0',
				'subscriptions_count'         => 1,
				'recurring_total'             => $product_row['line_total'] ?? '0',
				'first_next_payment_date_gmt' => $stats_row['next_payment_date_gmt'] ?? '',
				'last_next_payment_date_gmt'  => $stats_row['next_payment_date_gmt'] ?? '',
			)
		);
		$key             = $row['key'];

		if ( ! isset( $rows[ $key ] ) ) {
			$row['subscription_ids'] = array();
			$rows[ $key ]            = $row;
		} else {
			$rows[ $key ]['product_name']                = \max( $rows[ $key ]['product_name'], $row['product_name'] );
			$rows[ $key ]['total_quantity']              = $this->format_decimal(
				(float) $rows[ $key ]['total_quantity'] + (float) $row['total_quantity']
			);
			$rows[ $key ]['recurring_total']             = $this->format_decimal(
				(float) $rows[ $key ]['recurring_total'] + (float) $row['recurring_total']
			);
			$rows[ $key ]['first_next_payment_date_gmt'] = \min(
				$rows[ $key ]['first_next_payment_date_gmt'],
				$row['first_next_payment_date_gmt']
			);
			$rows[ $key ]['last_next_payment_date_gmt']  = \max(
				$rows[ $key ]['last_next_payment_date_gmt'],
				$row['last_next_payment_date_gmt']
			);
		}

		if ( 0 !== $subscription_id ) {
			$rows[ $key ]['subscription_ids'][ $subscription_id ] = true;
			$all_subscription_ids[ $subscription_id ]             = true;
		}
	}

	/**
	 * Compare lookup and source aggregate snapshots.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, mixed>> $lookup_rows Lookup rows keyed by aggregate key.
	 * @param array<string, array<string, mixed>> $source_rows Source rows keyed by aggregate key.
	 *
	 * @return array<int, array<string, mixed>> Mismatch records.
	 */
	private function compare_snapshots( array $lookup_rows, array $source_rows ): array {
		$mismatches = array();
		$keys       = \array_unique( \array_merge( \array_keys( $lookup_rows ), \array_keys( $source_rows ) ) );
		\sort( $keys );

		foreach ( $keys as $key ) {
			if ( ! isset( $lookup_rows[ $key ] ) ) {
				$mismatches[] = $this->build_missing_row_mismatch( 'missing_lookup_row', $key, $source_rows[ $key ] );
				continue;
			}

			if ( ! isset( $source_rows[ $key ] ) ) {
				$mismatches[] = $this->build_missing_row_mismatch( 'missing_source_row', $key, $lookup_rows[ $key ] );
				continue;
			}

			foreach ( array( 'total_quantity', 'subscriptions_count', 'recurring_total' ) as $field ) {
				if ( (string) $lookup_rows[ $key ][ $field ] === (string) $source_rows[ $key ][ $field ] ) {
					continue;
				}

				$mismatches[] = array(
					'type'        => $field . '_mismatch',
					'key'         => $key,
					'productId'   => $lookup_rows[ $key ]['product_id'],
					'variationId' => $lookup_rows[ $key ]['variation_id'],
					'currency'    => $lookup_rows[ $key ]['currency'],
					'field'       => $field,
					'lookup'      => $lookup_rows[ $key ][ $field ],
					'source'      => $source_rows[ $key ][ $field ],
				);
			}
		}

		return $mismatches;
	}

	/**
	 * Build a missing-row mismatch record.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $type Mismatch type.
	 * @param string               $key  Aggregate key.
	 * @param array<string, mixed> $row  Available row.
	 *
	 * @return array<string, mixed>
	 */
	private function build_missing_row_mismatch( string $type, string $key, array $row ): array {
		return array(
			'type'        => $type,
			'key'         => $key,
			'productId'   => $row['product_id'],
			'variationId' => $row['variation_id'],
			'currency'    => $row['currency'],
			'lookup'      => 'missing_lookup_row' === $type ? null : $row,
			'source'      => 'missing_source_row' === $type ? null : $row,
		);
	}

	/**
	 * Determine whether a source stats row belongs in the diagnostic window.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|null> $stats_row  Source stats row.
	 * @param array<string, mixed>           $query_args Normalized arguments.
	 *
	 * @return bool True when the row matches.
	 */
	private function source_row_matches_window( array $stats_row, array $query_args ): bool {
		$status           = (string) ( $stats_row['status'] ?? '' );
		$next_payment_gmt = (string) ( $stats_row['next_payment_date_gmt'] ?? '' );

		if (
			array() !== $query_args['statuses']
			&& ! \in_array( $status, $query_args['statuses'], true )
		) {
			return false;
		}

		return '' !== $next_payment_gmt
			&& $next_payment_gmt >= $query_args['after_gmt']
			&& $next_payment_gmt < $query_args['before_gmt'];
	}

	/**
	 * Normalize statuses for comparison.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $status_arg Raw status argument.
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

		return \array_values( \array_unique( $statuses ) );
	}

	/**
	 * Normalize one aggregate row into the diagnostic shape.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $row Raw row.
	 *
	 * @return array<string, int|string>
	 */
	private function normalize_aggregate_row( mixed $row ): array {
		$row          = \is_object( $row ) ? \get_object_vars( $row ) : (array) $row;
		$product_id   = \max( 0, (int) ( $row['product_id'] ?? 0 ) );
		$variation_id = \max( 0, (int) ( $row['variation_id'] ?? 0 ) );
		$currency     = (string) ( $row['currency'] ?? '' );

		return array(
			'key'                         => $this->build_row_key( $product_id, $variation_id, $currency ),
			'product_id'                  => $product_id,
			'variation_id'                => $variation_id,
			'product_name'                => (string) ( $row['product_name'] ?? '' ),
			'currency'                    => $currency,
			'total_quantity'              => $this->format_decimal(
				$row['total_quantity'] ?? ( $row['total_qty'] ?? '0' )
			),
			'subscriptions_count'         => \max(
				0,
				(int) ( $row['subscriptions_count'] ?? ( $row['subscription_count'] ?? 0 ) )
			),
			'recurring_total'             => $this->format_decimal( $row['recurring_total'] ?? '0' ),
			'first_next_payment_date_gmt' => (string) ( $row['first_next_payment_date_gmt'] ?? '' ),
			'last_next_payment_date_gmt'  => (string) ( $row['last_next_payment_date_gmt'] ?? '' ),
		);
	}

	/**
	 * Finalize source row subscription counts and remove internal state.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, mixed>> $rows Source aggregate state.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function finalize_source_rows( array $rows ): array {
		foreach ( $rows as $key => $row ) {
			$rows[ $key ]['subscriptions_count'] = \count( (array) ( $row['subscription_ids'] ?? array() ) );
			unset( $rows[ $key ]['subscription_ids'] );
		}

		return $this->remove_internal_keys( $rows );
	}

	/**
	 * Remove internal keys from rows returned to callers.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, mixed>> $rows Rows keyed by aggregate key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function remove_internal_keys( array $rows ): array {
		foreach ( $rows as $key => $row ) {
			unset( $row['key'] );
			$rows[ $key ] = $row;
		}

		return $rows;
	}

	/**
	 * Build source-wide totals.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, mixed>> $rows                 Final aggregate rows.
	 * @param array<int, bool>                    $all_subscription_ids Distinct subscriptions with product rows.
	 *
	 * @return array<string, int|string>
	 */
	private function build_source_totals( array $rows, array $all_subscription_ids ): array {
		$total_quantity  = 0.0;
		$recurring_total = 0.0;

		foreach ( $rows as $row ) {
			$total_quantity  += (float) ( $row['total_quantity'] ?? 0 );
			$recurring_total += (float) ( $row['recurring_total'] ?? 0 );
		}

		return array(
			'total_quantity'      => $this->format_decimal( $total_quantity ),
			'subscriptions_count' => \count( $all_subscription_ids ),
			'recurring_total'     => $this->format_decimal( $recurring_total ),
		);
	}

	/**
	 * Normalize lookup totals.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $totals Raw totals.
	 *
	 * @return array<string, int|string>
	 */
	private function normalize_totals( array $totals ): array {
		return array(
			'total_quantity'      => $this->format_decimal( $totals['total_quantity'] ?? '0' ),
			'subscriptions_count' => \max( 0, (int) ( $totals['subscriptions_count'] ?? 0 ) ),
			'recurring_total'     => $this->format_decimal( $totals['recurring_total'] ?? '0' ),
		);
	}

	/**
	 * Build a stable aggregate row key.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $product_id   Product ID.
	 * @param int    $variation_id Variation ID.
	 * @param string $currency     Currency code.
	 *
	 * @return string Aggregate row key.
	 */
	private function build_row_key( int $product_id, int $variation_id, string $currency ): string {
		return $product_id . ':' . $variation_id . ':' . $currency;
	}

	/**
	 * Format a decimal value.
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
