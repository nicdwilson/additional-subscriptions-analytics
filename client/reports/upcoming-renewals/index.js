/**
 * External dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies -- WooCommerce dependency extraction externalizes WordPress and WooCommerce packages. */
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice } from '@wordpress/components';
import { addFilter } from '@wordpress/hooks';
import { decodeEntities } from '@wordpress/html-entities';
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
import {
	getAllowedIntervalsForQuery,
	getChartTypeForQuery,
	getDateFormatsForInterval,
	getIntervalForQuery,
} from '@woocommerce/date';
import { CurrencyContext } from '@woocommerce/currency';
import { getNewPath, updateQueryString } from '@woocommerce/navigation';

/**
 * Internal dependencies
 */
import ForwardDateRangeFilterPicker from '../../components/forward-date-range-filter-picker';
import {
	DEFAULT_STATUS,
	REPORT_SLUG,
	REPORT_TITLE,
	advancedFilters,
	charts,
	filters,
	getDefaultDateRange,
	getDefaultQuery,
	getHeaders,
	normalizeForwardDateQuery,
} from './config';

const REPORT_PATH = '/analytics/upcoming-renewals';

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

const getLinkHref = ( item, rel ) => {
	const link = item?._links?.[ rel ];

	if ( Array.isArray( link ) ) {
		return link[ 0 ]?.href;
	}

	return link?.href;
};

const formatNumber = ( value, maximumFractionDigits = 0 ) =>
	new Intl.NumberFormat( undefined, {
		maximumFractionDigits,
	} ).format( Number( value ) || 0 );

const formatQuantity = ( value ) => {
	const numericValue = Number( value ) || 0;

	return formatNumber(
		numericValue,
		Number.isInteger( numericValue ) ? 0 : 8
	);
};

const formatCurrency = ( value, currency ) => {
	const numericValue = Number( value ) || 0;

	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: currency || 'USD',
		} ).format( numericValue );
	} catch ( error ) {
		return formatNumber( numericValue, 2 );
	}
};

const formatTableDate = ( value ) => {
	if ( ! value ) {
		return '';
	}

	const date = new Date( `${ value.replace( ' ', 'T' ) }Z` );

	if ( Number.isNaN( date.getTime() ) ) {
		return value;
	}

	return new Intl.DateTimeFormat( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} ).format( date );
};

const getRows = ( data = [] ) =>
	data.map( ( item ) => {
		const productName = decodeEntities( item.product_name || '' );
		const editHref = getLinkHref( item, 'edit' );

		return [
			{
				display: editHref ? (
					<Link href={ editHref } type="wp-admin">
						{ productName }
					</Link>
				) : (
					productName
				),
				value: productName,
			},
			{
				display: decodeEntities( item.product_sku || '' ),
				value: item.product_sku,
			},
			{
				display: item.product_id,
				value: item.product_id,
			},
			{
				display: item.variation_id || '',
				value: item.variation_id,
			},
			{
				display: formatQuantity( item.total_qty ),
				value: item.total_qty,
			},
			{
				display: formatNumber( item.subscription_count ),
				value: item.subscription_count,
			},
			{
				display: formatCurrency( item.recurring_total, item.currency ),
				value: item.recurring_total,
			},
			{
				display: formatTableDate( item.first_next_payment_date_gmt ),
				value: item.first_next_payment_date_gmt,
			},
			{
				display: formatTableDate( item.last_next_payment_date_gmt ),
				value: item.last_next_payment_date_gmt,
			},
		];
	} );

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

const SummaryMetric = ( {
	href,
	isOpen = false,
	label,
	onToggle,
	selected,
	value,
} ) => {
	const Container = onToggle ? Button : Link;
	const containerProps = onToggle
		? {
				'aria-expanded': isOpen,
				onClick: onToggle,
		  }
		: {
				href,
				role: 'menuitem',
				type: 'wc-admin',
		  };
	const itemClasses = [
		'woocommerce-summary__item',
		selected ? 'is-selected' : '',
	]
		.filter( Boolean )
		.join( ' ' );
	const containerClasses = [
		'woocommerce-summary__item-container',
		onToggle ? 'is-dropdown-button' : '',
		isOpen ? 'is-dropdown-expanded' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<li className={ containerClasses }>
			<Container
				className={ itemClasses }
				aria-current={ selected ? 'page' : null }
				{ ...containerProps }
			>
				<div className="woocommerce-summary__item-label">{ label }</div>
				<div className="woocommerce-summary__item-data">
					<div className="woocommerce-summary__item-value asa-summary-metric__value">
						{ value }
					</div>
				</div>
			</Container>
		</li>
	);
};

