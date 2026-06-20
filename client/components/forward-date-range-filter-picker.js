/**
 * External dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies -- WooCommerce dependency extraction externalizes WordPress and WooCommerce packages. */
import moment from 'moment';
import { Button, Dropdown, TabPanel } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { isoDateFormat } from '@woocommerce/date';
import {
	DateRange,
	DropdownButton,
	SegmentedSelection,
} from '@woocommerce/components';
import { updateQueryString } from '@woocommerce/navigation';

export const DEFAULT_FORWARD_PERIOD = 'next_30_days';
export const FUTURE_PERIOD_QUERY_PARAM = 'future_period';

const shortDateFormatPlaceholder = __( 'MM/DD/YYYY', 'woocommerce' );
const shortDateFormat = 'MM/DD/YYYY';

const padDatePart = ( value ) => String( value ).padStart( 2, '0' );

const cloneDate = ( date ) => new Date( date.getTime() );

const startOfToday = () => {
	const date = new Date();
	date.setHours( 0, 0, 0, 0 );

	return date;
};

const addDays = ( date, days ) => {
	const nextDate = cloneDate( date );
	nextDate.setDate( nextDate.getDate() + days );

	return nextDate;
};

const addMonths = ( date, months ) => {
	const nextDate = cloneDate( date );
	nextDate.setMonth( nextDate.getMonth() + months );

	return nextDate;
};

const toIsoDate = ( date ) =>
	[
		date.getFullYear(),
		padDatePart( date.getMonth() + 1 ),
		padDatePart( date.getDate() ),
	].join( '-' );

const getMoment = ( value ) => {
	const date = moment( value, isoDateFormat, true );

	return date.isValid() ? date : null;
};

const getTodayMoment = () =>
	moment( toIsoDate( startOfToday() ), isoDateFormat );

const formatDateRange = ( after, before ) => {
	if ( ! after?.isValid?.() || ! before?.isValid?.() ) {
		return '';
	}

	const format = new Intl.DateTimeFormat( undefined, {
		year: after.year() === before.year() ? undefined : 'numeric',
		month: 'short',
		day: 'numeric',
	} );
	const endFormat = new Intl.DateTimeFormat( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} );

	return sprintf(
		/* translators: 1: start date, 2: end date. */
		__( '%1$s - %2$s', 'additional-subscriptions-analytics' ),
		format.format( after.toDate() ),
		endFormat.format( before.toDate() )
	);
};

export const forwardPeriodOptions = [
	{
		value: 'today',
		label: __( 'Today', 'woocommerce' ),
		getRange: ( today ) => [ today, today ],
	},
	{
		value: 'tomorrow',
		label: __( 'Tomorrow', 'additional-subscriptions-analytics' ),
		getRange: ( today ) => [ addDays( today, 1 ), addDays( today, 1 ) ],
	},
	{
		value: 'next_7_days',
		label: __( 'Next 7 days', 'additional-subscriptions-analytics' ),
		getRange: ( today ) => [ today, addDays( today, 6 ) ],
	},
	{
		value: 'next_30_days',
		label: __( 'Next 30 days', 'additional-subscriptions-analytics' ),
		getRange: ( today ) => [ today, addDays( today, 29 ) ],
	},
	{
		value: 'next_90_days',
		label: __( 'Next 90 days', 'additional-subscriptions-analytics' ),
		getRange: ( today ) => [ today, addDays( today, 89 ) ],
	},
	{
		value: 'next_12_months',
		label: __( 'Next 12 months', 'additional-subscriptions-analytics' ),
		getRange: ( today ) => [ today, addDays( addMonths( today, 12 ), -1 ) ],
	},
];

const compareOptions = [
	{
		value: 'previous_period',
		label: __( 'Previous period', 'woocommerce' ),
	},
	{
		value: 'previous_year',
		label: __( 'Previous year', 'woocommerce' ),
	},
];

const getPeriodConfig = ( period ) =>
	forwardPeriodOptions.find( ( option ) => option.value === period ) ||
	forwardPeriodOptions.find(
		( option ) => option.value === DEFAULT_FORWARD_PERIOD
	);

export const getForwardDateQuery = ( period = DEFAULT_FORWARD_PERIOD ) => {
	const periodConfig = getPeriodConfig( period );
	const [ after, before ] = periodConfig.getRange( startOfToday() );

	return {
		period: 'custom',
		compare: 'previous_period',
		[ FUTURE_PERIOD_QUERY_PARAM ]: periodConfig.value,
		after: toIsoDate( after ),
		before: toIsoDate( before ),
	};
};

export const getDefaultForwardDateRange = () =>
	new URLSearchParams( getForwardDateQuery() ).toString();

const findMatchingPreset = ( after, before ) => {
	if ( ! after || ! before ) {
		return DEFAULT_FORWARD_PERIOD;
	}

	return (
		forwardPeriodOptions.find( ( option ) => {
			const query = getForwardDateQuery( option.value );

			return query.after === after && query.before === before;
		} )?.value || 'custom'
	);
};

