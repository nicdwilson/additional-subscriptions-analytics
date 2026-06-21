<?php
/**
 * Tests for renewal occurrence expansion.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Unit\Data;

use AdditionalSubscriptionsAnalytics\Data\RenewalOccurrenceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests renewal occurrence calculation.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Data\RenewalOccurrenceCalculator
 */
final class RenewalOccurrenceCalculatorTest extends TestCase {

	/**
	 * Test monthly renewals repeat inside a future report window.
	 *
	 * @return void
	 */
	public function test_monthly_renewals_repeat_inside_window(): void {
		$calculator = new RenewalOccurrenceCalculator();

		$occurrences = $calculator->get_occurrences(
			$this->get_row(
				array(
					'next_payment_date_gmt' => '2026-07-01 00:00:00',
					'billing_period'        => 'month',
					'billing_interval'      => 1,
				)
			),
			'2026-07-01 00:00:00',
			'2026-10-01 00:00:00'
		);

		$this->assertSame(
			array(
				'2026-07-01 00:00:00',
				'2026-08-01 00:00:00',
				'2026-09-01 00:00:00',
			),
			$occurrences
		);
	}

	/**
	 * Test recurrence advances to the first occurrence inside the report window.
	 *
	 * @return void
	 */
	public function test_next_payment_before_window_advances_to_first_matching_occurrence(): void {
		$calculator = new RenewalOccurrenceCalculator();

		$occurrences = $calculator->get_occurrences(
			$this->get_row(
				array(
					'next_payment_date_gmt' => '2026-07-01 00:00:00',
					'billing_period'        => 'month',
					'billing_interval'      => 1,
				)
			),
			'2026-08-01 00:00:00',
			'2026-10-01 00:00:00'
		);

		$this->assertSame(
			array(
				'2026-08-01 00:00:00',
				'2026-09-01 00:00:00',
			),
			$occurrences
		);
	}

	/**
	 * Test subscription end date stops generated renewals.
	 *
	 * @return void
	 */
	public function test_subscription_end_date_stops_occurrences(): void {
		$calculator = new RenewalOccurrenceCalculator();

		$occurrences = $calculator->get_occurrences(
			$this->get_row(
				array(
					'next_payment_date_gmt' => '2026-07-01 00:00:00',
					'end_date_gmt'          => '2026-08-15 00:00:00',
					'billing_period'        => 'month',
					'billing_interval'      => 1,
				)
			),
			'2026-07-01 00:00:00',
			'2026-10-01 00:00:00'
		);

		$this->assertSame(
			array(
				'2026-07-01 00:00:00',
				'2026-08-01 00:00:00',
			),
			$occurrences
		);
	}

	/**
	 * Test unsupported periods only count the stored next payment date.
	 *
	 * @return void
	 */
	public function test_unsupported_period_counts_only_stored_next_payment(): void {
		$calculator = new RenewalOccurrenceCalculator();

		$occurrences = $calculator->get_occurrences(
			$this->get_row(
				array(
					'next_payment_date_gmt' => '2026-07-01 00:00:00',
					'billing_period'        => 'fortnight',
					'billing_interval'      => 1,
				)
			),
			'2026-07-01 00:00:00',
			'2026-10-01 00:00:00'
		);

		$this->assertSame( array( '2026-07-01 00:00:00' ), $occurrences );
	}

	/**
	 * Build a stats row.
	 *
	 * @param array<string, mixed> $overrides Row overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function get_row( array $overrides = array() ): array {
		return \array_merge(
			array(
				'next_payment_date_gmt' => null,
				'end_date_gmt'          => null,
				'billing_period'        => 'month',
				'billing_interval'      => 1,
			),
			$overrides
		);
	}
}
