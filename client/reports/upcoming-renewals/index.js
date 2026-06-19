/**
 * External dependencies
 */
/* eslint-disable import/no-unresolved, import/no-extraneous-dependencies -- WooCommerce dependency extraction externalizes WordPress and WooCommerce packages. */
import { Button, ButtonGroup, Notice } from '@wordpress/components';
import { addFilter } from '@wordpress/hooks';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	downloadCSVFile,
	generateCSVDataFromTable,
	generateCSVFileName,
} from '@woocommerce/csv-export';
import {
	EXPORT_STORE_NAME,
	getReportTableData,
	reportsStore,
} from '@woocommerce/data';
import { Link, TableCard } from '@woocommerce/components';

/**
 * Internal dependencies
 */
import {
	DEFAULT_DATE_RANGE,
	DEFAULT_STATUS,
	REPORT_SLUG,
	REPORT_TITLE,
	getDefaultQuery,
	getHeaders,
	getPresetQuery,
	presets,
} from './config';

const getInitialQuery = ( incomingQuery = {} ) => ( {
	...getDefaultQuery(),
	...incomingQuery,
	paged: Number.parseInt(
		incomingQuery.paged || incomingQuery.page || 1,
		10
	),
	per_page: Number.parseInt( incomingQuery.per_page || 25, 10 ),
	status: incomingQuery.status || DEFAULT_STATUS,
} );

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

const formatDate = ( value ) => {
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
				display: formatDate( item.first_next_payment_date_gmt ),
				value: item.first_next_payment_date_gmt,
			},
			{
				display: formatDate( item.last_next_payment_date_gmt ),
				value: item.last_next_payment_date_gmt,
			},
		];
	} );

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

const PresetButtons = ( { activePreset, onSelect } ) => (
	<ButtonGroup className="asa-upcoming-renewals__presets">
		{ presets.map( ( preset ) => (
			<Button
				key={ preset.key }
				variant={
					activePreset === preset.key ? 'primary' : 'secondary'
				}
				onClick={ () => onSelect( preset.key ) }
			>
				{ preset.label }
			</Button>
		) ) }
	</ButtonGroup>
);

const UpcomingRenewalsReport = ( { query: incomingQuery } ) => {
	const [ activePreset, setActivePreset ] = useState( 'next_friday' );
	const [ query, setQuery ] = useState( () =>
		getInitialQuery( incomingQuery )
	);
	const [ isExporting, setIsExporting ] = useState( false );
	const headers = useMemo( () => getHeaders(), [] );
	const { createNotice } = useDispatch( 'core/notices' );
	const { startExport } = useDispatch( EXPORT_STORE_NAME );
	const tableData = useSelect(
		( select ) =>
			getReportTableData( {
				endpoint: REPORT_SLUG,
				query,
				selector: select( reportsStore ),
				tableQuery: {
					status: query.status || DEFAULT_STATUS,
				},
				defaultDateRange: DEFAULT_DATE_RANGE,
			} ),
		[ query ]
	);
	const items = tableData.items || {};
	const data = useMemo( () => items.data || [], [ items.data ] );
	const rows = useMemo( () => getRows( data ), [ data ] );
	const totalRows = Number.parseInt( items.totalResults || 0, 10 );

	const onPresetSelect = ( preset ) => {
		setActivePreset( preset );
		setQuery( ( currentQuery ) => ( {
			...currentQuery,
			...getPresetQuery( preset ),
			paged: 1,
		} ) );
	};

	const onQueryChange = ( param ) => ( value, direction ) => {
		setQuery( ( currentQuery ) => {
			if ( param === 'sort' ) {
				return {
					...currentQuery,
					orderby: value,
					order: direction,
					paged: 1,
				};
			}

			if ( param === 'paged' ) {
				return {
					...currentQuery,
					paged: Number.parseInt( value, 10 ) || 1,
				};
			}

			if ( param === 'per_page' ) {
				return {
					...currentQuery,
					per_page: Number.parseInt( value, 10 ) || 25,
					paged: 1,
				};
			}

			return {
				...currentQuery,
				[ param ]: value,
				paged: 1,
			};
		} );
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
					'There was a problem exporting the upcoming renewals report. Please try again.',
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
					'The upcoming renewals report could not be loaded.',
					'additional-subscriptions-analytics'
				) }
			</Notice>
		);
	}

	return (
		<div className="asa-upcoming-renewals">
			<SyncStatusNotice />
			<PresetButtons
				activePreset={ activePreset }
				onSelect={ onPresetSelect }
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
					'No upcoming renewals found for this window.',
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
