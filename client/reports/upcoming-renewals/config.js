/**
 * External dependencies
 */
/* eslint-disable import/no-unresolved -- WooCommerce dependency extraction externalizes WordPress packages. */
import { __ } from '@wordpress/i18n';

export const REPORT_SLUG = 'upcoming-renewals';
export const REPORT_TITLE = __(
	'Upcoming renewals',
	'additional-subscriptions-analytics'
);
export const DEFAULT_DATE_RANGE = 'period=custom&compare=previous_period';
export const DEFAULT_STATUS = 'active';

const DAY_IN_MILLISECONDS = 24 * 60 * 60 * 1000;
const FRIDAY = 5;

const cloneDate = ( date ) => new Date( date.getTime() );

const startOfToday = () => {
	const date = new Date();
	date.setHours( 0, 0, 0, 0 );

	return date;
};

const addDays = ( date, days ) => {
	const nextDate = cloneDate( date );
	nextDate.setTime( nextDate.getTime() + days * DAY_IN_MILLISECONDS );

	return nextDate;
};

const toIsoDate = ( date ) => date.toISOString().slice( 0, 10 );

const getNextFriday = () => {
	const today = startOfToday();
	let daysUntilFriday = ( FRIDAY - today.getDay() + 7 ) % 7;

	if ( daysUntilFriday === 0 ) {
		daysUntilFriday = 7;
	}

	return addDays( today, daysUntilFriday );
};

const getCustomQuery = ( after, before ) => ( {
	period: 'custom',
	compare: 'previous_period',
	after: toIsoDate( after ),
	before: toIsoDate( before ),
} );

export const getPresetQuery = ( preset ) => {
	const today = startOfToday();

	if ( preset === 'next_7_days' ) {
		return getCustomQuery( today, addDays( today, 6 ) );
	}

	if ( preset === 'next_30_days' ) {
		return getCustomQuery( today, addDays( today, 29 ) );
	}

	const nextFriday = getNextFriday();

	return getCustomQuery( nextFriday, nextFriday );
};

export const getDefaultQuery = () => ( {
	...getPresetQuery( 'next_friday' ),
	orderby: 'product_name',
	order: 'asc',
	paged: 1,
	per_page: 25,
	status: DEFAULT_STATUS,
} );

export const presets = [
	{
		key: 'next_friday',
		label: __( 'Next Friday', 'additional-subscriptions-analytics' ),
	},
	{
		key: 'next_7_days',
		label: __( 'Next 7 days', 'additional-subscriptions-analytics' ),
	},
	{
		key: 'next_30_days',
		label: __( 'Next 30 days', 'additional-subscriptions-analytics' ),
	},
];

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