const getInitialPickerState = ( query ) => {
	const defaults = getForwardDateQuery();
	const after = getMoment( query.after ) || getMoment( defaults.after );
	const before = getMoment( query.before ) || getMoment( defaults.before );
	const queryPeriod = query[ FUTURE_PERIOD_QUERY_PARAM ];
	const matchingPeriod = forwardPeriodOptions.some(
		( option ) => option.value === queryPeriod
	)
		? queryPeriod
		: findMatchingPreset( query.after, query.before );

	return {
		futurePeriod: matchingPeriod,
		compare: query.compare || defaults.compare,
		after,
		before,
		focusedInput: 'startDate',
		afterText: after ? after.format( shortDateFormat ) : '',
		beforeText: before ? before.format( shortDateFormat ) : '',
		afterError: null,
		beforeError: null,
	};
};

const getSecondaryDateRange = ( after, before, compare ) => {
	if ( compare === 'previous_year' ) {
		return {
			after: after.clone().subtract( 1, 'year' ),
			before: before.clone().subtract( 1, 'year' ),
		};
	}

	const difference = before.diff( after, 'days' );
	const secondaryBefore = after.clone().subtract( 1, 'day' );

	return {
		after: secondaryBefore.clone().subtract( difference, 'days' ),
		before: secondaryBefore,
	};
};

const getButtonLabels = ( query ) => {
	const state = getInitialPickerState( query );
	const periodConfig = getPeriodConfig( state.futurePeriod );
	const primaryLabel =
		'custom' === state.futurePeriod
			? __( 'Custom', 'woocommerce' )
			: periodConfig.label;
	const secondary = getSecondaryDateRange(
		state.after,
		state.before,
		state.compare
	);
	const compareLabel =
		compareOptions.find( ( option ) => option.value === state.compare )
			?.label || compareOptions[ 0 ].label;

	return [
		`${ primaryLabel } (${ formatDateRange( state.after, state.before ) })`,
		`${ __( 'vs.', 'woocommerce' ) } ${ compareLabel } (${ formatDateRange(
			secondary.after,
			secondary.before
		) })`,
	];
};

const getValidationErrors = ( state ) => {
	const today = getTodayMoment();
	const errors = {
		afterError: null,
		beforeError: null,
	};

	if ( ! state.after?.isValid?.() ) {
		errors.afterError = __(
			'Choose a valid start date.',
			'additional-subscriptions-analytics'
		);
	} else if ( state.after.isBefore( today, 'day' ) ) {
		errors.afterError = __(
			'Choose today or a future date.',
			'additional-subscriptions-analytics'
		);
	}

	if ( ! state.before?.isValid?.() ) {
		errors.beforeError = __(
			'Choose a valid end date.',
			'additional-subscriptions-analytics'
		);
	} else if ( state.before.isBefore( today, 'day' ) ) {
		errors.beforeError = __(
			'Choose today or a future date.',
			'additional-subscriptions-analytics'
		);
	} else if (
		state.after?.isValid?.() &&
		state.before.isBefore( state.after, 'day' )
	) {
		errors.beforeError = __(
			'Choose an end date on or after the start date.',
			'additional-subscriptions-analytics'
		);
	}

	return errors;
};

const hasValidationErrors = ( errors ) =>
	Boolean( errors.afterError || errors.beforeError );

