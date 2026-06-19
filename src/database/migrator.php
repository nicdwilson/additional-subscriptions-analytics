<?php
/**
 * Database migration coordinator.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Runs version-gated database migrations.
 *
 * @since 0.1.0
 */
final class Migrator {

	/**
	 * Database schema version option.
	 *
	 * @since 0.1.0
	 */
	public const OPTION_DB_VERSION = 'asa_db_version';

	/**
	 * Backfill status option.
	 *
	 * @since 0.1.0
	 */
	public const OPTION_BACKFILL_STATUS = 'asa_backfill_status';

	/**
	 * Backfill start timestamp option.
	 *
	 * @since 0.1.0
	 */
	public const OPTION_BACKFILL_STARTED_AT_GMT = 'asa_backfill_started_at_gmt';

	/**
	 * Backfill completion timestamp option.
	 *
	 * @since 0.1.0
	 */
	public const OPTION_BACKFILL_COMPLETED_AT_GMT = 'asa_backfill_completed_at_gmt';

	/**
	 * Last sync timestamp option.
	 *
	 * @since 0.1.0
	 */
	public const OPTION_LAST_SYNC_AT_GMT = 'asa_last_sync_at_gmt';

	/**
	 * Database installer.
	 *
	 * @var Installer
	 */
	private Installer $installer;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Installer|null $installer Optional installer instance.
	 */
	public function __construct( ?Installer $installer = null ) {
		$this->installer = $installer ?? new Installer();
	}

	/**
	 * Run migrations when the stored version or table state is stale.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function maybe_migrate(): void {
		$current_version = (string) \get_option( self::OPTION_DB_VERSION, '' );

		if ( Schema::DB_VERSION === $current_version && $this->installer->tables_exist() ) {
			return;
		}

		$this->migrate( $current_version );
	}

	/**
	 * Run all migrations needed for the current code schema.
	 *
	 * @since 0.1.0
	 *
	 * @param string $current_version Stored schema version.
	 *
	 * @return void
	 */
	public function migrate( string $current_version = '' ): void {
		if ( '' !== $current_version && \version_compare( $current_version, Schema::DB_VERSION, '>' ) ) {
			if ( ! $this->installer->tables_exist() ) {
				$this->installer->create_tables();
				$this->installer->ensure_lifecycle_options();
			}

			return;
		}

		if ( '' === $current_version || \version_compare( $current_version, Schema::DB_VERSION, '<' ) ) {
			$this->installer->install();
			return;
		}

		if ( ! $this->installer->tables_exist() ) {
			$this->installer->install();
			return;
		}

		\update_option( self::OPTION_DB_VERSION, Schema::DB_VERSION );
	}
}
