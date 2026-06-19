<?php
/**
 * Tests for subscription stats rows.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Unit\Sync;

use AdditionalSubscriptionsAnalytics\Sync\SubscriptionRowBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests subscription stats row shaping.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Sync\SubscriptionRowBuilder
 */
final class SubscriptionRowBuilderTest extends TestCase {

	/**
	 * Test subscription source values become a stats row.
	 *
	 * @return void
	 */
	public function test_builds_subscription_stats_row(): void {
		$subscription = new FakeSubscription(
			array(
				'id'             => 42,
				'parent_id'      => 10,
				'customer_id'    => 99,
				'status'         => 'wc-active',
				'billing_period' => 'Month',
				'currency'       => 'nzd',
				'dates'          => array(
					'date_created'            => '2026-06-01 02:00:00',
					'date_modified'           => new \DateTimeImmutable( '2026-06-02 14:00:00+12:00' ),
					'start'                   => '2026-06-01 02:00:00',
					'trial_end'               => '0',
					'last_order_date_paid'    => '2026-06-10 01:30:00',
					'last_order_date_created' => '2026-06-10 01:00:00',
					'next_payment'            => '2026-06-24 02:00:00',
					'end'                     => null,
				),
			)
		);

		$row = ( new SubscriptionRowBuilder() )->build( $subscription );

		$this->assertSame( 42, $row['subscription_id'] );
		$this->assertSame( 10, $row['parent_order_id'] );
		$this->assertSame( 99, $row['customer_id'] );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( '2026-06-01 02:00:00', $row['date_created_gmt'] );
		$this->assertSame( '2026-06-02 02:00:00', $row['date_updated_gmt'] );
		$this->assertSame( '2026-06-01 02:00:00', $row['start_date_gmt'] );
		$this->assertNull( $row['trial_end_date_gmt'] );
		$this->assertSame( '2026-06-10 01:30:00', $row['last_payment_date_gmt'] );
		$this->assertSame( '2026-06-24 02:00:00', $row['next_payment_date_gmt'] );
		$this->assertNull( $row['end_date_gmt'] );
		$this->assertSame( 'month', $row['billing_period'] );
		$this->assertSame( 2, $row['billing_interval'] );
		$this->assertSame( '49.99000000', $row['recurring_total'] );
		$this->assertSame( '4.54000000', $row['recurring_tax_total'] );
		$this->assertSame( '6.50000000', $row['recurring_shipping_total'] );
		$this->assertSame( 'NZD', $row['currency'] );
		$this->assertSame( 'stripe_cc', $row['payment_method'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['synced_at_gmt'] );
	}

	/**
	 * Test last payment falls back to last order created date when paid date is absent.
	 *
	 * @return void
	 */
	public function test_last_payment_falls_back_to_last_order_created(): void {
		$subscription = new FakeSubscription(
			array(
				'dates' => array(
					'last_order_date_paid'    => 0,
					'last_order_date_created' => '2026-05-01 01:00:00',
				),
			)
		);

		$row = ( new SubscriptionRowBuilder() )->build( $subscription );

		$this->assertSame( '2026-05-01 01:00:00', $row['last_payment_date_gmt'] );
	}
}

/**
 * Lightweight subscription test double.
 */
final class FakeSubscription {

	/**
	 * Source data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Source data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function get_id(): int {
		return (int) ( $this->data['id'] ?? 1 );
	}

	public function get_parent_id(): int {
		return (int) ( $this->data['parent_id'] ?? 0 );
	}

	public function get_customer_id(): int {
		return (int) ( $this->data['customer_id'] ?? 0 );
	}

	public function get_status(): string {
		return (string) ( $this->data['status'] ?? 'active' );
	}

	public function get_date( string $date_type, string $timezone = 'gmt' ): mixed {
		unset( $timezone );

		return $this->data['dates'][ $date_type ] ?? null;
	}

	public function get_billing_period(): string {
		return (string) ( $this->data['billing_period'] ?? 'month' );
	}

	public function get_billing_interval(): int {
		return (int) ( $this->data['billing_interval'] ?? 2 );
	}

	public function get_total(): string {
		return (string) ( $this->data['total'] ?? '49.99' );
	}

	public function get_total_tax(): string {
		return (string) ( $this->data['total_tax'] ?? '4.54' );
	}

	public function get_shipping_total(): string {
		return (string) ( $this->data['shipping_total'] ?? '6.50' );
	}

	public function get_currency(): string {
		return (string) ( $this->data['currency'] ?? 'NZD' );
	}

	public function get_payment_method(): string {
		return (string) ( $this->data['payment_method'] ?? 'stripe_cc' );
	}
}