const ForwardDateRangeContent = ( { onClose, onSelect, query } ) => {
	const [ state, setState ] = useState( () =>
		getInitialPickerState( query )
	);

	const updateState = ( update ) => {
		setState( ( previous ) => ( {
			...previous,
			...update,
		} ) );
	};

	const onPeriodSelect = ( update ) => {
		const nextPeriod = update[ FUTURE_PERIOD_QUERY_PARAM ];
		const periodConfig = getPeriodConfig( nextPeriod );
		const [ afterDate, beforeDate ] = periodConfig.getRange(
			startOfToday()
		);
		const after = moment( toIsoDate( afterDate ), isoDateFormat );
		const before = moment( toIsoDate( beforeDate ), isoDateFormat );

		updateState( {
			futurePeriod: nextPeriod,
			after,
			before,
			afterText: after.format( shortDateFormat ),
			beforeText: before.format( shortDateFormat ),
			afterError: null,
			beforeError: null,
		} );
	};

	const onCompareSelect = ( update ) => {
		updateState( {
			compare: update.compare,
		} );
	};

	const resetCustomValues = () => {
		const defaults = getInitialPickerState( getForwardDateQuery() );
		updateState( {
			after: defaults.after,
			before: defaults.before,
			afterText: defaults.afterText,
			beforeText: defaults.beforeText,
			afterError: null,
			beforeError: null,
		} );
	};

	const selectRange = ( selectedTab ) => ( event ) => {
		const errors = getValidationErrors( state );

		if ( hasValidationErrors( errors ) ) {
			updateState( errors );
			return;
		}

		onSelect(
			{
				period: 'custom',
				compare: state.compare,
				[ FUTURE_PERIOD_QUERY_PARAM ]:
					selectedTab === 'custom' ? 'custom' : state.futurePeriod,
				after: state.after.format( isoDateFormat ),
				before: state.before.format( isoDateFormat ),
			},
			event
		);
		onClose( event );
	};

	const isFutureDateInvalid = ( date ) =>
		moment( date ).isBefore( getTodayMoment(), 'day' );
	const errors = getValidationErrors( state );
	const updateDisabled = hasValidationErrors( errors );
	const afterError = state.afterError || errors.afterError;
	const beforeError = state.beforeError || errors.beforeError;
	const initialTabName =
		'custom' === state.futurePeriod ? 'custom' : 'period';

	return (
		<div>
			<div className="screen-reader-text" tabIndex="0">
				{ __(
					'Select future date range and comparison',
					'additional-subscriptions-analytics'
				) }
			</div>
			<div>
				<h3 className="woocommerce-filters-date__text">
					{ __(
						'select a future date range',
						'additional-subscriptions-analytics'
					) }
				</h3>
				<TabPanel
					tabs={ [
						{
							name: 'period',
							title: __( 'Presets', 'woocommerce' ),
							className: 'woocommerce-filters-date__tab',
						},
						{
							name: 'custom',
							title: __( 'Custom', 'woocommerce' ),
							className: 'woocommerce-filters-date__tab',
						},
					] }
					className="woocommerce-filters-date__tabs"
					activeClass="is-active"
					initialTabName={ initialTabName }
				>
					{ ( selected ) => (
						<>
							{ selected.name === 'period' && (
								<SegmentedSelection
									options={ forwardPeriodOptions.map(
										( { value, label } ) => ( {
											value,
											label,
										} )
									) }
									selected={ state.futurePeriod }
									onSelect={ onPeriodSelect }
									name={ FUTURE_PERIOD_QUERY_PARAM }
									legend={ __(
										'select a future preset period',
										'additional-subscriptions-analytics'
									) }
								/>
							) }
							{ selected.name === 'custom' && (
								<DateRange
									after={ state.after }
									before={ state.before }
									onUpdate={ updateState }
									isInvalidDate={ isFutureDateInvalid }
									focusedInput={ state.focusedInput }
									afterText={ state.afterText }
									beforeText={ state.beforeText }
									afterError={ afterError }
									beforeError={ beforeError }
									shortDateFormat={ shortDateFormat }
									shortDateFormatPlaceholder={
										shortDateFormatPlaceholder
									}
								/>
							) }
							<div
								className={ `woocommerce-filters-date__content-controls${
									selected.name === 'custom'
										? ' is-custom'
										: ''
								}` }
							>
								<h3 className="woocommerce-filters-date__text">
									{ __( 'compare to', 'woocommerce' ) }
								</h3>
								<SegmentedSelection
									options={ compareOptions }
									selected={ state.compare }
									onSelect={ onCompareSelect }
									name="compare"
									legend={ __(
										'select a comparison period',
										'woocommerce'
									) }
								/>
								<div className="woocommerce-filters-date__button-group">
									{ selected.name === 'custom' && (
										<Button
											className="woocommerce-filters-date__button"
											variant="secondary"
											onClick={ resetCustomValues }
										>
											{ __( 'Reset', 'woocommerce' ) }
										</Button>
									) }
									<Button
										className="woocommerce-filters-date__button"
										variant="primary"
										disabled={ updateDisabled }
										onClick={ selectRange( selected.name ) }
									>
										{ __( 'Update', 'woocommerce' ) }
									</Button>
								</div>
							</div>
						</>
					) }
				</TabPanel>
			</div>
		</div>
	);
};

const ForwardDateRangeFilterPicker = ( {
	onDateSelect = () => {},
	path,
	query,
} ) => (
	<div className="woocommerce-filters-filter">
		<span className="woocommerce-filters-label">
			{ __( 'Date range', 'woocommerce' ) }
		</span>
		<Dropdown
			contentClassName="woocommerce-filters-date__content"
			expandOnMobile
			popoverProps={ {
				placement: 'bottom',
			} }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<DropdownButton
					onClick={ onToggle }
					isOpen={ isOpen }
					labels={ getButtonLabels( query ) }
				/>
			) }
			renderContent={ ( { onClose } ) => (
				<ForwardDateRangeContent
					onClose={ onClose }
					query={ query }
					onSelect={ ( data ) => {
						updateQueryString( data, path, query );
						onDateSelect( data );
					} }
				/>
			) }
		/>
	</div>
);

export default ForwardDateRangeFilterPicker;
