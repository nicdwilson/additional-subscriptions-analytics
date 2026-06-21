<?php
/**
 * Renewal occurrence calculation helpers.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.9.4
 */

namespace AdditionalSubscriptionsAnalytics\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Expands a subscription's next payment date into report-window renewal dates.
 *
 * @since 0.9.4
 */
final class RenewalOccurrenceCalculator {

	/**
	 * Safety cap for one subscription inside one report window.
	 *
	 * @since 0.9.4
	 */
	private const MAX_OCCURRENCES = 1000;

	/**
	 * Get renewal occurrence dates for a lookup/source stats row.
	 *
	 * @since 0.9.4
	 *
	 * @param array<string, mixed> $row        Stats row containing next payment, period, interval, and optional end date.
	 * @param string               $after_gmt  Inclusive GMT window start.
	 * @param string               $before_gmt Exclusive GMT window end.
	 *
	 * @return array<int, string> GMT MySQL datetime occurrences inside the window.
	 */
	public function get_occurrences( array $row, string $after_gmt, string $before_gmt ): array {
		$window_start = $this->parse_gmt_datetime( $after_gmt );
		$window_end   = $this->parse_gmt_datetime( $before_gmt );
		$cursor       = $this->parse_gmt_datetime( $row['next_payment_date_gmt'] ?? null );

		if ( null === $window_start || null === $window_end || null === $cursor || $window_end <= $window_start ) {
			return array();
		}

		$limit = $window_end;
		$end   = $this->parse_gmt_datetime( $row['end_date_gmt'] ?? null );

		if ( null !== $end && $end < $limit ) {
			$limit = $end;
		}

		if ( $limit <= $window_start || $cursor >= $limit ) {
			return array();
		}

		$period   = $this->normalize_period( $row['billing_period'] ?? '' );
		$interval = \max( 1, (int) ( $row['billing_interval'] ?? 1 ) );

		if ( null === $period ) {
			return $cursor >= $window_start && $cursor < $limit
				? array( $cursor->format( DateWindow::MYSQL_FORMAT ) )
				: array();
		}

		while ( $cursor < $window_start ) {
			$next = $this->get_next_occurrence( $cursor, $period, $interval );

			if ( $next <= $cursor ) {
				return array();
			}

			$cursor = $next;
		}

		$occurrences = array();
		$count       = 0;

		while ( $cursor < $limit && $count < self::MAX_OCCURRENCES ) {
			$occurrences[] = $cursor->format( DateWindow::MYSQL_FORMAT );
			++$count;
			$next = $this->get_next_occurrence( $cursor, $period, $interval );

			if ( $next <= $cursor ) {
				break;
			}

			$cursor = $next;
		}

		return $occurrences;
	}

	/**
	 * Parse a GMT datetime string.
	 *
	 * @since 0.9.4
	 *
	 * @param mixed $value Date value.
	 *
	 * @return \DateTimeImmutable|null Parsed datetime, or null.
	 */
	private function parse_gmt_datetime( mixed $value ): ?\DateTimeImmutable {
		if ( ! \is_scalar( $value ) ) {
			return null;
		}

		$value = \trim( (string) $value );

		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}

		try {
			return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception ) {
			return null;
		}
	}

	/**
	 * Normalize a subscription billing period.
	 *
	 * @since 0.9.4
	 *
	 * @param mixed $period Billing period.
	 *
	 * @return string|null Supported period, or null.
	 */
	private function normalize_period( mixed $period ): ?string {
		$period = \strtolower( \trim( (string) $period ) );

		return \in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) ? $period : null;
	}

	/**
	 * Advance one billing interval.
	 *
	 * @since 0.9.4
	 *
	 * @param \DateTimeImmutable $date     Current occurrence.
	 * @param string             $period   Billing period.
	 * @param int                $interval Billing interval.
	 *
	 * @return \DateTimeImmutable Next occurrence.
	 */
	private function get_next_occurrence(
		\DateTimeImmutable $date,
		string $period,
		int $interval
	): \DateTimeImmutable {
		$modifier = '+' . $interval . ' ' . $period . ( 1 === $interval ? '' : 's' );

		return $date->modify( $modifier );
	}
}
