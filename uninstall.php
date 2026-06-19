<?php
/**
 * Plugin uninstall handler.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/**
 * Clear plugin-owned scheduled actions.
 *
 * @since 0.1.0
 *
 * @return void
 */
function asa_uninstall_clear_scheduled_actions(): void {
	$scheduled_hooks = array(
		'asa_backfill_init',
		'asa_backfill_batch',
		'asa_regenerate_init',
		'asa_regenerate_batch',
		'asa_sync_subscription',
		'asa_repair_stale_rows',
	);

	foreach ( $scheduled_hooks as $hook_name ) {
		\wp_clear_scheduled_hook( $hook_name );

		if ( \function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions( $hook_name );
		}
	}
}

asa_uninstall_clear_scheduled_actions();

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must remove plugin options by prefix.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'asa_' ) . '%'
	)
);

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema cleanup on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_subscription_product_lookup" );

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional schema cleanup on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wc_subscriptions_stats" );
