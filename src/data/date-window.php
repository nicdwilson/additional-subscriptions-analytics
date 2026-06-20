<?php
/**
 * Date normalization helpers.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes source and report dates for analytics queries.
 *
 * @since 0.1.0
 */
final class DateWindow {

	/**
	 * MySQL datetime format used by lookup tables.
	 *
	 * @since 0.1.0
	 */
	public const MYSQL_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Optional site timezone override.
	 *
	 * @var \DateTimeZone|null
	 */
	private ?\DateTimeZone $site_timezone;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeZone|null $site_timezone Optional site timezone override.
	 */
	public function __construct( ?\DateTimeZone $site_timezone = null ) {
		$this->site_timezone = $site_timezone;
	}

	/**
	 * Normalize a date value to a GMT MySQL datetime string.
	 *
	 * Empty Subscriptions date values such as `0` are converted to null so they
	 * can be persisted into nullable lookup-table columns.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value DateTime object, timestamp, MySQL datetime string, or empty value.
	 *
	 * @return string|null GMT MySQL datetime string, or null when no date exists.
	 */
	public function normalize_gmt_datetime( mixed $value ): ?string {
		if ( $this->is_empty_date_value( $value ) ) {
			return null;
		}

		if ( $value instanceof \DateTimeInterface ) {
			return \DateTimeImmutable::createFromInterface( $value )
				->setTimezone( new \DateTimeZone( 'UTC' ) )
				->format( self::MYSQL_FORMAT );
		}

		if ( \is_int( $value ) || \is_float( $value ) || ( \is_string( $value ) && \is_numeric( $value ) ) ) {
			return $this->normalize_timestamp( (int) $value );
		}

		if ( \is_string( $value ) ) {
			return $this->normalize_datetime_string( $value );
		}

		return null;
	}

	/**
	 * Get the current GMT time in lookup-table format.
	 *
	 * @since 0.1.0
	 *
	 * @return string Current GMT MySQL datetime.
	 */
	public function current_gmt_datetime(): string {
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( self::MYSQL_FORMAT );
	}

	/**
	 * Convert Analytics after/before params into a GMT half-open window.
	 *
	 * Date-only `before` values are treated as inclusive local report dates and
	 * converted to the next local midnight, so SQL can use `< end`.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $after  Analytics after parameter.
	 * @param mixed $before Analytics before parameter.
	 *
	 * @return array{start: string, end: string} GMT MySQL datetime bounds.
	 */
	public function analytics_range_to_gmt_window( mixed $after, mixed $before ): array {
		$window = $this->analytics_range_to_local_window( $after, $before );

		return array(
			'start' => $window['start']->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::MYSQL_FORMAT ),
			'end'   => $window['end']->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::MYSQL_FORMAT ),
		);
	}

	/**
	 * Convert Analytics after/before params into a site-local half-open window.
	 *
	 * @since 0.9.2
	 *
	 * @param mixed $after  Analytics after parameter.
	 * @param mixed $before Analytics before parameter.
	 *
	 * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable} Site-local datetime bounds.
	 */
	public function analytics_range_to_local_window( mixed $after, mixed $before ): array {
		$timezone = $this->get_site_timezone();
		$start    = $this->parse_local_report_boundary( $after, $timezone, false );

		if ( null === $start ) {
			$start = new \DateTimeImmutable( 'today', $timezone );
		}

		$end = $this->parse_local_report_boundary( $before, $timezone, true );

		if ( null === $end || $end <= $start ) {
			$end = $start->modify( '+30 days' );
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Determine whether a source date value is empty.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Source date value.
	 *
	 * @return bool True when the value represents no date.
	 */
	private function is_empty_date_value( mixed $value ): bool {
		if ( null === $value || false === $value || '' === $value || 0 === $value || 0.0 === $value ) {
			return true;
		}

		if ( \is_string( $value ) ) {
			$value = \trim( $value );

			return '' === $value || '0' === $value || '0000-00-00 00:00:00' === $value;
		}

		return false;
	}

	/**
	 * Normalize a Unix timestamp to GMT.
	 *
	 * @since 0.1.0
	 *
	 * @param int $timestamp Unix timestamp.
	 *
	 * @return string|null GMT MySQL datetime string, or null when no date exists.
	 */
	private function normalize_timestamp( int $timestamp ): ?string {
		if ( $timestamp <= 0 ) {
			return null;
		}

		return ( new \DateTimeImmutable( '@' . $timestamp ) )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( self::MYSQL_FORMAT );
	}

	/**
	 * Normalize a datetime string to GMT.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Source datetime string.
	 *
	 * @return string|null GMT MySQL datetime string, or null when parsing fails.
	 */
	private function normalize_datetime_string( string $value ): ?string {
		$value = \trim( $value );

		try {
			if ( 1 === \preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value ) ) {
				$date = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
			} else {
				$date = new \DateTimeImmutable( $value );
			}
		} catch ( \Exception ) {
			return null;
		}

		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::MYSQL_FORMAT );
	}

	/**
	 * Resolve the site timezone.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeZone Site timezone.
	 */
	public function get_site_timezone(): \DateTimeZone {
		if ( null !== $this->site_timezone ) {
			return $this->site_timezone;
		}

		$timezone_string = 'UTC';

		if ( \function_exists( 'wc_timezone_string' ) ) {
			$timezone_string = (string) \wc_timezone_string();
		} elseif ( \function_exists( 'get_option' ) ) {
			$timezone_string = (string) \get_option( 'timezone_string', 'UTC' );
		}

		try {
			return new \DateTimeZone( '' !== $timezone_string ? $timezone_string : 'UTC' );
		} catch ( \Exception ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	/**
	 * Parse an Analytics report boundary as a site-local datetime.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed         $value     Report boundary value.
	 * @param \DateTimeZone $timezone  Site timezone.
	 * @param bool          $is_before Whether this is the `before` boundary.
	 *
	 * @return \DateTimeImmutable|null Parsed boundary, or null when invalid.
	 */
	private function parse_local_report_boundary(
		mixed $value,
		\DateTimeZone $timezone,
		bool $is_before
	): ?\DateTimeImmutable {
		if ( $value instanceof \DateTimeInterface ) {
			return \DateTimeImmutable::createFromInterface( $value )->setTimezone( $timezone );
		}

		if ( ! \is_scalar( $value ) ) {
			return null;
		}

		$value = \trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		try {
			if ( 1 === \preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$date = new \DateTimeImmutable( $value . ' 00:00:00', $timezone );

				return $is_before ? $date->modify( '+1 day' ) : $date;
			}

			return new \DateTimeImmutable( $value, $timezone );
		} catch ( \Exception ) {
			return null;
		}
	}
}
