<?php
/**
 * PHPUnit bootstrap.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
$_tests_dir = getenv( 'WP_TESTS_DIR' );
$_asa_vendor_dir = dirname( __DIR__ ) . '/vendor/';

if ( ! $_tests_dir && ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

if ( ! function_exists( 'asa_prioritize_test_composer_autoloader' ) ) {
	/**
	 * Move this plugin's Composer autoloader ahead of dependency plugin autoloaders.
	 *
	 * Some source checkouts include their development dependencies. WooCommerce
	 * Subscriptions registers its Composer autoloader after this plugin's test
	 * bootstrap, so PHPUnit classes can otherwise resolve from the wrong vendor
	 * directory inside wp-env.
	 *
	 * @param string $vendor_dir This plugin's vendor directory.
	 *
	 * @return void
	 */
	function asa_prioritize_test_composer_autoloader( string $vendor_dir ): void {
		if ( ! class_exists( \Composer\Autoload\ClassLoader::class ) ) {
			return;
		}

		foreach ( spl_autoload_functions() as $callback ) {
			if (
				! is_array( $callback )
				|| ! isset( $callback[0] )
				|| ! $callback[0] instanceof \Composer\Autoload\ClassLoader
			) {
				continue;
			}

			$loader         = $callback[0];
			$test_case_file = $loader->findFile( \PHPUnit\Framework\TestCase::class );

			if ( is_string( $test_case_file ) && str_starts_with( $test_case_file, $vendor_dir ) ) {
				$loader->unregister();
				$loader->register( true );
				return;
			}
		}
	}
}

if ( $_tests_dir ) {
	$polyfills_dir = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';

	if ( is_dir( $polyfills_dir ) && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_dir );
	}

	require_once $_tests_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function () use ( $_asa_vendor_dir ): void {
			$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : dirname( __DIR__, 2 );

			foreach (
				array(
					$plugin_dir . '/woocommerce/woocommerce.php',
					$plugin_dir . '/woocommerce-subscriptions/woocommerce-subscriptions.php',
				) as $dependency_plugin
			) {
				if ( file_exists( $dependency_plugin ) ) {
					require_once $dependency_plugin;
				}
			}

			asa_prioritize_test_composer_autoloader( $_asa_vendor_dir );

			require dirname( __DIR__ ) . '/additional-subscriptions-analytics.php';
		}
	);

	require $_tests_dir . '/includes/bootstrap.php';
}
