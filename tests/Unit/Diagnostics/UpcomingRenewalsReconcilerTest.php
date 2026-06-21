<?php
/**
 * Tests for upcoming renewals reconciliation diagnostics.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Unit\Diagnostics;

use AdditionalSubscriptionsAnalytics\Data\DateWindow;
use AdditionalSubscriptionsAnalytics\Diagnostics\UpcomingRenewalsReconciler;
use PHPUnit\Framework\TestCase;

/**
 * Tests source-vs-lookup reconciliation.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Diagnostics\UpcomingRenewalsReconciler
 */
final class UpcomingRenewalsReconcilerTest extends TestCase {

	/**
	 * Test matching source and lookup aggregates reconcile cleanly.
	 *
	 * @return void
	 */
	public function test_reconciles_matching_source_and_lookup_rows(): void {
		$reconciler = $this->get_reconciler(
			array(
				$this->get_lookup_row( 10, '2.00000000', 1, '20.00000000' ),
			),
			array(
				new ReconcilerTestSubscription( 501, 'active', '2026-07-04 00:00:00', 10, 0, 2, '20' ),
			)
		);

		$result = $reconciler->reconcile(
			array(
				'after'  => '2026-07-01',
				'before' => '2026-07-31',
			)
		);

		$this->assertSame( 'matched', $result['status'] );
		$this->assertSame( 0, $result['summary']['mismatchCount'] );
		$this->assertSame( 1, $result['summary']['lookupRows'] );
		$this->assertSame( 1, $result['summary']['sourceRows'] );
		$this->assertSame( 1, $result['summary']['sourceSubscriptionsMatched'] );
	}

	/**
	 * Test lookup rows that differ from source rows are reported as data-sync mismatches.
	 *
	 * @return void
	 */
	public function test_reports_row_level_data_sync_mismatches(): void {
		$reconciler = $this->get_reconciler(
			array(
				$this->get_lookup_row( 10, '1.00000000', 1, '10.00000000' ),
			),
			array(
				new ReconcilerTestSubscription( 502, 'active', '2026-07-04 00:00:00', 10, 0, 2, '20' ),
			)
		);

		$result         = $reconciler->reconcile(
			array(
				'after'  => '2026-07-01',
				'before' => '2026-07-31',
			)
		);
		$mismatch_types = \array_column( $result['mismatches'], 'type' );

		$this->assertSame( 'mismatched', $result['status'] );
		$this->assertContains( 'total_quantity_mismatch', $mismatch_types );
		$this->assertContains( 'recurring_total_mismatch', $mismatch_types );
		$this->assertSame( 'data_sync', $result['recommended']['primary'] );
	}

	/**
	 * Test source reconciliation expands recurring renewals inside the diagnostic window.
	 *
	 * @return void
	 */
	public function test_reconciles_recurring_source_renewal_occurrences(): void {
		$reconciler = $this->get_reconciler(
			array(
				$this->get_lookup_row(
					10,
					'6.00000000',
					1,
					'60.00000000',
					'2026-07-01 00:00:00',
					'2026-09-01 00:00:00'
				),
			),
			array(
				new ReconcilerTestSubscription( 506, 'active', '2026-07-01 00:00:00', 10, 0, 2, '20' ),
			)
		);

		$result = $reconciler->reconcile(
			array(
				'after'  => '2026-07-01',
				'before' => '2026-09-30',
			)
		);

		$this->assertSame( 'matched', $result['status'] );
		$this->assertSame( 1, $result['summary']['sourceSubscriptionsMatched'] );
	}

	/**
	 * Test source reconciliation applies the selected date window and status filter.
	 *
	 * @return void
	 */
	public function test_filters_source_subscriptions_by_window_and_status(): void {
		$reconciler = $this->get_reconciler(
			array(
				$this->get_lookup_row( 10, '2.00000000', 1, '20.00000000' ),
			),
			array(
				new ReconcilerTestSubscription( 503, 'active', '2026-07-04 00:00:00', 10, 0, 2, '20' ),
				new ReconcilerTestSubscription( 504, 'cancelled', '2026-07-05 00:00:00', 10, 0, 5, '50' ),
				new ReconcilerTestSubscription( 505, 'active', '2026-08-04 00:00:00', 10, 0, 7, '70' ),
			)
		);

		$result = $reconciler->reconcile(
			array(
				'after'  => '2026-07-01',
				'before' => '2026-07-31',
				'status' => 'active',
			)
		);

		$this->assertSame( 'matched', $result['status'] );
		$this->assertSame( 3, $result['summary']['sourceSubscriptionsScanned'] );
		$this->assertSame( 1, $result['summary']['sourceSubscriptionsMatched'] );
	}

