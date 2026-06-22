/**
 * External dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies -- WooCommerce dependency extraction externalizes WordPress and WooCommerce packages. */
import { Button, Notice, SelectControl } from '@wordpress/components';
import { addFilter } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import { format as formatDate } from '@wordpress/date';
import { useContext, useMemo, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	downloadCSVFile,
	generateCSVDataFromTable,
	generateCSVFileName,
} from '@woocommerce/csv-export';
import {
	EXPORT_STORE_NAME,
	getReportChartData,
	getTooltipValueFormat,
	getReportTableData,
	reportsStore,
} from '@woocommerce/data';
import {
	AdvancedFilters,
	Chart,
	FilterPicker,
	Link,
	SummaryList,
	TableCard,
} from '@woocommerce/components';
import { formatValue } from '@woocommerce/number';
import { getChartTypeForQuery } from '@woocommerce/date';
import { CurrencyContext } from '@woocommerce/currency';
import { getNewPath, updateQueryString } from '@woocommerce/navigation';

/**
 * Internal dependencies
 */
import ForwardDateRangeFilterPicker from '../../components/forward-date-range-filter-picker';
import {
	REPORT_SLUG,
	REPORT_TITLE,
	advancedFilters,
	charts,
	filters,
	getDefaultDateRange,
	getDefaultQuery,
	getHeaders,
	groupByOptions,
	normalizeForwardDateQuery,
} from './config';

const REPORT_PATH = '/analytics/upcoming-renewal-revenue';
const CHART_INTERVAL = 'day';

const getInitialQuery = ( incomingQuery = {} ) => {
	const defaults = getDefaultQuery();
	const forwardQuery = { ...incomingQuery };
	const normalizedDateQuery = normalizeForwardDateQuery( forwardQuery );
	const query = {
		...defaults,
		...forwardQuery,
		...normalizedDateQuery,
	};

	return {
		...query,
		interval: CHART_INTERVAL,
		paged: Number.parseInt(
			forwardQuery.paged || forwardQuery.page || defaults.paged,
			10
		),
		per_page: Number.parseInt(
			forwardQuery.per_page || defaults.per_page,
			10
		),
	};
};

const formatNumber = ( value, maximumFractionDigits = 0 ) =>
	new Intl.NumberFormat( undefined, {
		maximumFractionDigits,
	} ).format( Number( value ) || 0 );

const parseLocalDate = ( value ) => {
	if ( ! value ) {
		return null;
	}

	const date = new Date( value.replace( ' ', 'T' ) );

	return Number.isNaN( date.getTime() ) ? null : date;
};

const formatDateValue = ( value, options ) => {
	const date = parseLocalDate( value );

	if ( ! date ) {
		return value || '';
	}

	return new Intl.DateTimeFormat( undefined, options ).format( date );
};

const formatPeriod = ( item ) => {
	if ( item.grouping === 'year' ) {
		return formatDateValue( item.date_start, { year: 'numeric' } );
	}

	if ( item.grouping === 'month' ) {
		return formatDateValue( item.date_start, {
			year: 'numeric',
			month: 'long',
		} );
	}

	if ( item.grouping === 'week' ) {
		return sprintf(
			/* translators: 1: period start date, 2: period end date. */
			__( '%1$s - %2$s', 'additional-subscriptions-analytics' ),
			formatDateValue( item.date_start, {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
			} ),
			formatDateValue( item.date_end, {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
			} )
		);
	}

	return formatDateValue( item.date_start, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} );
};

const getRows = ( data = [], formatAmount ) =>
	data.map( ( item ) => [
		{
			display: formatPeriod( item ),
			value: item.date_start,
		},
		{
			display: formatNumber( item.renewals_count ),
			value: item.renewals_count,
		},
		{
			display: formatNumber( item.subscription_count ),
			value: item.subscription_count,
		},
		{
			display: formatAmount( item.recurring_total ),
			value: item.recurring_total,
		},
	] );

const getSelectedChart = ( query ) =>
	charts.find( ( chart ) => chart.key === query.chart ) || charts[ 0 ];

const createDateFormatter = ( format ) => ( date ) =>
	formatDate( format, date );

const buildChartData = ( primaryData, selectedChart ) => {
	const primaryIntervals = primaryData?.data?.intervals || [];

	return primaryIntervals.map( ( interval ) => ( {
		date: formatDate( 'Y-m-d\\TH:i:s', interval.date_start ),
		[ selectedChart.key ]: {
			label: selectedChart.label,
			labelDate: interval.date_start,
			value: Number( interval.subtotals?.[ selectedChart.key ] || 0 ),
		},
	} ) );
};

