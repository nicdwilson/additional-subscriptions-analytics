<?php
/**
 * Subscription analytics backfill scheduler.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Sync;

use AdditionalSubscriptionsAnalytics\Data\DateWindow;
use AdditionalSubscriptionsAnalytics\Data\SubscriptionAnalyticsRepository;
use AdditionalSubscriptionsAnalytics\Database\Installer;
use AdditionalSubscriptionsAnalytics\Database\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates lookup-table backfill and regeneration work through Action Scheduler.
 *
 * @since 0.1.0
 */
final class BackfillScheduler {

	/**
	 * Backfill init action hook.
	 *
	 * @since 0.1.0
	 */
	public const ACTION_INIT = 'asa_backfill_init';

	/**
	 * Backfill batch action hook.
	 *
	 * @since 0.1.0
	 */
	public const ACTION_BATCH = 'asa_backfill_batch';

	/**
	 * Regeneration init action hook.
	 *
	 * @since 0.1.0
	 */
	public const ACTION_REGENERATE_INIT = 'asa_regenerate_init';

	/**
	 * Regeneration batch action hook.
	 *
	 * @since 0.1.0
	 */
	public const ACTION_REGENERATE_BATCH = 'asa_regenerate_batch';

	/**
	 * Action Scheduler group.
	 *
	 * @since 0.1.0
	 */
	public const GROUP = 'additional-subscriptions-analytics';

	/**
	 * Backfill page size.
	 *
	 * @since 0.1.0
	 */
	public const BATCH_SIZE = 25;

	/**
	 * Current backfill page option.
	 *
	 * @since 0.1.0
	 */
	public const OPTION_BACKFILL_LAST_PAGE = 'asa_backfill_last_page';

	/**
	 * Backfill failure message option.
	 *
	 * @since 0.1.0
	 */
	public const OPTION_BACKFILL_FAILURE = 'asa_backfill_failure';

	/**
	 * Queued status.
	 *
	 * @since 0.1.0
	 */
	public const STATUS_QUEUED = 'queued';

	/**
	 * Running status.
	 *
	 * @since 0.1.0
	 */
	public const STATUS_RUNNING = 'running';

	/**
	 * Completed status.
	 *
	 * @since 0.1.0
	 */
	public const STATUS_COMPLETED = 'completed';

	/**
	 * Failed status.
	 *
	 * @since 0.1.0
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Subscription source.
	 *
	 * @var object
	 */
	private object $source;

	/**
	 * Subscription syncer.
	 *
	 * @var object
	 */
	private object $syncer;

	/**
	 * Analytics repository.
	 *
	 * @var object
	 */
	private object $repository;

	/**
	 * Date helper.
	 *
	 * @var DateWindow
	 */
	private DateWindow $date_window;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param object|null     $source      Optional subscription source.
	 * @param object|null     $syncer      Optional subscription syncer.
	 * @param object|null     $repository  Optional analytics repository.
	 * @param DateWindow|null $date_window Optional date helper.
	 */
	public function __construct(
		?object $source = null,
		?object $syncer = null,
		?object $repository = null,
		?DateWindow $date_window = null
	) {
		$this->source      = $source ?? new SubscriptionSource();
		$this->repository  = $repository ?? new SubscriptionAnalyticsRepository();
		$this->syncer      = $syncer ?? new SubscriptionSyncer( $this->repository, null, null, $this->source );
		$this->date_window = $date_window ?? new DateWindow();
	}

	/**
	 * Register Action Scheduler hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		\add_action( self::ACTION_INIT, array( $this, 'process_backfill_init' ), 10, 1 );
		\add_action( self::ACTION_BATCH, array( $this, 'process_backfill_batch' ), 10, 2 );
		\add_action( self::ACTION_REGENERATE_INIT, array( $this, 'process_regeneration_init' ) );
		\add_action( self::ACTION_REGENERATE_BATCH, array( $this, 'process_regeneration_batch' ), 10, 1 );
	}

	/**
	 * Schedule a resumable backfill.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $skip_existing Whether existing stats rows should be skipped.
	 *
	 * @return void
	 */
	public function schedule_backfill( bool $skip_existing = true ): void {
		$this->set_status( self::STATUS_QUEUED );
		$this->delete_option( Migrator::OPTION_BACKFILL_COMPLETED_AT_GMT );
		$this->delete_option( self::OPTION_BACKFILL_FAILURE );

		$this->schedule_unique_action(
			self::ACTION_INIT,
			array( 'skip_existing' => $skip_existing )
		);
	}

