<?php
/**
 * Dependency and compatibility helpers.
 *
 * @package AdditionalSubscriptionsAnalytics
 * @since   0.1.0
 */

namespace AdditionalSubscriptionsAnalytics\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Checks runtime compatibility before plugin services load.
 *
 * @since 0.1.0
 */
final class Compat {

	/**
	 * Get the first dependency error for the current runtime.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null Error message, or null when dependencies are satisfied.
	 */
	public static function get_dependency_error(): ?string {
		if ( \version_compare( PHP_VERSION, ASA_MIN_PHP_VERSION, '<' ) ) {
			return \sprintf(
				/* translators: 1: plugin name, 2: required PHP version. */
				\__( '%1$s requires PHP %2$s or higher.', 'additional-subscriptions-analytics' ),
				'Additional Subscriptions Analytics',
				ASA_MIN_PHP_VERSION
			);
		}

		if ( \function_exists( 'get_bloginfo' ) && \version_compare( \get_bloginfo( 'version' ), ASA_MIN_WP_VERSION, '<' ) ) {
			return \sprintf(
				/* translators: 1: plugin name, 2: required WordPress version. */
				\__( '%1$s requires WordPress %2$s or higher.', 'additional-subscriptions-analytics' ),
				'Additional Subscriptions Analytics',
				ASA_MIN_WP_VERSION
			);
		}

		if ( ! \class_exists( 'WooCommerce' ) || ! \defined( 'WC_VERSION' ) ) {
			return \__(
				'Additional Subscriptions Analytics requires WooCommerce to be installed and active.',
				'additional-subscriptions-analytics'
			);
		}

		if ( \version_compare( WC_VERSION, ASA_MIN_WC_VERSION, '<' ) ) {
			return \sprintf(
				/* translators: 1: plugin name, 2: required WooCommerce version. */
				\__( '%1$s requires WooCommerce %2$s or higher.', 'additional-subscriptions-analytics' ),
				'Additional Subscriptions Analytics',
				ASA_MIN_WC_VERSION
			);
		}

		if ( ! \class_exists( 'WC_Subscriptions' ) ) {
			return \__(
				'Additional Subscriptions Analytics requires WooCommerce Subscriptions to be installed and active.',
				'additional-subscriptions-analytics'
			);
		}

		$subscriptions_version = self::get_subscriptions_version();

		if ( null !== $subscriptions_version && \version_compare( $subscriptions_version, ASA_MIN_WCS_VERSION, '<' ) ) {
			return \sprintf(
				/* translators: 1: plugin name, 2: required WooCommerce Subscriptions version. */
				\__( '%1$s requires WooCommerce Subscriptions %2$s or higher.', 'additional-subscriptions-analytics' ),
				'Additional Subscriptions Analytics',
				ASA_MIN_WCS_VERSION
			);
		}

		return null;
	}

	/**
	 * Get the active WooCommerce Subscriptions version.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null Version string, or null when it cannot be determined.
	 */
	public static function get_subscriptions_version(): ?string {
		if ( \class_exists( 'WC_Subscriptions' ) && isset( \WC_Subscriptions::$version ) ) {
			return (string) \WC_Subscriptions::$version;
		}

		return null;
	}
}
