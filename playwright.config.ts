import { defineConfig } from '@playwright/test';

export default defineConfig( {
	testDir: './tests/E2E',
	timeout: 60000,
	retries: 1,
	use: {
		baseURL: 'http://localhost:8889',
		storageState: './tests/E2E/.auth/admin.json',
		screenshot: 'only-on-failure',
		trace: 'on-first-retry',
	},
} );