	/**
	 * Schedule the first non-destructive backfill when no run has started yet.
	 *
	 * @since 0.9.1
	 *
	 * @return bool True when a backfill was scheduled.
	 */
	public function maybe_schedule_initial_backfill(): bool {
		if ( Installer::BACKFILL_STATUS_NOT_STARTED !== $this->get_status() ) {
			return false;
		}

		if ( '' !== $this->get_option( Migrator::OPTION_BACKFILL_COMPLETED_AT_GMT, '' ) ) {
			return false;
		}

		$this->schedule_backfill( true );

		return true;
	}

	/**
	 * Schedule a full table regeneration.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function schedule_regeneration(): void {
		$this->set_status( self::STATUS_QUEUED );
		$this->delete_option( Migrator::OPTION_BACKFILL_COMPLETED_AT_GMT );
		$this->delete_option( self::OPTION_BACKFILL_FAILURE );

		$this->schedule_unique_action( self::ACTION_REGENERATE_INIT );
	}

	/**
	 * Get the current backfill status.
	 *
	 * @since 0.9.1
	 *
	 * @return string Current lifecycle status.
	 */
	public function get_status(): string {
		return (string) $this->get_option(
			Migrator::OPTION_BACKFILL_STATUS,
			Installer::BACKFILL_STATUS_NOT_STARTED
		);
	}

	/**
	 * Determine whether a backfill or regeneration is currently active.
	 *
	 * @since 0.9.1
	 *
	 * @return bool True when a run is queued or running.
	 */
	public function is_backfill_active(): bool {
		return \in_array(
			$this->get_status(),
			array(
				self::STATUS_QUEUED,
				self::STATUS_RUNNING,
			),
			true
		);
	}

	/**
	 * Unschedule queued plugin backfill and regeneration actions.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function clear_queued_actions(): void {
		foreach (
			array(
				self::ACTION_INIT,
				self::ACTION_BATCH,
				self::ACTION_REGENERATE_INIT,
				self::ACTION_REGENERATE_BATCH,
			) as $hook
		) {
			if ( \function_exists( 'as_unschedule_all_actions' ) ) {
				\as_unschedule_all_actions( $hook, null, self::GROUP );
			}
		}
	}

	/**
	 * Initialize a paged backfill.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $skip_existing Whether existing stats rows should be skipped.
	 *
	 * @return void
	 */
	public function process_backfill_init( bool $skip_existing = true ): void {
		$this->start_run();
		$this->schedule_unique_action(
			self::ACTION_BATCH,
			array(
				'page'          => 1,
				'skip_existing' => $skip_existing,
			)
		);
	}

	/**
	 * Initialize a full table regeneration.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function process_regeneration_init(): void {
		$this->start_run();
		$this->repository->truncate_tables();
		$this->schedule_unique_action( self::ACTION_REGENERATE_BATCH, array( 'page' => 1 ) );
	}

	/**
	 * Process a single backfill batch.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $page          One-based page number.
	 * @param bool $skip_existing Whether existing stats rows should be skipped.
	 *
	 * @return void
	 *
	 * @throws \Throwable Rethrows processing failures for Action Scheduler logging/retry.
	 */
	public function process_backfill_batch( int $page = 1, bool $skip_existing = true ): void {
		$this->process_batch( \max( 1, $page ), $skip_existing, self::ACTION_BATCH );
	}

	/**
	 * Process a single regeneration batch.
	 *
	 * @since 0.1.0
	 *
	 * @param int $page One-based page number.
	 *
	 * @return void
	 *
	 * @throws \Throwable Rethrows processing failures for Action Scheduler logging/retry.
	 */
	public function process_regeneration_batch( int $page = 1 ): void {
		$this->process_batch( \max( 1, $page ), false, self::ACTION_REGENERATE_BATCH );
	}

	/**
	 * Process a paged batch and enqueue the next page when needed.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $page          One-based page number.
	 * @param bool   $skip_existing Whether existing stats rows should be skipped.
	 * @param string $batch_hook    Hook to schedule for the next page.
	 *
	 * @return void
	 *
	 * @throws \Throwable Rethrows processing failures for Action Scheduler logging/retry.
	 */
	private function process_batch( int $page, bool $skip_existing, string $batch_hook ): void {
		try {
			$subscription_ids = $this->source->get_subscription_ids( $page, self::BATCH_SIZE );

			if ( array() === $subscription_ids ) {
				$this->complete_run( $page );
				return;
			}

			foreach ( $subscription_ids as $subscription_id ) {
				$subscription_id = \max( 0, (int) $subscription_id );

				if ( 0 === $subscription_id ) {
					continue;
				}

				if ( $skip_existing && $this->repository->subscription_exists( $subscription_id ) ) {
					continue;
				}

				$this->syncer->sync_by_id( $subscription_id );
			}

			$this->update_option( self::OPTION_BACKFILL_LAST_PAGE, (string) $page );

			if ( self::BATCH_SIZE <= \count( $subscription_ids ) ) {
				$this->schedule_next_batch( $batch_hook, $page + 1, $skip_existing );
				return;
			}

			$this->complete_run( $page );
		} catch ( \Throwable $exception ) {
			$this->mark_failed( $exception );
			throw $exception;
		}
	}

