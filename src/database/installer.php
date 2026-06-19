<?php
/**
 * Database installation routines.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and verifies plugin-owned database tables and lifecycle options.
 *
 * @since 0.1.0
 */
final class Installer {

	/**
	 * Backfill has not started.
	 *
	 * @since 0.1.0
	 */
	public const BACKFILL_STATUS_NOT_STARTED = 'not_started';

	/**
	 * Install or update database tables and lifecycle options.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function install(): void {
		$this->create_tables();
		$this->ensure_lifecycle_options();

		\update_option( Migrator::OPTION_DB_VERSION, Schema::DB_VERSION );
	}

	/**
	 * Create or update plugin-owned database tables.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function create_tables(): void {
		global $wpdb;

		if ( ! \function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		\dbDelta( Schema::get_schema_sql( $wpdb->prefix, $wpdb->get_charset_collate() ) );
	}

	/**
	 * Ensure lifecycle options exist.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function ensure_lifecycle_options(): void {
		$defaults = array(
			Migrator::OPTION_BACKFILL_STATUS           => self::BACKFILL_STATUS_NOT_STARTED,
			Migrator::OPTION_BACKFILL_STARTED_AT_GMT   => '',
			Migrator::OPTION_BACKFILL_COMPLETED_AT_GMT => '',
			Migrator::OPTION_LAST_SYNC_AT_GMT          => '',
		);

		foreach ( $defaults as $option_name => $default_value ) {
			\add_option( $option_name, $default_value, '', false );
		}
	}

	/**
	 * Determine whether all plugin-owned tables exist.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when all expected tables exist.
	 */
	public function tables_exist(): bool {
		global $wpdb;

		foreach ( Schema::get_table_names( $wpdb->prefix ) as $table_name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema verification must query table metadata.
			$found_table = $wpdb->get_var(
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$wpdb->esc_like( $table_name )
				)
			);

			if ( $table_name !== $found_table ) {
				return false;
			}
		}

		return true;
	}
}
