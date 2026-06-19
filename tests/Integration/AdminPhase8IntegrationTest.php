<?php
/**
 * Integration tests for Phase 8 admin registration and sync status.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

namespace AdditionalSubscriptionsAnalytics\Tests\Integration;

use AdditionalSubscriptionsAnalytics\Admin\Menu;
use AdditionalSubscriptionsAnalytics\Admin\SyncStatus;
use AdditionalSubscriptionsAnalytics\Database\Installer;
use AdditionalSubscriptionsAnalytics\Database\Migrator;
use AdditionalSubscriptionsAnalytics\Sync\BackfillScheduler;
use PHPUnit\Framework\TestCase;

if ( ! \class_exists( '\WP_UnitTestCase' ) ) {
	/**
	 * Fallback test case for non-WordPress PHPUnit runs.
	 */
	final class AdminPhase8IntegrationTest extends TestCase {

		/**
		 * Mark this suite skipped when WordPress test libraries are unavailable.
		 *
		 * @return void
		 */
		public function test_requires_wordpress_test_environment(): void {
			$this->markTestSkipped( 'Phase 8 admin integration tests require the WordPress PHPUnit environment.' );
		}
	}

	return;
}

/**
 * Tests Phase 8 WooCommerce Admin integration.
 *
 * @covers \AdditionalSubscriptionsAnalytics\Admin\Menu
 * @covers \AdditionalSubscriptionsAnalytics\Admin\SyncStatus
 * @covers \AdditionalSubscriptionsAnalytics\Plugin
 */
final class AdminPhase8IntegrationTest extends \WP_UnitTestCase {

	/**
	 * Database installer.
	 *
	 * @var Installer
	 */
	private Installer $installer;

	/**
	 * Set up database tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->installer = new Installer();
		$this->installer->install();
		$this->reset_sync_options();
	}

	/**
	 * Tear down sync options.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->reset_sync_options();

		parent::tear_down();
	}

	/**
	 * Test the report menu item is registered under WooCommerce Analytics.
	 *
	 * @return void
	 */
	public function test_report_menu_item_is_registered_under_analytics(): void {
		$report_pages = \apply_filters( 'woocommerce_analytics_report_menu_items', array() );
		$matching     = \array_values(
			\array_filter(
				$report_pages,
				static function ( array $report_page ): bool {
					return Menu::REPORT_ID === ( $report_page['id'] ?? '' );
				}
			)
		);

		$this->assertCount( 1, $matching );
		$this->assertSame( 'woocommerce-analytics', $matching[0]['parent'] );
		$this->assertSame( Menu::REPORT_PATH, $matching[0]['path'] );
		$this->assertSame( 'manage_woocommerce', $matching[0]['capability'] );
	}

	/**
	 * Test sync status reports a pending backfill before completion.
	 *
	 * @return void
	 */
	public function test_sync_status_reports_backfill_needed(): void {
		$status = ( new SyncStatus( $this->installer ) )->get_status();

		$this->assertSame( 'needs_backfill', $status['state'] );
		$this->assertSame( 'warning', $status['severity'] );
		$this->assertTrue( $status['actionRequired'] );
	}

	/**
	 * Test sync status reports a running backfill.
	 *
	 * @return void
	 */
	public function test_sync_status_reports_running_backfill(): void {
		\update_option( Migrator::OPTION_BACKFILL_STATUS, BackfillScheduler::STATUS_RUNNING );

		$status = ( new SyncStatus( $this->installer ) )->get_status();

		$this->assertSame( 'running', $status['state'] );
		$this->assertSame( 'info', $status['severity'] );
		$this->assertFalse( $status['actionRequired'] );
	}

	/**
	 * Test sync status reports failed backfill details.
	 *
	 * @return void
	 */
	public function test_sync_status_reports_failed_backfill(): void {
		\update_option( Migrator::OPTION_BACKFILL_STATUS, BackfillScheduler::STATUS_FAILED );
		\update_option( BackfillScheduler::OPTION_BACKFILL_FAILURE, 'Database timeout' );

		$status = ( new SyncStatus( $this->installer ) )->get_status();

		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( 'error', $status['severity'] );
		$this->assertStringContainsString( 'Database timeout', $status['message'] );
	}

	/**
	 * Test sync status reports stale completed data.
	 *
	 * @return void
	 */
	public function test_sync_status_reports_stale_rows(): void {
		\update_option( Migrator::OPTION_BACKFILL_STATUS, BackfillScheduler::STATUS_COMPLETED );
		\update_option( Migrator::OPTION_BACKFILL_COMPLETED_AT_GMT, '2026-06-01 00:00:00' );
		\update_option( Migrator::OPTION_LAST_SYNC_AT_GMT, '2026-06-01 00:00:00' );

		$status = ( new SyncStatus( $this->installer ) )->get_status();

		$this->assertSame( 'stale', $status['state'] );
		$this->assertSame( 'warning', $status['severity'] );
	}

	/**
	 * Test sync status reports ready data after a recent sync.
	 *
	 * @return void
	 */
	public function test_sync_status_reports_ready_rows(): void {
		$now = \gmdate( 'Y-m-d H:i:s' );

		\update_option( Migrator::OPTION_BACKFILL_STATUS, BackfillScheduler::STATUS_COMPLETED );
		\update_option( Migrator::OPTION_BACKFILL_COMPLETED_AT_GMT, $now );
		\update_option( Migrator::OPTION_LAST_SYNC_AT_GMT, $now );

		$status = ( new SyncStatus( $this->installer ) )->get_status();

		$this->assertSame( 'ready', $status['state'] );
		$this->assertSame( 'success', $status['severity'] );
		$this->assertFalse( $status['actionRequired'] );
	}

	/**
	 * Reset sync lifecycle options.
	 *
	 * @return void
	 */
	private function reset_sync_options(): void {
		\update_option( Migrator::OPTION_BACKFILL_STATUS, Installer::BACKFILL_STATUS_NOT_STARTED );
		\update_option( Migrator::OPTION_BACKFILL_STARTED_AT_GMT, '' );
		\update_option( Migrator::OPTION_BACKFILL_COMPLETED_AT_GMT, '' );
		\update_option( Migrator::OPTION_LAST_SYNC_AT_GMT, '' );
		\delete_option( BackfillScheduler::OPTION_BACKFILL_FAILURE );
		\delete_option( BackfillScheduler::OPTION_BACKFILL_LAST_PAGE );
	}
}
