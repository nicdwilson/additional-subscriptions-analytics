/**
 * WooCommerce Analytics settings extensions.
 */

/**
 * External dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies -- WooCommerce dependency extraction externalizes WordPress packages. */
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import SubscriptionAnalyticsReimport from './subscription-analytics-reimport';

addFilter(
	'woocommerce_admin_analytics_settings',
	'additional-subscriptions-analytics/subscription-analytics-reimport',
	( settings ) => ( {
		...settings,
		asa_subscription_analytics_reimport: {
			name: 'asa_subscription_analytics_reimport',
			label: __(
				'Subscription analytics data:',
				'additional-subscriptions-analytics'
			),
			inputType: 'component',
			component: SubscriptionAnalyticsReimport,
			defaultValue: '',
			helpText: __(
				'Refresh missing or incomplete subscription analytics rows without truncating tables, or remove and rebuild all derived subscription analytics data.',
				'additional-subscriptions-analytics'
			),
		},
	} )
);
