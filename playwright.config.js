const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	fullyParallel: true,
	retries: 0,
	reporter: 'list',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://cb-test.local',
		trace: 'on-first-retry',
	},
	projects: [
		{
			name: 'chromium',
			use: { browserName: 'chromium' },
		},
	],
} );
