<?php
/**
 * Tests for WooCommerce Subscriptions source access.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace {
	if ( ! \function_exists( 'wcs_get_subscriptions' ) ) {
		$GLOBALS['asa_subscription_source_test_function_defined'] = true;

		/**
		 * Test double for WooCommerce Subscriptions source queries.
		 *
		 * @param array<string, mixed> $args Query arguments.
		 *
		 * @return array<int, object>
		 */
		function wcs_get_subscriptions( array $args ): array {
			$GLOBALS['asa_subscription_source_test_args'][] = $args;

			return array(
				26 => new class() {
					/**
					 * Get the subscription ID.
					 *
					 * @return int
					 */
					public function get_id(): int {
						return 26;
					}
				},
			);
		}
	}
}

namespace AdditionalSubscriptionsAnalytics\Tests\Unit\Sync {

	use AdditionalSubscriptionsAnalytics\Sync\SubscriptionSource;
	use PHPUnit\Framework\TestCase;

	/**
	 * Tests subscription source query arguments.
	 *
	 * @covers \AdditionalSubscriptionsAnalytics\Sync\SubscriptionSource
	 */
	final class SubscriptionSourceTest extends TestCase {

		/**
		 * Test page numbers are translated to explicit offsets.
		 *
		 * WooCommerce Subscriptions accepts `paged`, but current storage adapters only
		 * apply the explicit `offset` when retrieving subscriptions.
		 *
		 * @return void
		 */
		public function test_get_subscription_ids_uses_offset_for_later_pages(): void {
			if ( empty( $GLOBALS['asa_subscription_source_test_function_defined'] ) ) {
				$this->markTestSkipped( 'WooCommerce Subscriptions is already loaded.' );
			}

			$GLOBALS['asa_subscription_source_test_args'] = array();

			$source = new SubscriptionSource();

			$this->assertSame( array( 26 ), $source->get_subscription_ids( 2, 25 ) );
			$this->assertCount( 1, $GLOBALS['asa_subscription_source_test_args'] );
			$this->assertSame( 25, $GLOBALS['asa_subscription_source_test_args'][0]['offset'] );
			$this->assertSame( 25, $GLOBALS['asa_subscription_source_test_args'][0]['subscriptions_per_page'] );
			$this->assertArrayNotHasKey( 'paged', $GLOBALS['asa_subscription_source_test_args'][0] );
		}
	}
}
