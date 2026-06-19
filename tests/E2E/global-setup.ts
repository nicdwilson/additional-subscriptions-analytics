import { chromium, type FullConfig } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const authFile = resolve( 'tests/E2E/.auth/admin.json' );

export default async function globalSetup( config: FullConfig ) {
	const baseURL =
		( config.projects[ 0 ].use.baseURL as string | undefined ) ||
		'http://localhost:8888';
	const browser = await chromium.launch();
	const page = await browser.newPage();

	await page.goto( `${ baseURL }/wp-login.php` );
	await page.getByLabel( 'Username or Email Address' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.waitForURL( /wp-admin/ );

	mkdirSync( dirname( authFile ), { recursive: true } );
	await page.context().storageState( { path: authFile } );
	await browser.close();
}
