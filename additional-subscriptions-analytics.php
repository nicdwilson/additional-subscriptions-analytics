<?php
/**
 * Plugin Name:       Additional Subscriptions Analytics
 * Plugin URI:        https://github.com/woocommerce/additional-subscriptions-analytics
 * Description:       Adds native WooCommerce Analytics reports backed by subscription analytics lookup tables.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            WooCommerce
 * Author URI:        https://woocommerce.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       additional-subscriptions-analytics
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce, woocommerce-subscriptions
 * WC requires at least: 9.3
 * WC tested up to:      10.8
 *
 * @package AdditionalSubscriptionsAnalytics
 */

defined( 'ABSPATH' ) || exit;

define( 'ASA_VERSION', '0.1.0' );
define( 'ASA_MIN_PHP_VERSION', '8.0' );
define( 'ASA_MIN_WP_VERSION', '6.4' );
define( 'ASA_MIN_WC_VERSION', '9.3' );
define( 'ASA_MIN_WCS_VERSION', '6.0' );
define( 'ASA_FILE', __FILE__ );
define( 'ASA_PATH', plugin_dir_path( __FILE__ ) );
define( 'ASA_URL', plugin_dir_url( __FILE__ ) );
define( 'ASA_BASENAME', plugin_basename( __FILE__ ) );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

asa_register_fallback_autoloader();

/**
 * Register a minimal autoloader for development installs without Composer.
 *
 * Production packages should include Composer's generated autoloader. This
 * fallback keeps the private prototype activatable from a source checkout.
 *
 * @since 0.1.0
 *
 * @return void
 */
function asa_register_fallback_autoloader(): void {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'AdditionalSubscriptionsAnalytics\\';

			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}

			$relative_class = substr( $class_name, strlen( $prefix ) );
			$segments       = explode( '\\', $relative_class );
			$segments       = array_map(
				static function ( string $segment ): string {
					return strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '-$0', $segment ) );
				},
				$segments
			);
			$file           = ASA_PATH . 'src/' . implode( '/', $segments ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

/**
 * Declare WooCommerce feature compatibility before WooCommerce initializes.
 *
 * @since 0.1.0
 *
 * @return void
 */
function asa_declare_woocommerce_compatibility(): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
}
add_action( 'before_woocommerce_init', 'asa_declare_woocommerce_compatibility' );

/**
 * Bootstrap the plugin after dependencies have loaded.
 *
 * @since 0.1.0
 *
 * @return void
 */
function asa_bootstrap(): void {
	$dependency_error = \AdditionalSubscriptionsAnalytics\Support\Compat::get_dependency_error();

	if ( null !== $dependency_error ) {
		asa_add_dependency_notice( $dependency_error );
		return;
	}

	\AdditionalSubscriptionsAnalytics\Plugin::instance();
}
add_action( 'plugins_loaded', 'asa_bootstrap', 20 );

/**
 * Add an admin notice for a missing or unsupported dependency.
 *
 * @since 0.1.0
 *
 * @param string $message Notice message.
 *
 * @return void
 */
function asa_add_dependency_notice( string $message ): void {
	add_action(
		'admin_notices',
		static function () use ( $message ): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	);
}

register_activation_hook( __FILE__, array( \AdditionalSubscriptionsAnalytics\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \AdditionalSubscriptionsAnalytics\Plugin::class, 'deactivate' ) );
