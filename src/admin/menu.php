<?php
/**
 * WooCommerce Admin menu and asset registration.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers subscription analytics reports in WooCommerce Analytics.
 *
 * @since 0.1.0
 */
final class Menu {

	/**
	 * Client script handle.
	 *
	 * @since 0.1.0
	 */
	public const SCRIPT_HANDLE = 'asa-upcoming-renewals-admin';

	/**
	 * Report menu ID.
	 *
	 * @since 0.1.0
	 */
	public const REPORT_ID = 'asa-upcoming-renewals';

	/**
	 * Revenue report menu ID.
	 *
	 * @since 0.9.5
	 */
	public const REVENUE_REPORT_ID = 'asa-upcoming-renewal-revenue';

	/**
	 * Report path inside WooCommerce Admin.
	 *
	 * @since 0.1.0
	 */
	public const REPORT_PATH = '/analytics/upcoming-renewals';

	/**
	 * Revenue report path inside WooCommerce Admin.
	 *
	 * @since 0.9.5
	 */
	public const REVENUE_REPORT_PATH = '/analytics/upcoming-renewal-revenue';

	/**
	 * Sync status helper.
	 *
	 * @var SyncStatus
	 */
	private SyncStatus $sync_status;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param SyncStatus|null $sync_status Optional sync status helper.
	 */
	public function __construct( ?SyncStatus $sync_status = null ) {
		$this->sync_status = $sync_status ?? new SyncStatus();
	}

	/**
	 * Register admin hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		\add_filter( 'woocommerce_analytics_report_menu_items', array( $this, 'register_report_menu_item' ) );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the report to WooCommerce > Analytics.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $report_pages WooCommerce Analytics report menu items.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function register_report_menu_item( array $report_pages ): array {
		$registered_ids = \array_column( $report_pages, 'id' );

		if ( ! \in_array( self::REPORT_ID, $registered_ids, true ) ) {
			$report_pages[] = array(
				'id'         => self::REPORT_ID,
				'title'      => __( 'Upcoming renewal products', 'additional-subscriptions-analytics' ),
				'parent'     => 'woocommerce-analytics',
				'path'       => self::REPORT_PATH,
				'capability' => 'manage_woocommerce',
			);
		}

		if ( ! \in_array( self::REVENUE_REPORT_ID, $registered_ids, true ) ) {
			$report_pages[] = array(
				'id'         => self::REVENUE_REPORT_ID,
				'title'      => __( 'Upcoming renewal revenue', 'additional-subscriptions-analytics' ),
				'parent'     => 'woocommerce-analytics',
				'path'       => self::REVENUE_REPORT_PATH,
				'capability' => 'manage_woocommerce',
			);
		}

		return $report_pages;
	}

	/**
	 * Enqueue the WooCommerce Admin report client.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! $this->should_enqueue_assets() ) {
			return;
		}

		$script_path = ASA_PATH . 'build/index.js';
		$asset_path  = ASA_PATH . 'build/index.asset.php';

		if ( ! \file_exists( $script_path ) || ! \file_exists( $asset_path ) ) {
			return;
		}

		$asset = require $asset_path;

		\wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ASA_URL . 'build/index.js',
			(array) ( $asset['dependencies'] ?? array() ),
			(string) ( $asset['version'] ?? ASA_VERSION ),
			true
		);

		$style_path = ASA_PATH . 'build/style-index.css';

		if ( \file_exists( $style_path ) ) {
			\wp_enqueue_style(
				self::SCRIPT_HANDLE,
				ASA_URL . 'build/style-index.css',
				array(),
				(string) ( $asset['version'] ?? ASA_VERSION )
			);
			\wp_style_add_data( self::SCRIPT_HANDLE, 'rtl', 'replace' );
		}

		if ( \function_exists( 'wp_set_script_translations' ) ) {
			\wp_set_script_translations(
				self::SCRIPT_HANDLE,
				'additional-subscriptions-analytics',
				ASA_PATH . 'languages'
			);
		}

		\wp_localize_script(
			self::SCRIPT_HANDLE,
			'asaUpcomingRenewals',
			array(
				'reportUrl'        => \admin_url( 'admin.php?page=wc-admin&path=/analytics/upcoming-renewals' ),
				'revenueReportUrl' => \admin_url( 'admin.php?page=wc-admin&path=/analytics/upcoming-renewal-revenue' ),
				'syncStatus'       => $this->sync_status->get_status(),
			)
		);
	}

	/**
	 * Determine whether the WooCommerce Admin client should be loaded.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function should_enqueue_assets(): bool {
		if (
			\class_exists( '\Automattic\WooCommerce\Admin\PageController' )
			&& \Automattic\WooCommerce\Admin\PageController::is_admin_or_embed_page()
		) {
			return true;
		}

		if ( ! \function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = \get_current_screen();

		return \is_object( $screen )
			&& isset( $screen->id )
			&& 'woocommerce_page_wc-admin' === $screen->id;
	}
}