	/**
	 * Mark a backfill/regeneration run as started.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function start_run(): void {
		$this->set_status( self::STATUS_RUNNING );
		$this->update_option( Migrator::OPTION_BACKFILL_STARTED_AT_GMT, $this->date_window->current_gmt_datetime() );
		$this->delete_option( Migrator::OPTION_BACKFILL_COMPLETED_AT_GMT );
		$this->delete_option( self::OPTION_BACKFILL_FAILURE );
		$this->update_option( self::OPTION_BACKFILL_LAST_PAGE, '0' );
	}

	/**
	 * Mark a backfill/regeneration run as complete.
	 *
	 * @since 0.1.0
	 *
	 * @param int $page Last processed page.
	 *
	 * @return void
	 */
	private function complete_run( int $page ): void {
		$this->update_option( self::OPTION_BACKFILL_LAST_PAGE, (string) \max( 0, $page ) );
		$this->set_status( self::STATUS_COMPLETED );
		$this->update_option( Migrator::OPTION_BACKFILL_COMPLETED_AT_GMT, $this->date_window->current_gmt_datetime() );
	}

	/**
	 * Mark a backfill/regeneration run as failed.
	 *
	 * @since 0.1.0
	 *
	 * @param \Throwable $exception Processing failure.
	 *
	 * @return void
	 */
	private function mark_failed( \Throwable $exception ): void {
		$this->set_status( self::STATUS_FAILED );
		$this->update_option( self::OPTION_BACKFILL_FAILURE, $exception->getMessage() );
	}

	/**
	 * Store the backfill status.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status Status value.
	 *
	 * @return void
	 */
	private function set_status( string $status ): void {
		$this->update_option( Migrator::OPTION_BACKFILL_STATUS, $status );
	}

	/**
	 * Schedule the next batch action.
	 *
	 * @since 0.1.0
	 *
	 * @param string $batch_hook    Batch hook.
	 * @param int    $page          Next page.
	 * @param bool   $skip_existing Whether existing rows should be skipped.
	 *
	 * @return void
	 */
	private function schedule_next_batch( string $batch_hook, int $page, bool $skip_existing ): void {
		$args = array( 'page' => \max( 1, $page ) );

		if ( self::ACTION_BATCH === $batch_hook ) {
			$args['skip_existing'] = $skip_existing;
		}

		$this->schedule_unique_action( $batch_hook, $args );
	}

	/**
	 * Schedule a unique Action Scheduler job when Action Scheduler is available.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $hook Action hook.
	 * @param array<string, mixed> $args Action arguments.
	 *
	 * @return void
	 */
	private function schedule_unique_action( string $hook, array $args = array() ): void {
		if ( \function_exists( 'as_next_scheduled_action' ) ) {
			$next_scheduled = \as_next_scheduled_action( $hook, $args, self::GROUP );

			if ( false !== $next_scheduled ) {
				return;
			}
		}

		if ( \function_exists( 'as_enqueue_async_action' ) ) {
			\as_enqueue_async_action( $hook, $args, self::GROUP, true );
			return;
		}

		if ( \function_exists( 'as_schedule_single_action' ) ) {
			\as_schedule_single_action( \time(), $hook, $args, self::GROUP, true );
		}
	}

	/**
	 * Update a WordPress option when WordPress is loaded.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 *
	 * @return void
	 */
	private function update_option( string $option, mixed $value ): void {
		if ( \function_exists( 'update_option' ) ) {
			\update_option( $option, $value );
		}
	}

	/**
	 * Read a WordPress option when WordPress is loaded.
	 *
	 * @since 0.9.1
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default_value Default value.
	 *
	 * @return mixed Option value.
	 */
	private function get_option( string $option, mixed $default_value = false ): mixed {
		if ( \function_exists( 'get_option' ) ) {
			return \get_option( $option, $default_value );
		}

		return $default_value;
	}

	/**
	 * Delete a WordPress option when WordPress is loaded.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option Option name.
	 *
	 * @return void
	 */
	private function delete_option( string $option ): void {
		if ( \function_exists( 'delete_option' ) ) {
			\delete_option( $option );
		}
	}
}
