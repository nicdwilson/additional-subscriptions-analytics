<?php
/**
 * Main plugin bootstrap.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics;

use AdditionalSubscriptionsAnalytics\Database\Migrator;
use AdditionalSubscriptionsAnalytics\Support\Compat;
use AdditionalSubscriptionsAnalytics\Sync\BackfillScheduler;
use AdditionalSubscriptionsAnalytics\Sync\RepairCommands;
use AdditionalSubscriptionsAnalytics\Sync\SyncHooks;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates plugin services and lifecycle hooks.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Backfill scheduler.
	 *
	 * @var BackfillScheduler
	 */
	private BackfillScheduler $backfill_scheduler;

	/**
	 * Repair command coordinator.
	 *
	 * @var RepairCommands
	 */
	private RepairCommands $repair_commands;

	/**
	 * Incremental sync hooks.
	 *
	 * @var SyncHooks
	 */
	private SyncHooks $sync_hooks;

	/**
	 * Get the singleton instance.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->backfill_scheduler = new BackfillScheduler();
		$this->repair_commands    = new RepairCommands( $this->backfill_scheduler );
		$this->sync_hooks         = new SyncHooks();

		$this->init_hooks();
	}

	/**
	 * Register core hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		\add_action( 'init', array( $this, 'maybe_migrate_database' ), 5 );
		\add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->backfill_scheduler->init_hooks();
		$this->repair_commands->init_hooks();
		$this->sync_hooks->init_hooks();
	}

	/**
	 * Run database migrations when schema state is stale.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function maybe_migrate_database(): void {
		( new Migrator() )->maybe_migrate();
	}

	/**
	 * Load translations.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		\load_plugin_textdomain(
			'additional-subscriptions-analytics',
			false,
			\dirname( ASA_BASENAME ) . '/languages'
		);
	}

	/**
	 * Run activation tasks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function activate(): void {
		$dependency_error = Compat::get_dependency_error();

		if ( null !== $dependency_error ) {
			return;
		}

		( new Migrator() )->migrate();

		\update_option( 'asa_version', ASA_VERSION );
		\update_option( 'asa_activation_time', \time() );
	}

	/**
	 * Run deactivation tasks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		( new BackfillScheduler() )->clear_queued_actions();
		( new SyncHooks() )->clear_queued_actions();
	}

	/**
	 * Prevent cloning.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 *
	 * @throws \RuntimeException Always throws.
	 */
	public function __wakeup(): void {
		throw new \RuntimeException( 'Cannot unserialize singleton.' );
	}
}
