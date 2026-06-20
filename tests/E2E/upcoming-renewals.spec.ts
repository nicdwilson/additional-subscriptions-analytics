import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';

test.describe.configure( { mode: 'serial' } );

const hasBuiltWooAdminAssets = () => {
	const wpEnv = JSON.parse(
		readFileSync( path.join( process.cwd(), '.wp-env.json' ), 'utf8' )
	);
	const wooPlugin = ( wpEnv.plugins || [] ).find(
		( plugin: string ) =>
			typeof plugin === 'string' && plugin.endsWith( '/plugins/woocommerce' )
	);

	if ( ! wooPlugin ) {
		return true;
	}

	const wooPluginPath = path.isAbsolute( wooPlugin )
		? wooPlugin
		: path.resolve( process.cwd(), wooPlugin );

	return existsSync(
		path.join(
			wooPluginPath,
			'assets/client/admin/wp-admin-scripts/command-palette.asset.php'
		)
	);
};

test.skip(
	! hasBuiltWooAdminAssets(),
	'WooCommerce Admin built assets are required for this E2E suite.'
);

const runWpCli = ( args: string[] ) => {
	execFileSync(
		'npx',
		[
			'wp-env',
			'run',
			'--env-cwd=wp-content/plugins/additional-subscriptions-analytics',
			'cli',
			'wp',
			...args,
		],
		{
			cwd: process.cwd(),
			stdio: 'inherit',
		}
	);
};

test.beforeAll( () => {
	runWpCli( [ 'eval-file', 'tests/E2E/fixtures/seed-phase8.php' ] );
} );

test( 'merchant can use the upcoming renewals report', async ( { page } ) => {
	await page.goto(
		'/wp-admin/admin.php?page=wc-admin&path=%2Fanalytics%2Fupcoming-renewals'
	);

	await expect(
		page.getByRole( 'heading', { name: 'Upcoming renewals' } )
	).toBeVisible( { timeout: 30000 } );
	await expect(
		page.getByText( 'Subscription analytics data is ready.' )
	).toBeVisible();
	await expect( page.getByText( 'Date range' ) ).toBeVisible();
	await expect( page.getByText( 'Show:' ) ).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'All upcoming renewals' } )
	).toBeVisible();
	await expect( page.getByText( 'Renewals' ).first() ).toBeVisible();
	await expect( page.getByText( 'Phase 8 Coffee' ) ).toBeVisible();
	await expect( page.getByText( 'Phase 8 Cocoa' ) ).toBeVisible();

	await page
		.getByRole( 'button', { name: 'All upcoming renewals' } )
		.click();
	await page.getByRole( 'button', { name: 'Advanced filters' } ).click();
	await expect( page.getByText( 'Upcoming renewals match' ) ).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Add a filter' } )
	).toBeVisible();

	await page
		.getByRole( 'columnheader', { name: /Renewal quantity/ } )
		.click();

	const downloadPromise = page.waitForEvent( 'download' );
	await page.getByRole( 'button', { name: 'Download CSV' } ).click();
	const download = await downloadPromise;

	expect( download.suggestedFilename() ).toContain( 'Upcoming renewals' );
} );

test( 'merchant sees sync status notices on the report', async ( { page } ) => {
	runWpCli( [
		'option',
		'update',
		'asa_backfill_status',
		'failed',
	] );
	runWpCli( [
		'option',
		'update',
		'asa_backfill_failure',
		'Phase 8 failure',
	] );

	await page.goto(
		'/wp-admin/admin.php?page=wc-admin&path=%2Fanalytics%2Fupcoming-renewals'
	);

	await expect(
		page.getByText( 'Subscription analytics backfill failed: Phase 8 failure' )
	).toBeVisible( { timeout: 30000 } );
} );
