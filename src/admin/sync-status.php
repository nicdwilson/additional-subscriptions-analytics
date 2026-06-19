<?php
/**
 * Admin sync status reporting for subscription analytics tables.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Admin;

use AdditionalSubscriptionsAnalytics\Database\Installer;
use AdditionalSubscriptionsAnalytics\Database\Migrator;
use AdditionalSubscriptionsAnalytics\Sync\BackfillScheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Builds merchant-facing sync status state for the report.
 *
 * @since 0.1.0
 */
final class SyncStatus {

	/**
	 * Number of seconds after which the analytics rows are considered stale.
	 *
	 * @since 0.1.0
	 */
	public const STALE_AFTER_SECONDS = 86400;

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
	 * @param Installer|null $installer Optional installer dependency.
	 */
	public function __construct( ?Installer $installer = null ) {
		$this->installer = $installer ?? new Installer();
	}

	/**
	 * Register admin hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		\add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
	}

	/**
	 * Get the current sync status payload.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool|int|string>
	 */
	public function get_status(): array {
		$tables_exist       = $this->installer->tables_exist();
		$backfill_status    = (string) \get_option(
			Migrator::OPTION_BACKFILL_STATUS,
			Installer::BACKFILL_STATUS_NOT_STARTED
		);
		$started_at_gmt     = (string) \get_option( Migrator::OPTION_BACKFILL_STARTED_AT_GMT, '' );
		$completed_at_gmt   = (string) \get_option( Migrator::OPTION_BACKFILL_COMPLETED_AT_GMT, '' );
		$last_sync_at_gmt   = (string) \get_option( Migrator::OPTION_LAST_SYNC_AT_GMT, '' );
		$failure_message    = (string) \get_option( BackfillScheduler::OPTION_BACKFILL_FAILURE, '' );
		$last_page          = (int) \get_option( BackfillScheduler::OPTION_BACKFILL_LAST_PAGE, 0 );
		$seconds_since_sync = $this->get_seconds_since_gmt_timestamp( $last_sync_at_gmt );

		$status = array(
			'state'            => 'ready',
			'severity'         => 'success',
			'message'          => __( 'Subscription analytics data is ready.', 'additional-subscriptions-analytics' ),
			'actionRequired'   => false,
			'tablesExist'      => $tables_exist,
			'backfillStatus'   => $backfill_status,
			'startedAtGmt'     => $started_at_gmt,
			'completedAtGmt'   => $completed_at_gmt,
			'lastSyncAtGmt'    => $last_sync_at_gmt,
			'failureMessage'   => $failure_message,
			'lastPage'         => $last_page,
			'staleAfter'       => self::STALE_AFTER_SECONDS,
			'secondsSinceSync' => $seconds_since_sync,
		);

		if ( ! $tables_exist ) {
			return $this->with_status(
				$status,
				'missing_tables',
				'error',
				__( 'Subscription analytics tables are missing. Run the database migration before using this report.', 'additional-subscriptions-analytics' )
			);
		}

		if ( BackfillScheduler::STATUS_FAILED === $backfill_status ) {
			$message = __( 'Subscription analytics backfill failed.', 'additional-subscriptions-analytics' );

			if ( '' !== $failure_message ) {
				$message = \sprintf(
					/* translators: %s: failure message. */
					__( 'Subscription analytics backfill failed: %s', 'additional-subscriptions-analytics' ),
					$failure_message
				);
			}

			return $this->with_status( $status, 'failed', 'error', $message );
		}

		if (
			BackfillScheduler::STATUS_QUEUED === $backfill_status
			|| BackfillScheduler::STATUS_RUNNING === $backfill_status
		) {
			return $this->with_status(
				$status,
				'running',
				'info',
				__( 'Subscription analytics backfill is running. Report results may be incomplete until it finishes.', 'additional-subscriptions-analytics' ),
				false
			);
		}

		if ( '' === $completed_at_gmt || Installer::BACKFILL_STATUS_NOT_STARTED === $backfill_status ) {
			return $this->with_status(
				$status,
				'needs_backfill',
				'warning',
				__( 'Subscription analytics tables need a backfill before this report has complete data.', 'additional-subscriptions-analytics' )
			);
		}

		if ( '' === $last_sync_at_gmt || $seconds_since_sync > self::STALE_AFTER_SECONDS ) {
			return $this->with_status(
				$status,
				'stale',
				'warning',
				__( 'Subscription analytics data has not synced recently. Recent subscription changes may be missing.', 'additional-subscriptions-analytics' )
			);
		}

		return $status;
	}

	/**
	 * Render an admin notice on the report page when the status needs attention.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_admin_notice(): void {
		$status = $this->get_status();

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce core capability.
		if ( ! \current_user_can( 'manage_woocommerce' ) || ! $this->is_report_screen() ) {
			return;
		}

		if ( ! $status['actionRequired'] && 'success' === $status['severity'] ) {
			return;
		}

		$notice_class = $this->get_notice_class( (string) $status['severity'] );

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			\esc_attr( $notice_class ),
			\esc_html( (string) $status['message'] )
		);
	}

	/**
	 * Determine whether the current admin screen is the upcoming renewals report.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_report_screen(): bool {
		if ( ! \function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = \get_current_screen();

		if (
			! \is_object( $screen )
			|| ! isset( $screen->id )
			|| 'woocommerce_page_wc-admin' !== $screen->id
		) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen targeting.
		$page = isset( $_GET['page'] )
			? \sanitize_key( (string) \wp_unslash( $_GET['page'] ) )
			: '';
		$path = isset( $_GET['path'] )
			? \sanitize_text_field( (string) \wp_unslash( $_GET['path'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'wc-admin' === $page && Menu::REPORT_PATH === $path;
	}

	/**
	 * Merge a concrete state into the base payload.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, bool|int|string> $status          Base status payload.
	 * @param string                         $state           Machine-readable state.
	 * @param string                         $severity        Notice severity.
	 * @param string                         $message         Merchant-facing message.
	 * @param bool                           $action_required Whether the state needs action.
	 *
	 * @return array<string, bool|int|string>
	 */
	private function with_status(
		array $status,
		string $state,
		string $severity,
		string $message,
		bool $action_required = true
	): array {
		$status['state']          = $state;
		$status['severity']       = $severity;
		$status['message']        = $message;
		$status['actionRequired'] = $action_required;

		return $status;
	}

	/**
	 * Get seconds elapsed since a GMT timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @param string $timestamp_gmt GMT timestamp.
	 *
	 * @return int
	 */
	private function get_seconds_since_gmt_timestamp( string $timestamp_gmt ): int {
		if ( '' === $timestamp_gmt ) {
			return 0;
		}

		$timestamp = \strtotime( $timestamp_gmt . ' UTC' );

		if ( false === $timestamp ) {
			return 0;
		}

		return \max( 0, \time() - $timestamp );
	}

	/**
	 * Map a status severity to a WordPress admin notice class.
	 *
	 * @since 0.1.0
	 *
	 * @param string $severity Status severity.
	 *
	 * @return string
	 */
	private function get_notice_class( string $severity ): string {
		return match ( $severity ) {
			'error' => 'notice-error',
			'warning' => 'notice-warning',
			'success' => 'notice-success',
			default => 'notice-info',
		};
	}
}
