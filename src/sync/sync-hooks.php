<?php
/**
 * Incremental subscription analytics sync hooks.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Queues subscription analytics sync work after subscription lifecycle events.
 *
 * @since 0.1.0
 */
final class SyncHooks {

	/**
	 * Single-subscription sync action hook.
	 *
	 * @since 0.1.0
	 */
	public const ACTION_SYNC = 'asa_sync_subscription';

	/**
	 * Action Scheduler group.
	 *
	 * @since 0.1.0
	 */
	public const GROUP = BackfillScheduler::GROUP;

	/**
	 * Subscription syncer.
	 *
	 * @var object
	 */
	private object $syncer;

	/**
	 * Subscription source.
	 *
	 * @var object
	 */
	private object $source;

	/**
	 * Optional schedule callback used by isolated unit tests.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $schedule_action_callback;

	/**
	 * Subscription IDs already queued in the current request.
	 *
	 * @var array<int, bool>
	 */
	private array $queued_subscription_ids = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param object|null   $syncer                   Optional subscription syncer.
	 * @param object|null   $source                   Optional subscription source.
	 * @param \Closure|null $schedule_action_callback Optional schedule callback for tests.
	 */
	public function __construct(
		?object $syncer = null,
		?object $source = null,
		?\Closure $schedule_action_callback = null
	) {
		$this->syncer                   = $syncer ?? new SubscriptionSyncer();
		$this->source                   = $source ?? new SubscriptionSource();
		$this->schedule_action_callback = $schedule_action_callback;
	}

	/**
	 * Register lifecycle and scheduled sync hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		\add_action( self::ACTION_SYNC, array( $this, 'process_sync_action' ), 10, 1 );

		foreach ( $this->get_subscription_sync_hooks() as $hook => $accepted_args ) {
			\add_action( $hook, array( $this, 'queue_subscription_sync_from_hook' ), 10, $accepted_args );
		}

		foreach ( $this->get_subscription_delete_hooks() as $hook => $accepted_args ) {
			\add_action( $hook, array( $this, 'delete_subscription_rows_from_hook' ), 10, $accepted_args );
		}

		\add_action( 'woocommerce_new_order_item', array( $this, 'queue_order_item_sync_from_hook' ), 10, 3 );
		\add_action( 'woocommerce_update_order_item', array( $this, 'queue_order_item_sync_from_hook' ), 10, 3 );
		\add_action( 'woocommerce_before_delete_order_item', array( $this, 'queue_order_item_sync_from_hook' ), 10, 1 );
		\add_action( 'woocommerce_delete_order_item', array( $this, 'queue_order_item_sync_from_hook' ), 10, 1 );
		\add_action( 'wcs_user_removed_item', array( $this, 'queue_subscription_item_sync_from_hook' ), 10, 2 );
		\add_action( 'wcs_user_readded_item', array( $this, 'queue_subscription_item_sync_from_hook' ), 10, 2 );
		\add_action( 'woocommerce_subscriptions_switch_completed', array( $this, 'queue_switch_completed_sync' ), 10, 1 );
		\add_action( 'woocommerce_subscriptions_switched_item', array( $this, 'queue_subscription_sync_from_hook' ), 10, 3 );
		\add_action( 'woocommerce_subscription_item_switched', array( $this, 'queue_subscription_sync_from_hook' ), 10, 4 );
	}

	/**
	 * Unschedule queued incremental sync actions.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function clear_queued_actions(): void {
		if ( \function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions( self::ACTION_SYNC, null, self::GROUP );
		}
	}

	/**
	 * Queue a subscription sync from a lifecycle hook.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $primary    Primary hook argument.
	 * @param mixed $secondary  Secondary hook argument.
	 * @param mixed $tertiary   Tertiary hook argument.
	 * @param mixed $quaternary Quaternary hook argument.
	 *
	 * @return void
	 */
	public function queue_subscription_sync_from_hook(
		mixed $primary = null,
		mixed $secondary = null,
		mixed $tertiary = null,
		mixed $quaternary = null
	): void {
		$subscription_id = $this->resolve_subscription_id_from_candidates(
			array(
				$quaternary,
				$primary,
				$secondary,
				$tertiary,
			)
		);

		$this->queue_subscription_sync( $subscription_id );
	}

