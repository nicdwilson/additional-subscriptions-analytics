import { defineConfig } from '@playwright/test';

export default defineConfig( {
	testDir: './tests/E2E',
	timeout: 60000,
	retries: 1,
	globalSetup: './tests/E2E/global-setup.ts',
	use: {
		baseURL: 'http://localhost:8888',
		storageState: './tests/E2E/.auth/admin.json',
		screenshot: 'only-on-failure',
		trace: 'on-first-retry',
	},
} );
