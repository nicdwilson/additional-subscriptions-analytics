/**
 * External dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies -- WooCommerce dependency extraction externalizes WordPress and WooCommerce packages. */
import moment from 'moment';
import { Button, Dropdown, TabPanel, TextControl } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { isoDateFormat } from '@woocommerce/date';
import { DropdownButton, SegmentedSelection } from '@woocommerce/components';
import { updateQueryString } from '@woocommerce/navigation';

export const DEFAULT_FORWARD_PERIOD = 'next_30_days';
export const FUTURE_PERIOD_QUERY_PARAM = 'future_period';

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

const startOfMonth = ( date ) =>
	new Date( date.getFullYear(), date.getMonth(), 1 );

const endOfMonth = ( date ) =>
	new Date( date.getFullYear(), date.getMonth() + 1, 0 );

const toIsoDate = ( date ) =>
	[
		date.getFullYear(),
		padDatePart( date.getMonth() + 1 ),
		padDatePart( date.getDate() ),
	].join( '-' );

const getDateFromIso = ( value ) => {
	if ( ! /^\d{4}-\d{2}-\d{2}$/.test( value || '' ) ) {
		return null;
	}

	const [ year, month, day ] = value.split( '-' ).map( Number );
	const date = new Date( year, month - 1, day );

	if (
		date.getFullYear() !== year ||
		date.getMonth() !== month - 1 ||
		date.getDate() !== day
	) {
		return null;
	}

	return date;
};

const getMoment = ( value ) => {
	const date = moment( value, isoDateFormat, true );

	return date.isValid() ? date : null;
};

const getTodayIso = () => toIsoDate( startOfToday() );

const getTodayMoment = () => moment( getTodayIso(), isoDateFormat );

const isIsoBefore = ( date, comparison ) =>
	moment( date, isoDateFormat, true ).isBefore(
		moment( comparison, isoDateFormat, true ),
		'day'
	);