	/**
	 * Queue a subscription sync from an order item hook.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $item_id  Order item ID.
	 * @param mixed $item     Order item object or data.
	 * @param mixed $order_id Order ID.
	 *
	 * @return void
	 */
	public function queue_order_item_sync_from_hook( mixed $item_id = null, mixed $item = null, mixed $order_id = null ): void {
		$order_id        = $this->resolve_order_id_from_order_item( $item_id, $item, $order_id );
		$subscription_id = $this->resolve_subscription_id_from_order_id( $order_id );

		$this->queue_subscription_sync( $subscription_id );
	}

	/**
	 * Queue a subscription sync from a subscription item mutation hook.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $item         Subscription item.
	 * @param mixed $subscription Subscription object.
	 *
	 * @return void
	 */
	public function queue_subscription_item_sync_from_hook( mixed $item = null, mixed $subscription = null ): void {
		unset( $item );

		$this->queue_subscription_sync(
			$this->resolve_subscription_id_from_candidates( array( $subscription ) )
		);
	}

	/**
	 * Queue syncs for subscriptions touched by a completed switch order.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $order Switch order.
	 *
	 * @return void
	 */
	public function queue_switch_completed_sync( mixed $order = null ): void {
		$subscriptions = array();

		if ( \function_exists( 'wcs_get_subscriptions_for_switch_order' ) ) {
			$subscriptions = \wcs_get_subscriptions_for_switch_order( $order );
		}

		if ( ! \is_iterable( $subscriptions ) ) {
			return;
		}

		foreach ( $subscriptions as $subscription ) {
			$this->queue_subscription_sync(
				$this->resolve_subscription_id_from_candidates( array( $subscription ) )
			);
		}
	}

	/**
	 * Delete analytics rows for a deleted subscription.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $primary   Primary hook argument.
	 * @param mixed $secondary Secondary hook argument.
	 *
	 * @return void
	 */
	public function delete_subscription_rows_from_hook( mixed $primary = null, mixed $secondary = null ): void {
		$subscription_id = $this->resolve_subscription_id_from_candidates( array( $secondary, $primary ) );

		if ( 0 === $subscription_id ) {
			return;
		}

		$this->unschedule_subscription_sync( $subscription_id );
		$this->syncer->delete( $subscription_id );
	}

	/**
	 * Process a queued subscription sync action.
	 *
	 * @since 0.1.0
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return void
	 */
	public function process_sync_action( int $subscription_id ): void {
		$subscription_id = \max( 0, $subscription_id );

		if ( 0 === $subscription_id ) {
			return;
		}

		$this->syncer->sync_by_id( $subscription_id );
	}

	/**
	 * Get hooks that should queue a subscription resync.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int> Hook names keyed to accepted argument counts.
	 */
	private function get_subscription_sync_hooks(): array {
		return array(
			'woocommerce_new_subscription'              => 2,
			'woocommerce_update_subscription'           => 2,
			'woocommerce_subscription_status_changed'   => 4,
			'woocommerce_subscription_date_updated'     => 3,
			'woocommerce_subscription_date_deleted'     => 2,
			'woocommerce_subscription_payment_complete' => 1,
			'woocommerce_subscription_renewal_payment_complete' => 2,
			'woocommerce_subscription_payment_failed'   => 2,
			'woocommerce_subscription_renewal_payment_failed' => 2,
			'woocommerce_before_trash_subscription'     => 2,
			'woocommerce_trash_subscription'            => 1,
			'woocommerce_subscription_trashed'          => 1,
		);
	}

	/**
	 * Get hooks that should remove analytics rows.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int> Hook names keyed to accepted argument counts.
	 */
	private function get_subscription_delete_hooks(): array {
		return array(
			'woocommerce_before_delete_subscription' => 2,
			'woocommerce_delete_subscription'        => 1,
			'woocommerce_subscription_deleted'       => 1,
		);
	}

	/**
	 * Queue a debounced subscription sync.
	 *
	 * @since 0.1.0
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return void
	 */
	private function queue_subscription_sync( int $subscription_id ): void {
		$subscription_id = \max( 0, $subscription_id );

		if ( 0 === $subscription_id || isset( $this->queued_subscription_ids[ $subscription_id ] ) ) {
			return;
		}

		$this->queued_subscription_ids[ $subscription_id ] = true;

		$this->schedule_unique_sync_action( $subscription_id );
	}

