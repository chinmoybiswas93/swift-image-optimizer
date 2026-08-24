/**
 * Browser coverage for the merged library card (Unit 12).
 *
 * The existing smoke spec never logs in - it only checks that wp-login.php
 * renders. Everything here needs an authenticated admin session, so each test
 * logs in for itself rather than relying on shared state between tests.
 * Credentials come from the environment because they are Local-install
 * secrets, not something to hardcode into a committed spec:
 *
 *   WP_ADMIN_USER=admin WP_ADMIN_PASSWORD=... npm run test:e2e
 *
 * Skips outright when they are not set, rather than failing every test with
 * the same unhelpful 403 - see the guard below.
 */

const { test, expect } = require( '@playwright/test' );

const USER = process.env.WP_ADMIN_USER;
const PASSWORD = process.env.WP_ADMIN_PASSWORD;

test.skip(
	! USER || ! PASSWORD,
	'WP_ADMIN_USER and WP_ADMIN_PASSWORD are not set - see the file header.'
);

/**
 * Log in and land on the plugin's admin screen.
 *
 * @param {import('@playwright/test').Page} page Page under test.
 */
async function loginAndOpenDashboard( page ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( USER );
	await page.locator( '#user_pass' ).fill( PASSWORD );
	await page.locator( '#wp-submit' ).click();
	await expect( page ).toHaveURL( /wp-admin/ );

	await page.goto( '/wp-admin/upload.php?page=swift-image-optimizer' );
	await expect( page.locator( '#swift-image-optimizer-root' ) ).toBeVisible();
}

test( 'the Bulk Optimize tab renders one card with a progress ring', async ( {
	page,
} ) => {
	const consoleErrors = [];
	page.on( 'console', ( msg ) => {
		if ( msg.type() === 'error' ) {
			consoleErrors.push( msg.text() );
		}
	} );

	await loginAndOpenDashboard( page );

	/*
	 * Exactly one instance. The old screen had three cards ("Your library",
	 * "Before you start", "Bulk optimization") titled separately; this unit
	 * merges them, so more than one match here would mean the merge regressed.
	 */
	await expect( page.locator( '.sio-library' ) ).toHaveCount( 1 );
	await expect( page.locator( '.sio-ring' ) ).toHaveCount( 1 );
	await expect(
		page.locator( '[role="progressbar"]' ).first()
	).toBeVisible();

	expect( consoleErrors, consoleErrors.join( '\n' ) ).toHaveLength( 0 );
} );

test( 'the ring reports a value between 0 and 100', async ( { page } ) => {
	await loginAndOpenDashboard( page );

	const ring = page.locator( '[role="progressbar"]' ).first();
	const value = await ring.getAttribute( 'aria-valuenow' );

	expect( value ).not.toBeNull();
	expect( Number( value ) ).toBeGreaterThanOrEqual( 0 );
	expect( Number( value ) ).toBeLessThanOrEqual( 100 );
} );

test( 'Scan library disables while running and re-enables on completion', async ( {
	page,
} ) => {
	await loginAndOpenDashboard( page );

	const scanButton = page.getByRole( 'button', { name: 'Scan library' } );
	await expect( scanButton ).toBeVisible();

	const timeBefore = await page
		.locator( '.sio-scanmeta__time' )
		.textContent()
		.catch( () => '' );

	await scanButton.click();

	/*
	 * The button becomes unavailable the instant a scan starts - either
	 * disabled, or briefly replaced by its own spinner while the first
	 * response is in flight. Either is acceptable; a scan that never blocks
	 * the button at all would mean the request never registered as busy.
	 */
	await expect( scanButton ).toBeDisabled( { timeout: 5000 } );

	// A library of any real size finishes in well under this on a Local site;
	// the batch ceiling exists precisely so this does not hang.
	await expect( scanButton ).toBeEnabled( { timeout: 60000 } );

	await expect( page.locator( '.sio-scanmeta__time' ) ).toContainText(
		/Last scanned|just now/i
	);

	const timeAfter = await page.locator( '.sio-scanmeta__time' ).textContent();
	expect( timeAfter ).not.toBe( timeBefore );
} );

test( 'the Monthly scan frequency persists across a reload', async ( {
	page,
} ) => {
	await loginAndOpenDashboard( page );

	await page.getByRole( 'tab', { name: 'Settings' } ).click();

	const frequency = page.getByLabel( 'Scan the library automatically' );
	await expect( frequency ).toBeVisible();
	await frequency.selectOption( 'monthly' );

	await page.getByRole( 'button', { name: 'Save settings' } ).click();
	await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();

	await page.reload();
	await page.getByRole( 'tab', { name: 'Settings' } ).click();

	await expect(
		page.getByLabel( 'Scan the library automatically' )
	).toHaveValue( 'monthly' );
} );