const SyncStatusNotice = () => {
	const syncStatus = window.asaUpcomingRenewals?.syncStatus;

	if ( ! syncStatus?.message ) {
		return null;
	}

	return (
		<Notice
			className="asa-upcoming-renewals__sync-status"
			status={ syncStatus.severity || 'info' }
			isDismissible={ false }
		>
			{ syncStatus.message }
		</Notice>
	);
};

const getValidationNotice = ( validationResult ) => {
	if ( ! validationResult ) {
		return null;
	}

	if ( validationResult.status === 'matched' ) {
		return {
			status: 'success',
			message: __(
				'Lookup-table totals match source subscriptions for this window.',
				'additional-subscriptions-analytics'
			),
		};
	}

	if ( validationResult.status === 'incomplete' ) {
		return {
			status: 'warning',
			message: sprintf(
				/* translators: %d: number of mismatches. */
				__(
					'Validation reached the source scan limit and found %d data-sync differences.',
					'additional-subscriptions-analytics'
				),
				validationResult.summary?.mismatchCount || 0
			),
		};
	}

	return {
		status: 'error',
		message: sprintf(
			/* translators: %d: number of mismatches. */
			__(
				'Validation found %d data-sync differences. Regenerate or resync before investigating report rendering.',
				'additional-subscriptions-analytics'
			),
			validationResult.summary?.mismatchCount || 0
		),
	};
};

const ValidationNotice = ( { validationResult } ) => {
	const notice = getValidationNotice( validationResult );

	if ( ! notice ) {
		return null;
	}

	return (
		<Notice status={ notice.status } isDismissible={ false }>
			{ notice.message }
		</Notice>
	);
};

const UpcomingRenewalsFilters = ( { path, query } ) => {
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

const UpcomingRenewalsSummary = ( {
	path,
	query,
	selectedChart,
	summaryData,
} ) => {
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
					'The upcoming renewal products summary could not be loaded.',
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
				charts.map( ( chart ) => {
					const primaryValue =
						summaryData.data?.totals?.[ chart.key ] || 0;
					const newPath = { chart: chart.key };

					if ( chart.orderby ) {
						newPath.orderby = chart.orderby;
					}

					if ( chart.order ) {
						newPath.order = chart.order;
					}

					return (
						<SummaryMetric
							key={ chart.key }
							href={ getNewPath( newPath, path, query ) }
							label={ chart.label }
							selected={ selectedChart.key === chart.key }
							value={ formatMetricValue(
								primaryValue,
								chart.type
							) }
						/>
					);
				} )
			}
		</SummaryList>
	);
};

const UpcomingRenewalsChart = ( {
	defaultDateRange,
	path,
	primaryData,
	query,
	selectedChart,
} ) => {
	const currency = useContext( CurrencyContext );
	const currentInterval = getIntervalForQuery( query, defaultDateRange );
	const allowedIntervals = getAllowedIntervalsForQuery(
		query,
		defaultDateRange
	);
	const formats = getDateFormatsForInterval(
		currentInterval,
		primaryData?.data?.intervals?.length || 0,
		{ type: 'php' }
	);

	if ( primaryData.isError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'The upcoming renewal products chart could not be loaded.',
					'additional-subscriptions-analytics'
				) }
			</Notice>
		);
	}

	return (
		<Chart
			allowedIntervals={ allowedIntervals }
			chartType={ getChartTypeForQuery( query ) }
			currency={ currency?.getCurrencyConfig?.() || {} }
			data={ buildChartData( primaryData, selectedChart ) }
			dateParser="%Y-%m-%dT%H:%M:%S"
			emptyMessage={ __(
				'No data for the selected date range',
				'woocommerce'
			) }
			interval={ currentInterval }
			isRequesting={ primaryData.isRequesting }
			legendPosition="top"
			legendTotals={ {
				[ selectedChart.key ]: Number(
					primaryData?.data?.totals?.[ selectedChart.key ] || 0
				),
			} }
			mode="item-comparison"
			path={ path }
			query={ query }
			screenReaderFormat={ createDateFormatter(
				formats.screenReaderFormat
			) }
			showHeaderControls
			title={ selectedChart.label }
			tooltipLabelFormat={ createDateFormatter(
				formats.tooltipLabelFormat
			) }
			tooltipTitle={ selectedChart.label }
			tooltipValueFormat={ getTooltipValueFormat(
				selectedChart.type,
				currency?.formatAmount || formatNumber
			) }
			valueType={ selectedChart.type }
			xFormat={ createDateFormatter( formats.xFormat ) }
			x2Format={ createDateFormatter( formats.x2Format ) }
		/>
	);
};

