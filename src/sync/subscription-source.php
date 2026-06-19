<?php
/**
 * Subscription source access.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Reads source subscriptions through WooCommerce Subscriptions APIs.
 *
 * @since 0.1.0
 */
final class SubscriptionSource {

	/**
	 * Get subscription IDs for a paged backfill batch.
	 *
	 * @since 0.1.0
	 *
	 * @param int $page  One-based page number.
	 * @param int $limit Page size.
	 *
	 * @return array<int, int> Subscription IDs.
	 */
	public function get_subscription_ids( int $page, int $limit ): array {
		if ( ! \function_exists( 'wcs_get_subscriptions' ) ) {
			return array();
		}

		$subscriptions = \wcs_get_subscriptions(
			array(
				'subscriptions_per_page' => \max( 1, $limit ),
				'paged'                  => \max( 1, $page ),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'subscription_status'    => 'any',
			)
		);

		if ( ! \is_iterable( $subscriptions ) ) {
			return array();
		}

		$subscription_ids = array();

		foreach ( $subscriptions as $subscription_id => $subscription ) {
			if ( \is_object( $subscription ) && \method_exists( $subscription, 'get_id' ) ) {
				$subscription_ids[] = \max( 0, (int) $subscription->get_id() );
				continue;
			}

			$subscription_ids[] = \max( 0, (int) $subscription_id );
		}

		$subscription_ids = \array_filter( \array_unique( $subscription_ids ) );

		return \array_values( $subscription_ids );
	}

	/**
	 * Get a subscription by ID.
	 *
	 * @since 0.1.0
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return object|null Subscription object, or null when missing.
	 */
	public function get_subscription( int $subscription_id ): ?object {
		if ( \function_exists( 'wcs_get_subscription' ) ) {
			$subscription = \wcs_get_subscription( \max( 0, $subscription_id ) );

			return \is_object( $subscription ) ? $subscription : null;
		}

		if ( \function_exists( 'wc_get_order' ) ) {
			$subscription = \wc_get_order( \max( 0, $subscription_id ) );

			return \is_object( $subscription ) ? $subscription : null;
		}

		return null;
	}

	/**
	 * Check whether an object is a subscription.
	 *
	 * @since 0.1.0
	 *
	 * @param object $subscription Candidate subscription object.
	 *
	 * @return bool
	 */
	public function is_subscription( object $subscription ): bool {
		if ( \is_a( $subscription, 'WC_Subscription' ) ) {
			return true;
		}

		if ( \method_exists( $subscription, 'get_type' ) ) {
			return 'shop_subscription' === (string) $subscription->get_type();
		}

		return false;
	}
}
