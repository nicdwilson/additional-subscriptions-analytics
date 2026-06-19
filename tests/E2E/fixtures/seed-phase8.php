<?php
/**
 * Seed Phase 8 E2E lookup-table data.
 *
 * @package AdditionalSubscriptionsAnalytics\Tests
 */

use AdditionalSubscriptionsAnalytics\Data\SubscriptionAnalyticsRepository;
use AdditionalSubscriptionsAnalytics\Database\Installer;
use AdditionalSubscriptionsAnalytics\Database\Migrator;
use AdditionalSubscriptionsAnalytics\Sync\BackfillScheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( SubscriptionAnalyticsRepository::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/additional-subscriptions-analytics.php';
}

$repository = new SubscriptionAnalyticsRepository();
$today      = new DateTimeImmutable( 'today', new DateTimeZone( 'UTC' ) );
$friday     = $today->modify( 'next friday' );
$now        = gmdate( 'Y-m-d H:i:s' );

( new Installer() )->install();
$repository->truncate_tables();

$rows = array(
	array(
		'subscription_id' => 880001,
		'date'            => $friday,
		'product_id'      => 188001,
		'product_name'    => 'Phase 8 Coffee',
		'quantity'        => '3.00000000',
		'total'           => '45.00000000',
	),
	array(
		'subscription_id' => 880002,
		'date'            => $today->modify( '+10 days' ),
		'product_id'      => 188002,
		'product_name'    => 'Phase 8 Tea',
		'quantity'        => '1.00000000',
		'total'           => '18.00000000',
	),
	array(
		'subscription_id' => 880003,
		'date'            => $today->modify( '+20 days' ),
		'product_id'      => 188003,
		'product_name'    => 'Phase 8 Cocoa',
		'quantity'        => '2.00000000',
		'total'           => '28.00000000',
	),
);

foreach ( $rows as $row ) {
	$next_payment_date_gmt = $row['date']->format( 'Y-m-d 12:00:00' );

	$repository->upsert_subscription_stats(
		array(
			'subscription_id'          => $row['subscription_id'],
			'parent_order_id'          => 0,
			'customer_id'              => 1,
			'status'                   => 'active',
			'date_created_gmt'         => $now,
			'date_updated_gmt'         => $now,
			'start_date_gmt'           => $now,
			'trial_end_date_gmt'       => null,
			'last_payment_date_gmt'    => null,
			'next_payment_date_gmt'    => $next_payment_date_gmt,
			'end_date_gmt'             => null,
			'billing_period'           => 'month',
			'billing_interval'         => 1,
			'recurring_total'          => $row['total'],
			'recurring_tax_total'      => '0.00000000',
			'recurring_shipping_total' => '0.00000000',
			'currency'                 => 'USD',
			'payment_method'           => 'manual',
			'synced_at_gmt'            => $now,
		)
	);
	$repository->replace_product_lookup_rows(
		$row['subscription_id'],
		array(
			array(
				'subscription_id' => $row['subscription_id'],
				'line_item_id'    => $row['subscription_id'],
				'product_id'      => $row['product_id'],
				'variation_id'    => 0,
				'product_name'    => $row['product_name'],
				'product_qty'     => $row['quantity'],
				'line_subtotal'   => $row['total'],
				'line_total'      => $row['total'],
				'line_tax'        => '0.00000000',
				'synced_at_gmt'   => $now,
			),
		)
	);
}

update_option( Migrator::OPTION_BACKFILL_STATUS, BackfillScheduler::STATUS_COMPLETED );
update_option( Migrator::OPTION_BACKFILL_STARTED_AT_GMT, $now );
update_option( Migrator::OPTION_BACKFILL_COMPLETED_AT_GMT, $now );
update_option( Migrator::OPTION_LAST_SYNC_AT_GMT, $now );
delete_option( BackfillScheduler::OPTION_BACKFILL_FAILURE );
update_option( BackfillScheduler::OPTION_BACKFILL_LAST_PAGE, '1' );