	/**
	 * Resolve a subscription ID from possible hook arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, mixed> $candidates Possible subscription IDs or objects.
	 *
	 * @return int
	 */
	private function resolve_subscription_id_from_candidates( array $candidates ): int {
		foreach ( $candidates as $candidate ) {
			if ( \is_object( $candidate ) ) {
				$subscription_id = $this->resolve_subscription_id_from_object( $candidate );

				if ( 0 !== $subscription_id ) {
					return $subscription_id;
				}
			}

			if ( \is_scalar( $candidate ) && \is_numeric( $candidate ) ) {
				$subscription_id = \max( 0, (int) $candidate );

				if ( 0 !== $subscription_id ) {
					return $subscription_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Resolve a subscription ID from a subscription object.
	 *
	 * @since 0.1.0
	 *
	 * @param object $candidate Candidate subscription object.
	 *
	 * @return int
	 */
	private function resolve_subscription_id_from_object( object $candidate ): int {
		if ( \method_exists( $this->source, 'is_subscription' ) && ! $this->source->is_subscription( $candidate ) ) {
			return 0;
		}

		if ( ! \method_exists( $candidate, 'get_id' ) ) {
			return 0;
		}

		return \max( 0, (int) $candidate->get_id() );
	}

	/**
	 * Resolve a subscription ID from an order ID.
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return int
	 */
	private function resolve_subscription_id_from_order_id( int $order_id ): int {
		if ( 0 === $order_id ) {
			return 0;
		}

		$subscription = $this->source->get_subscription( $order_id );

		if ( ! \is_object( $subscription ) ) {
			return 0;
		}

		return $this->resolve_subscription_id_from_object( $subscription );
	}

	/**
	 * Resolve an order ID from an order item hook.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $item_id  Order item ID.
	 * @param mixed $item     Order item object or data.
	 * @param mixed $order_id Order ID.
	 *
	 * @return int
	 */
	private function resolve_order_id_from_order_item( mixed $item_id, mixed $item, mixed $order_id ): int {
		if ( \is_scalar( $order_id ) && \is_numeric( $order_id ) ) {
			return \max( 0, (int) $order_id );
		}

		if ( \is_object( $item ) && \method_exists( $item, 'get_order_id' ) ) {
			return \max( 0, (int) $item->get_order_id() );
		}

		if ( \is_array( $item ) && isset( $item['order_id'] ) && \is_numeric( $item['order_id'] ) ) {
			return \max( 0, (int) $item['order_id'] );
		}

		if ( \is_scalar( $item_id ) && \is_numeric( $item_id ) && \function_exists( 'wc_get_order_id_by_order_item_id' ) ) {
			return \max( 0, (int) \wc_get_order_id_by_order_item_id( (int) $item_id ) );
		}

		return 0;
	}

	/**
	 * Schedule a unique subscription sync action.
	 *
	 * @since 0.1.0
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return void
	 */
	private function schedule_unique_sync_action( int $subscription_id ): void {
		$args = array( 'subscription_id' => \max( 0, $subscription_id ) );

		if ( null !== $this->schedule_action_callback ) {
			$schedule_action_callback = $this->schedule_action_callback;
			$schedule_action_callback( self::ACTION_SYNC, $args );
			return;
		}

		if ( \function_exists( 'as_next_scheduled_action' ) ) {
			$next_scheduled = \as_next_scheduled_action( self::ACTION_SYNC, $args, self::GROUP );

			if ( false !== $next_scheduled ) {
				return;
			}
		}

		if ( \function_exists( 'as_enqueue_async_action' ) ) {
			\as_enqueue_async_action( self::ACTION_SYNC, $args, self::GROUP, true );
			return;
		}

		if ( \function_exists( 'as_schedule_single_action' ) ) {
			\as_schedule_single_action( \time(), self::ACTION_SYNC, $args, self::GROUP, true );
			return;
		}

		$this->process_sync_action( $subscription_id );
	}

	/**
	 * Unschedule pending sync work for a subscription.
	 *
	 * @since 0.1.0
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return void
	 */
	private function unschedule_subscription_sync( int $subscription_id ): void {
		$subscription_id = \max( 0, $subscription_id );
		unset( $this->queued_subscription_ids[ $subscription_id ] );

		if ( \function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions(
				self::ACTION_SYNC,
				array( 'subscription_id' => $subscription_id ),
				self::GROUP
			);
		}
	}
}
