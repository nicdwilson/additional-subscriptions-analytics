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
}