const SummaryMetric = ( { href, label, selected, value } ) => (
	<li className="woocommerce-summary__item-container">
		<Link
			className={ [
				'woocommerce-summary__item',
				selected ? 'is-selected' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
			aria-current={ selected ? 'page' : null }
			href={ href }
			role="menuitem"
			type="wc-admin"
		>
			<div className="woocommerce-summary__item-label">{ label }</div>
			<div className="woocommerce-summary__item-data">
				<div className="woocommerce-summary__item-value asa-summary-metric__value">
					{ value }
				</div>
			</div>
		</Link>
	</li>
);

const SyncStatusNotice = () => {
	const syncStatus = window.asaUpcomingRenewals?.syncStatus;

	if ( ! syncStatus?.message ) {
		return null;
	}

	return (
		<Notice
			className="asa-upcoming-renewal-revenue__sync-status"
			status={ syncStatus.severity || 'info' }
			isDismissible={ false }
		>
			{ syncStatus.message }
		</Notice>
	);
};

const RevenueFilters = ( { path, query } ) => {
	const currency = useContext( CurrencyContext );

	return (
		<div className="woocommerce-analytics-report-header woocommerce-filters">
			<div className="woocommerce-filters__basic-filters">
				<ForwardDateRangeFilterPicker path={ path } query={ query } />
				{ filters.map( ( config ) =>
					config.showFilters( query ) ? (
						<FilterPicker
							key={ config.param }
							config={ config }
							advancedFilters={ advancedFilters }
							query={ query }
							path={ path }
						/>
					) : null
				) }
				<div className="asa-revenue-grouping-control">
					<SelectControl
						label={ __(
							'Table grouping',
							'additional-subscriptions-analytics'
						) }
						value={ query.groupby }
						options={ groupByOptions }
						onChange={ ( value ) =>
							updateQueryString(
								{ groupby: value, paged: 1 },
								path,
								query
							)
						}
					/>
				</div>
			</div>
			{ query.filter === 'advanced' && (
				<div className="woocommerce-filters__advanced-filters">
					<AdvancedFilters
						currency={ currency?.getCurrencyConfig?.() || {} }
						config={ advancedFilters }
						path={ path }
						query={ query }
					/>
				</div>
			) }
		</div>
	);
};

const RevenueSummary = ( { path, query, selectedChart, summaryData } ) => {
	const currency = useContext( CurrencyContext );
	const currencyConfig = currency?.getCurrencyConfig?.() || {};
	const formatMetricValue = ( value, type ) => {
		if ( type === 'currency' && currency?.formatAmount ) {
			return currency.formatAmount( value );
		}

		return formatValue( currencyConfig, type, value );
	};

	if ( summaryData.isError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'The upcoming renewal revenue summary could not be loaded.',
					'additional-subscriptions-analytics'
				) }
			</Notice>
		);
	}

	if ( summaryData.isRequesting ) {
		return null;
	}

	return (
		<SummaryList>
			{ () =>
				charts.map( ( chart ) => (
					<SummaryMetric
						key={ chart.key }
						href={ getNewPath( { chart: chart.key }, path, query ) }
						label={ chart.label }
						selected={ selectedChart.key === chart.key }
						value={ formatMetricValue(
							summaryData.data?.totals?.[ chart.key ] || 0,
							chart.type
						) }
					/>
				) )
			}
		</SummaryList>
	);
};

const RevenueChart = ( { path, primaryData, query, selectedChart } ) => {
	const currency = useContext( CurrencyContext );
	const chartQuery = useMemo(
		() => ( { ...query, interval: CHART_INTERVAL } ),
		[ query ]
	);

	if ( primaryData.isError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'The upcoming renewal revenue chart could not be loaded.',
					'additional-subscriptions-analytics'
				) }
			</Notice>
		);
	}

	return (
		<Chart
			allowedIntervals={ [ CHART_INTERVAL ] }
			chartType={ getChartTypeForQuery( chartQuery ) }
			currency={ currency?.getCurrencyConfig?.() || {} }
			data={ buildChartData( primaryData, selectedChart ) }
			dateParser="%Y-%m-%dT%H:%M:%S"
			emptyMessage={ __(
				'No data for the selected date range',
				'woocommerce'
			) }
			interval={ CHART_INTERVAL }
			isRequesting={ primaryData.isRequesting }
			legendPosition="top"
			legendTotals={ {
				[ selectedChart.key ]: Number(
					primaryData?.data?.totals?.[ selectedChart.key ] || 0
				),
			} }
			mode="item-comparison"
			path={ path }
			query={ chartQuery }
			screenReaderFormat={ createDateFormatter( 'F j, Y' ) }
			showHeaderControls={ false }
			title={ selectedChart.label }
			tooltipLabelFormat={ createDateFormatter( 'F j, Y' ) }
			tooltipTitle={ selectedChart.label }
			tooltipValueFormat={ getTooltipValueFormat(
				selectedChart.type,
				currency?.formatAmount || formatNumber
			) }
			valueType={ selectedChart.type }
			xFormat={ createDateFormatter( 'M j' ) }
			x2Format={ createDateFormatter( 'Y' ) }
		/>
	);
};

