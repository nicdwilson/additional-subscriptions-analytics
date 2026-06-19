<?php
/**
 * Plugin uninstall handler.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

const ASA_UNINSTALL_ACTION_GROUP = 'additional-subscriptions-analytics';

const ASA_UNINSTALL_SCHEDULED_HOOKS = array(
	'asa_backfill_init',
	'asa_backfill_batch',
	'asa_regenerate_init',
	'asa_regenerate_batch',
	'asa_sync_subscription',
	// Legacy hook retained so deleting older installs clears any queued jobs.
	'asa_repair_stale_rows',
);

const ASA_UNINSTALL_OPTION_PREFIXES = array(
	'asa_',
	'_transient_asa_',
	'_transient_timeout_asa_',
	'_site_transient_asa_',
	'_site_transient_timeout_asa_',
);

const ASA_UNINSTALL_SITE_META_PREFIXES = array(
	'_site_transient_asa_',
	'_site_transient_timeout_asa_',
);

const ASA_UNINSTALL_TABLES = array(
	'wc_subscription_product_lookup',
	'wc_subscriptions_stats',
);

/**
 * Determine whether Action Scheduler can safely unschedule actions.
 *
 * @since 0.9.1
 *
 * @return bool True when Action Scheduler is loaded and initialized.
 */
function asa_uninstall_action_scheduler_available(): bool {
	if ( ! \function_exists( 'as_unschedule_all_actions' ) || ! \class_exists( 'ActionScheduler' ) ) {
		return false;
	}

	if ( ! \is_callable( array( 'ActionScheduler', 'is_initialized' ) ) ) {
		return false;
	}

	return (bool) \call_user_func( array( 'ActionScheduler', 'is_initialized' ) );
}

/**
 * Clear plugin-owned scheduled actions.
 *
 * @since 0.1.0
 *
 * @return void
 */
function asa_uninstall_clear_scheduled_actions(): void {
	foreach ( ASA_UNINSTALL_SCHEDULED_HOOKS as $hook_name ) {
		\wp_clear_scheduled_hook( $hook_name );

		if ( asa_uninstall_action_scheduler_available() ) {
			\as_unschedule_all_actions( $hook_name, null, ASA_UNINSTALL_ACTION_GROUP );
			\as_unschedule_all_actions( $hook_name );
		}
	}
}

/**
 * Delete rows from a WordPress-owned table by key prefix.
 *
 * @since 0.9.1
 *
 * @param string   $table_name  Table name.
 * @param string   $column_name Prefix-matched key column name.
 * @param string[] $prefixes    Row key prefixes to delete.
 *
 * @return void
 */
function asa_uninstall_delete_rows_by_prefix( string $table_name, string $column_name, array $prefixes ): void {
	global $wpdb;

	foreach ( $prefixes as $prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must delete plugin-owned rows by prefix.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE %i LIKE %s',
				$table_name,
				$column_name,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
	}
}

/**
 * Delete plugin-owned options and transients.
 *
 * @since 0.9.1
 *
 * @return void
 */
function asa_uninstall_delete_options(): void {
	global $wpdb;

	asa_uninstall_delete_rows_by_prefix( $wpdb->options, 'option_name', ASA_UNINSTALL_OPTION_PREFIXES );

	if ( \function_exists( 'is_multisite' ) && \is_multisite() && ! empty( $wpdb->sitemeta ) ) {
		asa_uninstall_delete_rows_by_prefix( $wpdb->sitemeta, 'meta_key', ASA_UNINSTALL_SITE_META_PREFIXES );
	}
}

/**
 * Drop plugin-owned analytics tables.
 *
 * @since 0.9.1
 *
 * @return void
 */
function asa_uninstall_drop_tables(): void {
	global $wpdb;

	foreach ( ASA_UNINSTALL_TABLES as $table_suffix ) {
		$table_name = $wpdb->prefix . $table_suffix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema cleanup on uninstall.
		$wpdb->query(
			$wpdb->prepare(
				'DROP TABLE IF EXISTS %i',
				$table_name
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}

asa_uninstall_clear_scheduled_actions();
asa_uninstall_delete_options();
asa_uninstall_drop_tables();