const UpcomingRenewalsReport = ( {
	query: incomingQuery = {},
	path = REPORT_PATH,
} ) => {
	const query = useMemo(
		() => getInitialQuery( incomingQuery ),
		[ incomingQuery ]
	);
	const defaultDateRange = useMemo( () => getDefaultDateRange(), [] );
	const selectedChart = getSelectedChart( query );
	const [ isExporting, setIsExporting ] = useState( false );
	const [ isValidating, setIsValidating ] = useState( false );
	const [ validationResult, setValidationResult ] = useState( null );
	const headers = useMemo( () => getHeaders(), [] );
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
					query,
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
		[ defaultDateRange, query ]
	);
	const items = tableData.items || {};
	const data = useMemo( () => items.data || [], [ items.data ] );
	const rows = useMemo( () => getRows( data ), [ data ] );
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

		setValidationResult( null );
		updateQueryString( updates, path, query );
	};

	const downloadVisibleRows = () => {
		downloadCSVFile(
			generateCSVFileName( REPORT_TITLE, tableData.query || query ),
			generateCSVDataFromTable( headers, rows )
		);
	};

	const onValidate = async () => {
		if ( isValidating ) {
			return;
		}

		const reportQuery = tableData.query || query;
		const params = new URLSearchParams( {
			after: reportQuery.after || query.after,
			before: reportQuery.before || query.before,
			status: query.status || query.status_is || DEFAULT_STATUS,
			limit: '5000',
		} );

		try {
			setIsValidating( true );
			setValidationResult(
				await apiFetch( {
					path: `/wc-analytics/reports/upcoming-renewals/reconcile?${ params.toString() }`,
				} )
			);
		} catch ( error ) {
			createNotice(
				'error',
				__(
					'There was a problem validating the upcoming renewal products data. Please try again.',
					'additional-subscriptions-analytics'
				)
			);
		} finally {
			setIsValidating( false );
		}
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
					'There was a problem exporting the upcoming renewal products report. Please try again.',
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
					'The upcoming renewal products report could not be loaded.',
					'additional-subscriptions-analytics'
				) }
			</Notice>
		);
	}

	return (
		<div className="asa-upcoming-renewals">
			<SyncStatusNotice />
			<ValidationNotice validationResult={ validationResult } />
			<UpcomingRenewalsFilters path={ path } query={ query } />
			<UpcomingRenewalsSummary
				path={ path }
				query={ query }
				selectedChart={ selectedChart }
				summaryData={ chartData }
			/>
			<UpcomingRenewalsChart
				defaultDateRange={ defaultDateRange }
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
					'No upcoming renewal products found for this window.',
					'additional-subscriptions-analytics'
				) }
				actions={ [
					<Button
						key="validate"
						variant="secondary"
						onClick={ onValidate }
						isBusy={ isValidating }
						disabled={ tableData.isRequesting || isValidating }
					>
						{ __(
							'Validate data',
							'additional-subscriptions-analytics'
						) }
					</Button>,
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
	'additional-subscriptions-analytics/upcoming-renewals',
	( reports ) => {
		if ( reports.some( ( report ) => report.report === REPORT_SLUG ) ) {
			return reports;
		}

		return [
			...reports,
			{
				report: REPORT_SLUG,
				title: REPORT_TITLE,
				component: UpcomingRenewalsReport,
				navArgs: {
					id: 'asa-upcoming-renewals',
				},
			},
		];
	}
);
