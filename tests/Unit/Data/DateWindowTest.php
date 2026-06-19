<?php
/**
 * Tests for date normalization.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Unit\Data;

use AdditionalSubscriptionsAnalytics\Data\DateWindow;
use PHPUnit\Framework\TestCase;

/**
 * Tests date normalization helpers.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Data\DateWindow
 */
final class DateWindowTest extends TestCase {

	/**
	 * Test timezone-aware datetime values normalize to GMT.
	 *
	 * @return void
	 */
	public function test_normalize_datetime_interface_to_gmt(): void {
		$date_window = new DateWindow();
		$date        = new \DateTimeImmutable( '2026-06-17 12:30:00', new \DateTimeZone( 'Pacific/Auckland' ) );

		$this->assertSame( '2026-06-17 00:30:00', $date_window->normalize_gmt_datetime( $date ) );
	}

	/**
	 * Test MySQL strings are treated as GMT when no timezone is present.
	 *
	 * @return void
	 */
	public function test_normalize_mysql_datetime_string_as_gmt(): void {
		$date_window = new DateWindow();

		$this->assertSame( '2026-06-17 12:30:00', $date_window->normalize_gmt_datetime( '2026-06-17 12:30:00' ) );
	}

	/**
	 * Test empty subscription date values become null.
	 *
	 * @return void
	 */
	public function test_empty_date_values_normalize_to_null(): void {
		$date_window = new DateWindow();

		$this->assertNull( $date_window->normalize_gmt_datetime( 0 ) );
		$this->assertNull( $date_window->normalize_gmt_datetime( '0' ) );
		$this->assertNull( $date_window->normalize_gmt_datetime( '0000-00-00 00:00:00' ) );
	}
}
