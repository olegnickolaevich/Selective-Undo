import { defineConfig } from '@playwright/test';

/**
 * E2E tests run against the disposable site from bin/test-env.sh, served by
 * PHP's built-in web server (bin/serve.sh).
 */
export default defineConfig( {
	testDir: 'tests/e2e',
	timeout: 60_000,
	// The first admin page of a run loads React and several REST requests on a cold
	// server; assertions still have to come true, they may just take longer on CI.
	expect: { timeout: 15_000 },
	retries: 0,
	workers: 1,
	// In CI failures also become annotations of the run, readable without the logs.
	reporter: process.env.CI ? [ [ 'list' ], [ 'github' ] ] : [ [ 'list' ] ],
	use: {
		baseURL: process.env.SU_E2E_URL ?? 'http://127.0.0.1:8899',
		launchOptions: process.env.SU_CHROMIUM ? { executablePath: process.env.SU_CHROMIUM } : {},
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	outputDir: '.work/e2e-results',
} );
