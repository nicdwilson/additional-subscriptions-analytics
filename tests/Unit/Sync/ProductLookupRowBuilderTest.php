<?php
/**
 * Tests for subscription product lookup rows.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Unit\Sync;

use AdditionalSubscriptionsAnalytics\Sync\ProductLookupRowBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests product lookup row shaping.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Sync\ProductLookupRowBuilder
 */
final class ProductLookupRowBuilderTest extends TestCase {

	/**
	 * Test line items become product lookup rows.
	 *
	 * @return void
	 */
	public function test_builds_product_lookup_rows(): void {
		$subscription = new FakeProductLookupSubscription(
			42,
			array(
				1001 => new FakeLineItem(
					array(
						'id'           => 1001,
						'product_id'   => 15,
						'variation_id' => 16,
						'name'         => 'Weekly Veg Box',
						'quantity'     => 2,
						'subtotal'     => '39.98',
						'total'        => '34.98',
						'total_tax'    => '3.18',
					)
				),
				1002 => new FakeLineItem(
					array(
						'id'           => 1002,
						'product_id'   => 0,
						'variation_id' => 0,
						'name'         => '<strong>Archived Item</strong>',
						'quantity'     => '1.5',
						'subtotal'     => '10',
						'total'        => '10',
						'total_tax'    => '0',
					)
				),
			)
		);

		$rows = ( new ProductLookupRowBuilder() )->build( $subscription );

		$this->assertCount( 2, $rows );
		$this->assertSame( 42, $rows[0]['subscription_id'] );
		$this->assertSame( 1001, $rows[0]['line_item_id'] );
		$this->assertSame( 15, $rows[0]['product_id'] );
		$this->assertSame( 16, $rows[0]['variation_id'] );
		$this->assertSame( 'Weekly Veg Box', $rows[0]['product_name'] );
		$this->assertSame( '2.00000000', $rows[0]['product_qty'] );
		$this->assertSame( '39.98000000', $rows[0]['line_subtotal'] );
		$this->assertSame( '34.98000000', $rows[0]['line_total'] );
		$this->assertSame( '3.18000000', $rows[0]['line_tax'] );
		$this->assertSame( 'Archived Item', $rows[1]['product_name'] );
		$this->assertSame( '1.50000000', $rows[1]['product_qty'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $rows[0]['synced_at_gmt'] );
	}

	/**
	 * Test a subscription without line items returns no rows.
	 *
	 * @return void
	 */
	public function test_empty_subscription_returns_no_rows(): void {
		$subscription = new FakeProductLookupSubscription( 42, array() );

		$this->assertSame( array(), ( new ProductLookupRowBuilder() )->build( $subscription ) );
	}
}

/**
 * Lightweight subscription test double for product lookup tests.
 */
final class FakeProductLookupSubscription {

	/**
	 * Subscription ID.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Line items.
	 *
	 * @var array<int, object>
	 */
	private array $items;

	/**
	 * Constructor.
	 *
	 * @param int                $id    Subscription ID.
	 * @param array<int, object> $items Line items.
	 */
	public function __construct( int $id, array $items ) {
		$this->id    = $id;
		$this->items = $items;
	}

	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Get line items.
	 *
	 * @param string $type Item type.
	 *
	 * @return array<int, object>
	 */
	public function get_items( string $type ): array {
		if ( 'line_item' !== $type ) {
			return array();
		}

		return $this->items;
	}
}

/**
 * Lightweight line item test double.
 */
final class FakeLineItem {

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
		return (int) ( $this->data['id'] ?? 0 );
	}

	public function get_product_id(): int {
		return (int) ( $this->data['product_id'] ?? 0 );
	}

	public function get_variation_id(): int {
		return (int) ( $this->data['variation_id'] ?? 0 );
	}

	public function get_name(): string {
		return (string) ( $this->data['name'] ?? '' );
	}

	public function get_quantity(): string {
		return (string) ( $this->data['quantity'] ?? 0 );
	}

	public function get_subtotal(): string {
		return (string) ( $this->data['subtotal'] ?? 0 );
	}

	public function get_total(): string {
		return (string) ( $this->data['total'] ?? 0 );
	}

	public function get_total_tax(): string {
		return (string) ( $this->data['total_tax'] ?? 0 );
	}
}
