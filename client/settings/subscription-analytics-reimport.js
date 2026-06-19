/**
 * Subscription analytics reimport controls.
 */

/**
 * External dependencies
 */
/* eslint-disable no-alert, import/no-unresolved, import/no-extraneous-dependencies -- WooCommerce dependency extraction externalizes WordPress packages. */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';

const ENDPOINT = '/wc-analytics/subscription-analytics/backfill';

const STATUS_LABELS = {
	not_started: __( 'Not started', 'additional-subscriptions-analytics' ),
	queued: __( 'Queued', 'additional-subscriptions-analytics' ),
	running: __( 'Running', 'additional-subscriptions-analytics' ),
	completed: __( 'Complete', 'additional-subscriptions-analytics' ),
	failed: __( 'Failed', 'additional-subscriptions-analytics' ),
};

const getStatusLabel = ( status ) =>
	STATUS_LABELS[ status ] ||
	status ||
	__( 'Unknown', 'additional-subscriptions-analytics' );

const getTimestamp = ( value ) =>
	value || __( 'Never', 'additional-subscriptions-analytics' );

const getErrorMessage = ( error ) =>
	error?.message ||
	__(
		'Subscription analytics backfill request failed.',
		'additional-subscriptions-analytics'
	);

const SubscriptionAnalyticsReimport = ( { createNotice } ) => {
	const [ payload, setPayload ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ errorMessage, setErrorMessage ] = useState( '' );

	const fetchStatus = useCallback( async () => {
		try {
			const response = await apiFetch( { path: ENDPOINT } );
			setPayload( response );
			setErrorMessage( '' );
		} catch ( error ) {
			setErrorMessage( getErrorMessage( error ) );
		} finally {
			setIsLoading( false );
		}
	}, [] );

	useEffect( () => {
		fetchStatus();
	}, [ fetchStatus ] );

	useEffect( () => {
		if ( ! payload?.isActive ) {
			return undefined;
		}

		const interval = setInterval( fetchStatus, 5000 );
		return () => clearInterval( interval );
	}, [ fetchStatus, payload?.isActive ] );

	const scheduleBackfill = async ( mode ) => {
		setIsSubmitting( true );
		setErrorMessage( '' );

		try {
			const response = await apiFetch( {
				path: ENDPOINT,
				method: 'POST',
				data: { mode },
			} );
			setPayload( response );

			if ( response.message && createNotice ) {
				createNotice( 'success', response.message );
			}
		} catch ( error ) {
			const message = getErrorMessage( error );
			setErrorMessage( message );

			if ( createNotice ) {
				createNotice( 'error', message );
			}
		} finally {
			setIsSubmitting( false );
		}
	};

	const scheduleReplacement = () => {
		// eslint-disable-next-line no-alert
		if (
			window.confirm(
				__(
					'Remove existing subscription analytics data and rebuild it from subscriptions?',
					'additional-subscriptions-analytics'
				)
			)
		) {
			scheduleBackfill( 'replace' );
		}
	};

	if ( isLoading ) {
		return <Spinner />;
	}

	const status = payload?.status || {};
	const backfillStatus = status.backfillStatus || 'not_started';
	const isActive = Boolean( payload?.isActive || isSubmitting );
	const failed = status.state === 'failed';

	return (
		<div className="asa-subscription-analytics-reimport">
			{ errorMessage && (
				<Notice status="error" isDismissible={ false }>
					{ errorMessage }
				</Notice>
			) }
			{ failed && status.failureMessage && (
				<Notice status="error" isDismissible={ false }>
					{ status.failureMessage }
				</Notice>
			) }
			<p>
				{ sprintf(
					/* translators: %s: current backfill status. */
					__( 'Status: %s', 'additional-subscriptions-analytics' ),
					getStatusLabel( backfillStatus )
				) }
			</p>
			<p>
				{ sprintf(
					/* translators: 1: start timestamp, 2: completion timestamp. */
					__(
						'Started: %1$s. Completed: %2$s.',
						'additional-subscriptions-analytics'
					),
					getTimestamp( status.startedAtGmt ),
					getTimestamp( status.completedAtGmt )
				) }
			</p>
			<p>
				{ sprintf(
					/* translators: %d: last processed source subscription page. */
					__(
						'Last processed page: %d',
						'additional-subscriptions-analytics'
					),
					status.lastPage || 0
				) }
			</p>
			<div className="woocommerce-settings__actions">
				<Button
					variant="secondary"
					disabled={ isActive }
					isBusy={ isSubmitting }
					onClick={ () => scheduleBackfill( 'backfill' ) }
				>
					{ __(
						'Backfill missing data',
						'additional-subscriptions-analytics'
					) }
				</Button>
				<Button
					variant="secondary"
					isDestructive
					disabled={ isActive }
					isBusy={ isSubmitting }
					onClick={ scheduleReplacement }
				>
					{ __(
						'Delete and rebuild data',
						'additional-subscriptions-analytics'
					) }
				</Button>
			</div>
		</div>
	);
};

export default SubscriptionAnalyticsReimport;
