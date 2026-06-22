/**
 * External dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies -- WooCommerce dependency extraction externalizes WordPress and WooCommerce packages. */
import { applyFilters } from '@wordpress/hooks';
import { __, _x } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	FUTURE_PERIOD_QUERY_PARAM,
	getDefaultForwardDateRange,
	getForwardDateQuery,
	normalizeForwardDateQuery,
} from '../../components/forward-date-range-filter-picker';

export const REPORT_SLUG = 'upcoming-renewal-revenue';
export const REPORT_TITLE = __(
	'Upcoming renewal revenue',
	'additional-subscriptions-analytics'
);
export const DEFAULT_STATUS = 'active';
export const DEFAULT_GROUP_BY = 'month';

export const groupByOptions = [
	{
		label: __( 'Daily', 'additional-subscriptions-analytics' ),
		value: 'day',
	},
	{
		label: __( 'Weekly', 'additional-subscriptions-analytics' ),
		value: 'week',
	},
	{
		label: __( 'Monthly', 'additional-subscriptions-analytics' ),
		value: 'month',
	},
	{
		label: __( 'Annual', 'additional-subscriptions-analytics' ),
		value: 'year',
	},
];

export const getDefaultDateRange = () => getDefaultForwardDateRange();

export const getDefaultQuery = () => ( {
	...getForwardDateQuery(),
	chart: 'recurring_total',
	groupby: DEFAULT_GROUP_BY,
	interval: 'day',
	orderby: 'date_start',
	order: 'asc',
	paged: 1,
	per_page: 25,
} );

export const charts = applyFilters(
	'additional_subscriptions_analytics_upcoming_renewal_revenue_report_charts',
	[
		{
			key: 'recurring_total',
			label: __(
				'Recurring total',
				'additional-subscriptions-analytics'
			),
			type: 'currency',
		},
	]
);

export const filters = applyFilters(
	'additional_subscriptions_analytics_upcoming_renewal_revenue_report_filters',
	[
		{
			label: __( 'Show', 'woocommerce' ),
			staticParams: [
				'period',
				'compare',
				'after',
				'before',
				'chart',
				'chartType',
				'interval',
				'groupby',
				FUTURE_PERIOD_QUERY_PARAM,
				'orderby',
				'order',
				'paged',
				'per_page',
			],
			param: 'filter',
			showFilters: () => true,
			filters: [
				{
					label: __(
						'All upcoming renewal revenue',
						'additional-subscriptions-analytics'
					),
					value: 'all',
				},
				{
					label: __( 'Advanced filters', 'woocommerce' ),
					value: 'advanced',
				},
			],
		},
	]
);

export const advancedFilters = applyFilters(
	'additional_subscriptions_analytics_upcoming_renewal_revenue_report_advanced_filters',
	{
		title: _x(
			'Upcoming renewal revenue matches <select/> filters',
			'A sentence describing filters for upcoming renewal revenue.',
			'additional-subscriptions-analytics'
		),
		filters: {
			status: {
				labels: {
					add: __(
						'Subscription status',
						'additional-subscriptions-analytics'
					),
					remove: __(
						'Remove subscription status filter',
						'additional-subscriptions-analytics'
					),
					rule: __(
						'Select a subscription status filter match',
						'additional-subscriptions-analytics'
					),
					title: __(
						'<title>Subscription status</title> <rule/> <filter/>',
						'additional-subscriptions-analytics'
					),
					filter: __(
						'Select a subscription status',
						'additional-subscriptions-analytics'
					),
				},
				rules: [
					{
						value: 'is',
						label: _x(
							'Is',
							'subscription status',
							'additional-subscriptions-analytics'
						),
					},
					{
						value: 'is_not',
						label: _x(
							'Is Not',
							'subscription status',
							'additional-subscriptions-analytics'
						),
					},
				],
				input: {
					component: 'SelectControl',
					options: [
						{
							value: 'active',
							label: __(
								'Active',
								'additional-subscriptions-analytics'
							),
						},
						{
							value: 'on-hold',
							label: __(
								'On hold',
								'additional-subscriptions-analytics'
							),
						},
						{
							value: 'pending-cancel',
							label: __(
								'Pending cancellation',
								'additional-subscriptions-analytics'
							),
						},
						{
							value: 'cancelled',
							label: __(
								'Cancelled',
								'additional-subscriptions-analytics'
							),
						},
						{
							value: 'expired',
							label: __(
								'Expired',
								'additional-subscriptions-analytics'
							),
						},
					],
				},
			},
		},
	}
);

export { normalizeForwardDateQuery };

export const getHeaders = () => [
	{
		label: __( 'Period', 'additional-subscriptions-analytics' ),
		key: 'date_start',
		required: true,
		isLeftAligned: true,
		isSortable: true,
	},
	{
		label: __( 'Renewals', 'additional-subscriptions-analytics' ),
		key: 'renewals_count',
		required: true,
		isSortable: true,
		isNumeric: true,
	},
	{
		label: __( 'Subscriptions', 'additional-subscriptions-analytics' ),
		key: 'subscription_count',
		required: true,
		isSortable: true,
		isNumeric: true,
	},
	{
		label: __( 'Recurring total', 'additional-subscriptions-analytics' ),
		key: 'recurring_total',
		required: true,
		isSortable: true,
		isNumeric: true,
	},
];
