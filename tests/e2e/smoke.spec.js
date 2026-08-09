const { test, expect } = require( '@playwright/test' );

test( 'wp-login page loads', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await expect( page.locator( '#loginform' ) ).toBeVisible();
} );
