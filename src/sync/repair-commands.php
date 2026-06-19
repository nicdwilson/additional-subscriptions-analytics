<?php
/**
 * Subscription analytics repair commands.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Sync;

use AdditionalSubscriptionsAnalytics\Data\DateWindow;
use AdditionalSubscriptionsAnalytics\Data\SubscriptionAnalyticsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Provides repair operations for CLI and future admin actions.
 *
 * @since 0.1.0
 */
final class RepairCommands {

	/**
	 * Backfill scheduler.
	 *
	 * @var object
	 */
	private object $scheduler;

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
	 * @param object|null     $scheduler   Optional backfill scheduler.
	 * @param object|null     $syncer      Optional subscription syncer.
	 * @param object|null     $repository  Optional analytics repository.
	 * @param DateWindow|null $date_window Optional date helper.
	 */
	public function __construct(
		?object $scheduler = null,
		?object $syncer = null,
		?object $repository = null,
		?DateWindow $date_window = null
	) {
		$this->repository  = $repository ?? new SubscriptionAnalyticsRepository();
		$this->scheduler   = $scheduler ?? new BackfillScheduler( null, null, $this->repository );
		$this->syncer      = $syncer ?? new SubscriptionSyncer( $this->repository );
		$this->date_window = $date_window ?? new DateWindow();
	}

	/**
	 * Register WP-CLI commands when WP-CLI is available.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( ! \defined( 'WP_CLI' ) || ! WP_CLI || ! \class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'asa regenerate', array( $this, 'regenerate_cli' ) );
		\WP_CLI::add_command( 'asa resync-subscription', array( $this, 'resync_subscription_cli' ) );
		\WP_CLI::add_command( 'asa repair-stale', array( $this, 'repair_stale_cli' ) );
		\WP_CLI::add_command( 'asa cleanup-orphans', array( $this, 'cleanup_orphans_cli' ) );
	}

	/**
	 * Schedule a full analytics table regeneration.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function regenerate(): void {
		$this->scheduler->schedule_regeneration();
	}

	/**
	 * Resync a single subscription immediately.
	 *
	 * @since 0.1.0
	 *
	 * @param int $subscription_id Subscription ID.
	 *
	 * @return bool True when a source subscription was found and written.
	 */
	public function resync_subscription( int $subscription_id ): bool {
		return $this->syncer->sync_by_id( $subscription_id );
	}

	/**
	 * Resync stale analytics rows.
	 *
	 * @since 0.1.0
	 *
	 * @param string $before_gmt GMT timestamp threshold. Defaults to now.
	 * @param int    $limit      Maximum number of stale rows to process.
	 *
	 * @return int Number of stale rows processed.
	 */
	public function repair_stale_rows( string $before_gmt = '', int $limit = 100 ): int {
		if ( '' === $before_gmt ) {
			$before_gmt = $this->date_window->current_gmt_datetime();
		}

		$subscription_ids = $this->repository->find_stale_subscription_ids( $before_gmt, $limit );
		$processed        = 0;

		foreach ( $subscription_ids as $subscription_id ) {
			$this->syncer->sync_by_id( \max( 0, (int) $subscription_id ) );
			++$processed;
		}

		return $processed;
	}

	/**
	 * Delete orphan product lookup rows.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of rows deleted.
	 */
	public function cleanup_orphan_product_lookup_rows(): int {
		return $this->repository->cleanup_orphan_product_lookup_rows();
	}

	/**
	 * WP-CLI handler for full regeneration.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function regenerate_cli( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$this->regenerate();
		$this->cli_success(
			\__( 'Subscription analytics regeneration scheduled.', 'additional-subscriptions-analytics' )
		);
	}

	/**
	 * WP-CLI handler for a single subscription resync.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function resync_subscription_cli( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		$subscription_id = isset( $args[0] ) ? \max( 0, (int) $args[0] ) : 0;

		if ( 0 === $subscription_id ) {
			$this->cli_error(
				\__( 'A valid subscription ID is required.', 'additional-subscriptions-analytics' )
			);
			return;
		}

		$synced = $this->resync_subscription( $subscription_id );

		if ( ! $synced ) {
			$this->cli_warning(
				\__( 'Source subscription was not found; analytics rows were removed.', 'additional-subscriptions-analytics' )
			);
			return;
		}

		$this->cli_success(
			\sprintf(
				/* translators: %d: subscription ID. */
				\__( 'Subscription %d resynced.', 'additional-subscriptions-analytics' ),
				$subscription_id
			)
		);
	}

	/**
	 * WP-CLI handler for stale row repair.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function repair_stale_cli( array $args, array $assoc_args ): void {
		unset( $args );

		$before_gmt = isset( $assoc_args['before-gmt'] ) ? (string) $assoc_args['before-gmt'] : '';
		$limit      = isset( $assoc_args['limit'] ) ? \max( 0, (int) $assoc_args['limit'] ) : 100;
		$processed  = $this->repair_stale_rows( $before_gmt, $limit );

		$this->cli_success(
			\sprintf(
				/* translators: %d: number of subscriptions processed. */
				\__( 'Processed %d stale subscription analytics rows.', 'additional-subscriptions-analytics' ),
				$processed
			)
		);
	}

	/**
	 * WP-CLI handler for orphan product lookup cleanup.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cleanup_orphans_cli( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$deleted = $this->cleanup_orphan_product_lookup_rows();

		$this->cli_success(
			\sprintf(
				/* translators: %d: number of product lookup rows deleted. */
				\__( 'Deleted %d orphan product lookup rows.', 'additional-subscriptions-analytics' ),
				$deleted
			)
		);
	}

	/**
	 * Send a WP-CLI success message.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	private function cli_success( string $message ): void {
		if ( \class_exists( '\WP_CLI' ) ) {
			\WP_CLI::success( $message );
		}
	}

	/**
	 * Send a WP-CLI warning message.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	private function cli_warning( string $message ): void {
		if ( \class_exists( '\WP_CLI' ) ) {
			\WP_CLI::warning( $message );
		}
	}

	/**
	 * Send a WP-CLI error message.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Message.
	 *
	 * @return void
	 */
	private function cli_error( string $message ): void {
		if ( \class_exists( '\WP_CLI' ) ) {
			\WP_CLI::error( $message );
		}
	}
}