const UpcomingRenewalRevenueReport = ( {
	query: incomingQuery = {},
	path = REPORT_PATH,
} ) => {
	const query = useMemo(
		() => getInitialQuery( incomingQuery ),
		[ incomingQuery ]
	);
	const chartQuery = useMemo(
		() => ( { ...query, interval: CHART_INTERVAL } ),
		[ query ]
	);
	const defaultDateRange = useMemo( () => getDefaultDateRange(), [] );
	const selectedChart = getSelectedChart( query );
	const [ isExporting, setIsExporting ] = useState( false );
	const headers = useMemo( () => getHeaders(), [] );
	const currency = useContext( CurrencyContext );
	const formatAmount = useMemo( () => {
		const currencyConfig = currency?.getCurrencyConfig?.() || {};

		return (
			currency?.formatAmount ||
			( ( value ) => formatValue( currencyConfig, 'currency', value ) )
		);
	}, [ currency ] );
	const { createNotice } = useDispatch( 'core/notices' );
	const { startExport } = useDispatch( EXPORT_STORE_NAME );
	const { chartData, tableData } = useSelect(
		( select ) => {
			const selector = select( reportsStore );
			const fields = charts.map( ( chart ) => chart.key );

			return {
				chartData: getReportChartData( {
					endpoint: REPORT_SLUG,
					dataType: 'primary',
					query: chartQuery,
					selector,
					limitBy: [ REPORT_SLUG ],
					filters,
					advancedFilters,
					defaultDateRange,
					fields,
				} ),
				tableData: getReportTableData( {
					endpoint: REPORT_SLUG,
					query,
					selector,
					filters,
					advancedFilters,
					defaultDateRange,
				} ),
			};
		},
		[ chartQuery, defaultDateRange, query ]
	);
	const items = tableData.items || {};
	const data = useMemo( () => items.data || [], [ items.data ] );
	const rows = useMemo(
		() => getRows( data, formatAmount ),
		[ data, formatAmount ]
	);
	const totalRows = Number.parseInt( items.totalResults || 0, 10 );

	const onQueryChange = ( param ) => ( value, direction ) => {
		const updates = {};

		if ( param === 'sort' ) {
			updates.orderby = value;
			updates.order = direction;
			updates.paged = 1;
		} else if ( param === 'paged' ) {
			updates.paged = Number.parseInt( value, 10 ) || 1;
		} else if ( param === 'per_page' ) {
			updates.per_page = Number.parseInt( value, 10 ) || 25;
			updates.paged = 1;
		} else {
			updates[ param ] = value;
			updates.paged = 1;
		}

		updateQueryString( updates, path, query );
	};

	const downloadVisibleRows = () => {
		downloadCSVFile(
			generateCSVFileName( REPORT_TITLE, tableData.query || query ),
			generateCSVDataFromTable( headers, rows )
		);
	};

	const onDownload = async () => {
		if ( isExporting ) {
			return;
		}

		if ( totalRows <= data.length ) {
			downloadVisibleRows();
			return;
		}

		try {
			setIsExporting( true );
			await startExport( REPORT_SLUG, tableData.query || query );
			createNotice(
				'success',
				sprintf(
					/* translators: %s: report title. */
					__(
						'Your %s report export will be emailed to you.',
						'additional-subscriptions-analytics'
					),
					REPORT_TITLE
				)
			);
		} catch ( error ) {
			createNotice(
				'error',
				__(
					'There was a problem exporting the upcoming renewal revenue report. Please try again.',
					'additional-subscriptions-analytics'
				)
			);
		} finally {
			setIsExporting( false );
		}
	};

	if ( tableData.isError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'The upcoming renewal revenue report could not be loaded.',
					'additional-subscriptions-analytics'
				) }
			</Notice>
		);
	}

	return (
		<div className="asa-upcoming-renewal-revenue">
			<SyncStatusNotice />
			<RevenueFilters path={ path } query={ query } />
			<RevenueSummary
				path={ path }
				query={ query }
				selectedChart={ selectedChart }
				summaryData={ chartData }
			/>
			<RevenueChart
				path={ path }
				primaryData={ chartData }
				query={ query }
				selectedChart={ selectedChart }
			/>
			<TableCard
				title={ REPORT_TITLE }
				headers={ headers }
				rows={ rows }
				rowsPerPage={ query.per_page }
				totalRows={ totalRows }
				query={ query }
				isLoading={ tableData.isRequesting }
				onQueryChange={ onQueryChange }
				emptyMessage={ __(
					'No upcoming renewal revenue found for this window.',
					'additional-subscriptions-analytics'
				) }
				actions={ [
					<Button
						key="download"
						variant="secondary"
						onClick={ onDownload }
						isBusy={ isExporting }
						disabled={
							tableData.isRequesting ||
							isExporting ||
							rows.length === 0
						}
					>
						{ __(
							'Download CSV',
							'additional-subscriptions-analytics'
						) }
					</Button>,
				] }
			/>
		</div>
	);
};

addFilter(
	'woocommerce_admin_reports_list',
	'additional-subscriptions-analytics/upcoming-renewal-revenue',
	( reports ) => {
		if ( reports.some( ( report ) => report.report === REPORT_SLUG ) ) {
			return reports;
		}

		return [
			...reports,
			{
				report: REPORT_SLUG,
				title: REPORT_TITLE,
				component: UpcomingRenewalRevenueReport,
				navArgs: {
					id: 'asa-upcoming-renewal-revenue',
				},
			},
		];
	}
);