	/**
	 * Get a reconciler with fake dependencies.
	 *
	 * @param array<int, array<string, mixed>> $lookup_rows   Lookup aggregate rows.
	 * @param array<int, object>               $subscriptions Source subscriptions.
	 *
	 * @return UpcomingRenewalsReconciler
	 */
	private function get_reconciler( array $lookup_rows, array $subscriptions ): UpcomingRenewalsReconciler {
		return new UpcomingRenewalsReconciler(
			new ReconcilerTestLookupQuery( $lookup_rows ),
			new ReconcilerTestSource( $subscriptions ),
			null,
			null,
			new DateWindow( new \DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Build a lookup aggregate row.
	 *
	 * @param int    $product_id          Product ID.
	 * @param string $total_quantity      Total quantity.
	 * @param int    $subscriptions_count Subscription count.
	 * @param string $recurring_total     Recurring total.
	 * @param string $first_renewal_gmt   First renewal date in GMT.
	 * @param string $last_renewal_gmt    Last renewal date in GMT.
	 *
	 * @return array<string, mixed>
	 */
	private function get_lookup_row(
		int $product_id,
		string $total_quantity,
		int $subscriptions_count,
		string $recurring_total,
		string $first_renewal_gmt = '2026-07-04 00:00:00',
		string $last_renewal_gmt = '2026-07-04 00:00:00'
	): array {
		return array(
			'product_id'                  => $product_id,
			'variation_id'                => 0,
			'product_name'                => 'Coffee',
			'currency'                    => 'USD',
			'total_quantity'              => $total_quantity,
			'subscriptions_count'         => $subscriptions_count,
			'recurring_total'             => $recurring_total,
			'first_next_payment_date_gmt' => $first_renewal_gmt,
			'last_next_payment_date_gmt'  => $last_renewal_gmt,
		);
	}
}

/**
 * Fake lookup query.
 */
final class ReconcilerTestLookupQuery {

	/**
	 * Lookup rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $rows Lookup rows.
	 */
	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	/**
	 * Get lookup data.
	 *
	 * @param array<string, mixed> $args Query args.
	 *
	 * @return array<string, mixed>
	 */
	public function get_data( array $args ): array {
		unset( $args );

		return array(
			'data'   => $this->rows,
			'totals' => array(
				'total_quantity'      => '0.00000000',
				'subscriptions_count' => 0,
				'recurring_total'     => '0.00000000',
			),
			'total'  => \count( $this->rows ),
			'pages'  => 1,
		);
	}
}

/**
 * Fake subscription source.
 */
final class ReconcilerTestSource {

	/**
	 * Source subscriptions.
	 *
	 * @var array<int, object>
	 */
	private array $subscriptions = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, object> $subscriptions Source subscriptions.
	 */
	public function __construct( array $subscriptions ) {
		foreach ( $subscriptions as $subscription ) {
			if ( \method_exists( $subscription, 'get_id' ) ) {
				$this->subscriptions[ $subscription->get_id() ] = $subscription;
			}
		}
	}

	/**
	 * Get source subscription IDs.
	 *
	 * @param int $page  Page number.
	 * @param int $limit Page size.
	 *
	 * @return array<int, int>
	 */
	public function get_subscription_ids( int $page, int $limit ): array {
		if ( 1 !== $page ) {
			return array();
		}

		return \array_slice( \array_keys( $this->subscriptions ), 0, $limit );
	}

	/**
	 * Get a subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return object|null
	 */
	public function get_subscription( int $subscription_id ): ?object {
		return $this->subscriptions[ $subscription_id ] ?? null;
	}
}

/**
 * Fake source subscription.
 */
final class ReconcilerTestSubscription {

	/**
	 * Constructor.
	 *
	 * @param int    $subscription_id       Subscription ID.
	 * @param string $status                Subscription status.
	 * @param string $next_payment_date_gmt Next payment date in GMT.
	 * @param int    $product_id            Product ID.
	 * @param int    $variation_id          Variation ID.
	 * @param int    $quantity              Quantity.
	 * @param string $line_total            Line total.
	 */
	public function __construct(
		private int $subscription_id,
		private string $status,
		private string $next_payment_date_gmt,
		private int $product_id,
		private int $variation_id,
		private int $quantity,
		private string $line_total
	) {}

	/**
	 * Get subscription ID.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->subscription_id;
	}

	/**
	 * Get parent ID.
	 *
	 * @return int
	 */
	public function get_parent_id(): int {
		return 0;
	}

	/**
	 * Get customer ID.
	 *
	 * @return int
	 */
	public function get_customer_id(): int {
		return 1;
	}

	/**
	 * Get subscription status.
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Get a subscription date.
	 *
	 * @param string $date_type Date type.
	 * @param string $timezone  Timezone.
	 *
	 * @return string|null
	 */
	public function get_date( string $date_type, string $timezone = 'gmt' ): ?string {
		unset( $timezone );

		$dates = array(
			'date_created'            => '2026-06-01 00:00:00',
			'date_modified'           => '2026-06-02 00:00:00',
			'start'                   => '2026-06-01 00:00:00',
			'last_order_date_created' => '2026-06-10 00:00:00',
			'next_payment'            => $this->next_payment_date_gmt,
		);

		return $dates[ $date_type ] ?? null;
	}

	/**
	 * Get billing period.
	 *
	 * @return string
	 */
	public function get_billing_period(): string {
		return 'month';
	}

	/**
	 * Get billing interval.
	 *
	 * @return int
	 */
	public function get_billing_interval(): int {
		return 1;
	}

	/**
	 * Get subscription total.
	 *
	 * @return string
	 */
	public function get_total(): string {
		return $this->line_total;
	}

	/**
	 * Get subscription tax total.
	 *
	 * @return string
	 */
	public function get_total_tax(): string {
		return '0';
	}

	/**
	 * Get shipping total.
	 *
	 * @return string
	 */
	public function get_shipping_total(): string {
		return '0';
	}

	/**
	 * Get currency.
	 *
	 * @return string
	 */
	public function get_currency(): string {
		return 'USD';
	}

	/**
	 * Get payment method.
	 *
	 * @return string
	 */
	public function get_payment_method(): string {
		return 'manual';
	}

	/**
	 * Get line items.
	 *
	 * @param string $type Item type.
	 *
	 * @return array<int, ReconcilerTestSubscriptionItem>
	 */
	public function get_items( string $type = 'line_item' ): array {
		if ( 'line_item' !== $type ) {
			return array();
		}

		return array(
			$this->subscription_id => new ReconcilerTestSubscriptionItem(
				$this->subscription_id,
				$this->product_id,
				$this->variation_id,
				$this->quantity,
				$this->line_total
			),
		);
	}
}

/**
 * Fake subscription line item.
 */
final class ReconcilerTestSubscriptionItem {

	/**
	 * Constructor.
	 *
	 * @param int    $item_id      Item ID.
	 * @param int    $product_id   Product ID.
	 * @param int    $variation_id Variation ID.
	 * @param int    $quantity     Quantity.
	 * @param string $line_total   Line total.
	 */
	public function __construct(
		private int $item_id,
		private int $product_id,
		private int $variation_id,
		private int $quantity,
		private string $line_total
	) {}

	/**
	 * Get item ID.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->item_id;
	}

	/**
	 * Get product ID.
	 *
	 * @return int
	 */
	public function get_product_id(): int {
		return $this->product_id;
	}

	/**
	 * Get variation ID.
	 *
	 * @return int
	 */
	public function get_variation_id(): int {
		return $this->variation_id;
	}

	/**
	 * Get product name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Coffee';
	}

	/**
	 * Get quantity.
	 *
	 * @return int
	 */
	public function get_quantity(): int {
		return $this->quantity;
	}

	/**
	 * Get subtotal.
	 *
	 * @return string
	 */
	public function get_subtotal(): string {
		return $this->line_total;
	}

	/**
	 * Get total.
	 *
	 * @return string
	 */
	public function get_total(): string {
		return $this->line_total;
	}

	/**
	 * Get total tax.
	 *
	 * @return string
	 */
	public function get_total_tax(): string {
		return '0';
	}
}
