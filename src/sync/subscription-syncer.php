<?php
/**
 * Subscription analytics row syncer.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Sync;

use AdditionalSubscriptionsAnalytics\Data\SubscriptionAnalyticsRepository;
use AdditionalSubscriptionsAnalytics\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Converts subscriptions into analytics rows and persists them.
 *
 * @since 0.1.0
 */
final class SubscriptionSyncer {

	/**
	 * Analytics repository.
	 *
	 * @var object
	 */
	private object $repository;

	/**
	 * Stats row builder.
	 *
	 * @var object
	 */
	private object $subscription_row_builder;

	/**
	 * Product lookup row builder.
	 *
	 * @var object
	 */
	private object $product_lookup_row_builder;

	/**
	 * Subscription source.
	 *
	 * @var object
	 */
	private object $source;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param object|null $repository                 Optional analytics repository.
	 * @param object|null $subscription_row_builder   Optional stats row builder.
	 * @param object|null $product_lookup_row_builder Optional product lookup row builder.
	 * @param object|null $source                     Optional subscription source.
	 */
	public function __construct(
		?object $repository = null,
		?object $subscription_row_builder = null,
		?object $product_lookup_row_builder = null,
		?object $source = null
	) {
		$this->repository                 = $repository ?? new SubscriptionAnalyticsRepository();
		$this->subscription_row_builder   = $subscription_row_builder ?? new SubscriptionRowBuilder();
		$this->product_lookup_row_builder = $product_lookup_row_builder ?? new ProductLookupRowBuilder();
		$this->source                     = $source ?? new SubscriptionSource();
	}

	/**
	 * Sync a subscription object into analytics lookup tables.
	 *
	 * @since 0.1.0
	 *
	 * @param object $subscription Subscription object.
	 *
	 * @return bool True when the subscription was written.
	 */
	public function sync( object $subscription ): bool {
		$stats_row       = $this->subscription_row_builder->build( $subscription );
		$subscription_id = \max( 0, (int) ( $stats_row['subscription_id'] ?? 0 ) );

		if ( 0 === $subscription_id ) {
			return false;
		}

		if ( ! $this->repository->upsert_subscription_stats( $stats_row ) ) {
			return false;
		}

		$this->repository->replace_product_lookup_rows(
			$subscription_id,
			$this->product_lookup_row_builder->build( $subscription )
		);

		$this->record_last_sync_time( $stats_row['synced_at_gmt'] ?? null );

		return true;
	}

	/**
	 * Sync a subscription by ID.
	 *
	 * @since 0.1.0
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return bool True when a source subscription was found and written.
	 */
	public function sync_by_id( int $subscription_id ): bool {
		$subscription = $this->source->get_subscription( $subscription_id );

		if ( ! \is_object( $subscription ) ) {
			$this->delete( $subscription_id );
			return false;
		}

		return $this->sync( $subscription );
	}

	/**
	 * Delete analytics rows for a subscription.
	 *
	 * @since 0.1.0
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return void
	 */
	public function delete( int $subscription_id ): void {
		$this->repository->delete_subscription( $subscription_id );
	}

	/**
	 * Persist the last successful sync timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $synced_at_gmt Sync timestamp.
	 *
	 * @return void
	 */
	private function record_last_sync_time( mixed $synced_at_gmt ): void {
		if ( ! \function_exists( 'update_option' ) || ! \is_scalar( $synced_at_gmt ) ) {
			return;
		}

		\update_option( Migrator::OPTION_LAST_SYNC_AT_GMT, (string) $synced_at_gmt );
	}
}
