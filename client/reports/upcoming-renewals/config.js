/**
 * External dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies -- WooCommerce dependency extraction externalizes WordPress and WooCommerce packages. */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { applyFilters } from '@wordpress/hooks';
import { __, _x } from '@wordpress/i18n';
import { getIdsFromQuery } from '@woocommerce/navigation';
import { NAMESPACE } from '@woocommerce/data';

export const REPORT_SLUG = 'upcoming-renewals';
export const REPORT_TITLE = __(
	'Upcoming renewals',
	'additional-subscriptions-analytics'
);
export const DEFAULT_STATUS = 'active';

const padDatePart = ( value ) => String( value ).padStart( 2, '0' );

const toIsoDate = ( date ) =>
	[
		date.getFullYear(),
		padDatePart( date.getMonth() + 1 ),
		padDatePart( date.getDate() ),
	].join( '-' );

const startOfToday = () => {
	const date = new Date();
	date.setHours( 0, 0, 0, 0 );

	return date;
};

const addDays = ( date, days ) => {
	const nextDate = new Date( date.getTime() );
	nextDate.setDate( nextDate.getDate() + days );

	return nextDate;
};

const getDefaultDateQuery = () => {
	const today = startOfToday();

	return {
		period: 'custom',
		compare: 'previous_period',
		after: toIsoDate( today ),
		before: toIsoDate( addDays( today, 29 ) ),
	};
};

const getRequestByIdString =
	( path, handleData ) =>
	( queryString = '', query = {} ) => {
		const idList = getIdsFromQuery( queryString );

		if ( idList.length < 1 ) {
			return Promise.resolve( [] );
		}

		const pathString = typeof path === 'function' ? path( query ) : path;

		return apiFetch( {
			path: addQueryArgs( pathString, {
				include: idList.join( ',' ),
				per_page: idList.length,
			} ),
		} ).then( ( data ) => data.map( handleData ) );
	};

const getProductLabels = getRequestByIdString(
	NAMESPACE + '/products',
	( product ) => ( {
		key: product.id,
		label: product.name,
	} )
);

const getVariationLabels = getRequestByIdString(
	( { products } = {} ) =>
		products
			? NAMESPACE + `/products/${ products }/variations`
			: NAMESPACE + '/variations',
	( variation ) => ( {
		key: variation.id,
		label: variation.name,
	} )
);

export const getDefaultDateRange = () =>
	new URLSearchParams( getDefaultDateQuery() ).toString();

export const getDefaultQuery = () => ( {
	...getDefaultDateQuery(),
	orderby: 'product_name',
	order: 'asc',
	paged: 1,
	per_page: 25,
} );

export const charts = applyFilters(
	'additional_subscriptions_analytics_upcoming_renewals_report_charts',
	[
		{
			key: 'renewals_count',
			label: __( 'Renewals', 'additional-subscriptions-analytics' ),
			type: 'number',
		},
		{
			key: 'renewal_quantity',
			label: __(
				'Renewal quantity',
				'additional-subscriptions-analytics'
			),
			order: 'desc',
			orderby: 'total_qty',
			type: 'number',
		},
		{
			key: 'recurring_total',
			label: __(
				'Recurring total',
				'additional-subscriptions-analytics'
			),
			order: 'desc',
			orderby: 'recurring_total',
			type: 'currency',
		},
	]
);

export const filters = applyFilters(
	'additional_subscriptions_analytics_upcoming_renewals_report_filters',
	[
		{
			label: __( 'Show', 'woocommerce' ),
			staticParams: [
				'chart',
				'chartType',
				'interval',
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
						'All upcoming renewals',
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
	'additional_subscriptions_analytics_upcoming_renewals_report_advanced_filters',
	{
		title: _x(
			'Upcoming renewals match <select/> filters',
			'A sentence describing filters for upcoming renewals.',
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
			product: {
				labels: {
					add: __( 'Product', 'woocommerce' ),
					placeholder: __( 'Search products', 'woocommerce' ),
					remove: __( 'Remove product filter', 'woocommerce' ),
					rule: __( 'Select a product filter match', 'woocommerce' ),
					title: __(
						'<title>Product</title> <rule/> <filter/>',
						'woocommerce'
					),
					filter: __( 'Select products', 'woocommerce' ),
				},
				rules: [
					{
						value: 'includes',
						label: _x( 'Includes', 'products', 'woocommerce' ),
					},
					{
						value: 'excludes',
						label: _x( 'Excludes', 'products', 'woocommerce' ),
					},
				],
				input: {
					component: 'Search',
					type: 'products',
					getLabels: getProductLabels,
				},
			},
			variation: {
				labels: {
					add: __( 'Product variation', 'woocommerce' ),
					placeholder: __(
						'Search product variations',
						'woocommerce'
					),
					remove: __(
						'Remove product variation filter',
						'woocommerce'
					),
					rule: __(
						'Select a product variation filter match',
						'woocommerce'
					),
					title: __(
						'<title>Product variation</title> <rule/> <filter/>',
						'woocommerce'
					),
					filter: __( 'Select variation', 'woocommerce' ),
				},
				rules: [
					{
						value: 'includes',
						label: _x( 'Includes', 'variations', 'woocommerce' ),
					},
					{
						value: 'excludes',
						label: _x( 'Excludes', 'variations', 'woocommerce' ),
					},
				],
				input: {
					component: 'Search',
					type: 'variations',
					getLabels: getVariationLabels,
				},
			},
		},
	}
);

export const getHeaders = () => [
	{
		label: __( 'Product title', 'additional-subscriptions-analytics' ),
		key: 'product_name',
		required: true,
		isLeftAligned: true,
		isSortable: true,
	},
	{
		label: __( 'Product ID', 'additional-subscriptions-analytics' ),
		key: 'product_id',
		isSortable: true,
		isNumeric: true,
	},
	{
		label: __( 'Variation ID', 'additional-subscriptions-analytics' ),
		key: 'variation_id',
		isSortable: true,
		isNumeric: true,
	},
	{
		label: __( 'Renewal quantity', 'additional-subscriptions-analytics' ),
		key: 'total_qty',
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
	{
		label: __( 'First renewal', 'additional-subscriptions-analytics' ),
		key: 'first_next_payment_date_gmt',
		isSortable: true,
	},
	{
		label: __( 'Last renewal', 'additional-subscriptions-analytics' ),
		key: 'last_next_payment_date_gmt',
		isSortable: true,
	},
];