const formatDateRange = ( after, before ) => {
	const afterMoment = getMoment( after );
	const beforeMoment = getMoment( before );

	if ( ! afterMoment || ! beforeMoment ) {
		return '';
	}

	const format = new Intl.DateTimeFormat( undefined, {
		year:
			afterMoment.year() === beforeMoment.year() ? undefined : 'numeric',
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
		format.format( afterMoment.toDate() ),
		endFormat.format( beforeMoment.toDate() )
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

const getPeriodConfig = ( period ) =>
	forwardPeriodOptions.find( ( option ) => option.value === period ) ||
	forwardPeriodOptions.find(
		( option ) => option.value === DEFAULT_FORWARD_PERIOD
	);

const isForwardPeriod = ( period ) =>
	forwardPeriodOptions.some( ( option ) => option.value === period );

export const getForwardDateQuery = ( period = DEFAULT_FORWARD_PERIOD ) => {
	const periodConfig = getPeriodConfig( period );
	const [ after, before ] = periodConfig.getRange( startOfToday() );

	return {
		period: 'custom',
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

export const normalizeForwardDateQuery = ( query = {} ) => {
	const defaults = getForwardDateQuery();
	const after = getMoment( query.after );
	const before = getMoment( query.before );
	const today = getTodayMoment();

	if (
		! after ||
		! before ||
		after.isBefore( today, 'day' ) ||
		before.isBefore( today, 'day' ) ||
		before.isBefore( after, 'day' )
	) {
		return defaults;
	}

	const afterIso = after.format( isoDateFormat );
	const beforeIso = before.format( isoDateFormat );
	const matchingPreset = findMatchingPreset( afterIso, beforeIso );
	const requestedPeriod = query[ FUTURE_PERIOD_QUERY_PARAM ];
	const futurePeriod =
		requestedPeriod === 'custom' || isForwardPeriod( requestedPeriod )
			? requestedPeriod
			: matchingPreset;

	return {
		period: 'custom',
		[ FUTURE_PERIOD_QUERY_PARAM ]:
			futurePeriod === 'custom' ? 'custom' : matchingPreset,
		after: afterIso,
		before: beforeIso,
	};
};

const getInitialPickerState = ( query ) => {
	const normalized = normalizeForwardDateQuery( query );

	return {
		futurePeriod: normalized[ FUTURE_PERIOD_QUERY_PARAM ],
		after: normalized.after,
		before: normalized.before,
		activeField: 'after',
		afterError: null,
		beforeError: null,
	};
};

const getButtonLabels = ( query ) => {
	const state = getInitialPickerState( query );
	const periodConfig = getPeriodConfig( state.futurePeriod );
	const primaryLabel =
		'custom' === state.futurePeriod
			? __( 'Custom', 'woocommerce' )
			: periodConfig.label;

	return [
		`${ primaryLabel } (${ formatDateRange( state.after, state.before ) })`,
	];
};

const getValidationErrors = ( state ) => {
	const today = getTodayIso();
	const errors = {
		afterError: null,
		beforeError: null,
	};

	if ( ! getDateFromIso( state.after ) ) {
		errors.afterError = __(
			'Choose a valid start date.',
			'additional-subscriptions-analytics'
		);
	} else if ( isIsoBefore( state.after, today ) ) {
		errors.afterError = __(
			'Choose today or a future date.',
			'additional-subscriptions-analytics'
		);
	}

	if ( ! getDateFromIso( state.before ) ) {
		errors.beforeError = __(
			'Choose a valid end date.',
			'additional-subscriptions-analytics'
		);
	} else if ( isIsoBefore( state.before, today ) ) {
		errors.beforeError = __(
			'Choose today or a future date.',
			'additional-subscriptions-analytics'
		);
	} else if (
		getDateFromIso( state.after ) &&
		isIsoBefore( state.before, state.after )
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

const getCalendarDays = ( monthDate ) => {
	const firstDay = startOfMonth( monthDate );
	const startDate = addDays( firstDay, firstDay.getDay() * -1 );

	return Array.from( { length: 42 }, ( _, index ) =>
		addDays( startDate, index )
	);
};

const getWeekdayLabels = () => {
	const baseDate = new Date( 2024, 0, 7 );
	const formatter = new Intl.DateTimeFormat( undefined, {
		weekday: 'short',
	} );

	return Array.from( { length: 7 }, ( _, index ) =>
		formatter.format( addDays( baseDate, index ) )
	);
};

const getMonthLabel = ( date ) =>
	new Intl.DateTimeFormat( undefined, {
		month: 'long',
		year: 'numeric',
	} ).format( date );

const getDayLabel = ( date ) =>
	new Intl.DateTimeFormat( undefined, {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	} ).format( date );

const getDayStateClassName = ( date, state, calendarMonth ) => {
	const isoDate = toIsoDate( date );
	const isSelected = isoDate === state.after || isoDate === state.before;
	const isInRange =
		! isIsoBefore( isoDate, state.after ) &&
		! isIsoBefore( state.before, isoDate );

	return [
		'asa-forward-date-picker__day',
		date.getMonth() !== calendarMonth.getMonth() ? 'is-outside-month' : '',
		isSelected ? 'is-selected' : '',
		isInRange && ! isSelected ? 'is-in-range' : '',
		isoDate === getTodayIso() ? 'is-today' : '',
	]
		.filter( Boolean )
		.join( ' ' );
};

const ForwardCalendar = ( { state, updateState } ) => {
	const selectedMonth = getDateFromIso( state.after ) || startOfToday();
	const [ calendarMonth, setCalendarMonth ] = useState( () =>
		startOfMonth( selectedMonth )
	);
	const weekdays = useMemo( () => getWeekdayLabels(), [] );
	const days = getCalendarDays( calendarMonth );
	const today = startOfToday();
	const previousMonth = addMonths( calendarMonth, -1 );
	const canShowPreviousMonth = endOfMonth( previousMonth ) >= today;

	const selectDate = ( date ) => {
		const selectedDate = toIsoDate( date );

		if ( state.activeField === 'after' ) {
			updateState( {
				futurePeriod: 'custom',
				after: selectedDate,
				before: isIsoBefore( state.before, selectedDate )
					? selectedDate
					: state.before,
				activeField: 'before',
				afterError: null,
				beforeError: null,
			} );
			return;
		}

		updateState( {
			futurePeriod: 'custom',
			before: selectedDate,
			activeField: 'after',
			afterError: null,
			beforeError: null,
		} );
	};

	return (
		<div className="asa-forward-date-picker__calendar">
			<div className="asa-forward-date-picker__calendar-header">
				<Button
					className="asa-forward-date-picker__month-button"
					disabled={ ! canShowPreviousMonth }
					label={ __( 'Previous month', 'woocommerce' ) }
					onClick={ () => setCalendarMonth( previousMonth ) }
				>
					<span aria-hidden="true">&lt;</span>
				</Button>
				<div className="asa-forward-date-picker__month-label">
					{ getMonthLabel( calendarMonth ) }
				</div>
				<Button
					className="asa-forward-date-picker__month-button"
					label={ __( 'Next month', 'woocommerce' ) }
					onClick={ () =>
						setCalendarMonth( addMonths( calendarMonth, 1 ) )
					}
				>
					<span aria-hidden="true">&gt;</span>
				</Button>
			</div>
			<div className="asa-forward-date-picker__calendar-grid">
				{ weekdays.map( ( weekday ) => (
					<div
						key={ weekday }
						className="asa-forward-date-picker__weekday"
					>
						{ weekday }
					</div>
				) ) }
				{ days.map( ( day ) => {
					const isoDate = toIsoDate( day );
					const isDisabled = day < today;

					return (
						<Button
							key={ isoDate }
							className={ getDayStateClassName(
								day,
								state,
								calendarMonth
							) }
							disabled={ isDisabled }
							onClick={ () => selectDate( day ) }
							aria-pressed={
								isoDate === state.after ||
								isoDate === state.before
							}
							label={ getDayLabel( day ) }
						>
							{ day.getDate() }
						</Button>
					);
				} ) }
			</div>
		</div>
	);
};

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

		updateState( {
			futurePeriod: nextPeriod,
			after: toIsoDate( afterDate ),
			before: toIsoDate( beforeDate ),
			afterError: null,
			beforeError: null,
		} );
	};

	const onDateInputChange = ( key ) => ( value ) => {
		updateState( {
			futurePeriod: 'custom',
			[ key ]: value,
			activeField: key,
			afterError: null,
			beforeError: null,
		} );
	};

	const resetCustomValues = () => {
		updateState( getInitialPickerState( getForwardDateQuery() ) );
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
				[ FUTURE_PERIOD_QUERY_PARAM ]:
					selectedTab === 'custom' ? 'custom' : state.futurePeriod,
				after: state.after,
				before: state.before,
			},
			event
		);
		onClose( event );
	};

	const errors = getValidationErrors( state );
	const updateDisabled = hasValidationErrors( errors );
	const afterError = state.afterError || errors.afterError;
	const beforeError = state.beforeError || errors.beforeError;
	const initialTabName =
		'custom' === state.futurePeriod ? 'custom' : 'period';
	const today = getTodayIso();

	return (
		<div className="asa-forward-date-picker">
			<div className="screen-reader-text" tabIndex="0">
				{ __(
					'Select future date range',
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
								<div className="asa-forward-date-picker__custom">
									<div className="asa-forward-date-picker__fields">
										<TextControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											className={
												state.activeField === 'after'
													? 'is-active'
													: ''
											}
											label={ __(
												'Start date',
												'woocommerce'
											) }
											min={ today }
											onChange={ onDateInputChange(
												'after'
											) }
											onFocus={ () =>
												updateState( {
													activeField: 'after',
												} )
											}
											type="date"
											value={ state.after }
											help={ afterError || undefined }
										/>
										<TextControl
											__next40pxDefaultSize
											__nextHasNoMarginBottom
											className={
												state.activeField === 'before'
													? 'is-active'
													: ''
											}
											label={ __(
												'End date',
												'woocommerce'
											) }
											min={ state.after || today }
											onChange={ onDateInputChange(
												'before'
											) }
											onFocus={ () =>
												updateState( {
													activeField: 'before',
												} )
											}
											type="date"
											value={ state.before }
											help={ beforeError || undefined }
										/>
									</div>
									<ForwardCalendar
										state={ state }
										updateState={ updateState }
									/>
								</div>
							) }
							<div
								className={ `woocommerce-filters-date__content-controls${
									selected.name === 'custom'
										? ' is-custom'
										: ''
								}` }
							>
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
