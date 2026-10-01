import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

const ROOT = process.cwd() + '/';

function wp( ...args: string[] ): string {
	return execFileSync( ROOT + 'bin/test-env.sh', [ 'wp', ...args ], { encoding: 'utf8' } ).trim();
}

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.waitForFunction( () => document.activeElement?.id === 'user_login' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

const RUN = Math.random().toString( 36 ).slice( 2, 8 );

test( 'undo a Quick Edit from the notice on the list screen', async ( { page } ) => {
	const title = `Prices ${ RUN }`;
	const id = wp( 'post', 'create', '--post_type=page', '--post_status=publish', `--post_title=${ title }`, '--porcelain' );

	await login( page );
	await page.goto( `/wp-admin/edit.php?post_type=page&s=${ encodeURIComponent( title ) }` );
	const row = page.locator( `#post-${ id }` );
	await row.hover();
	await row.getByRole( 'button', { name: /Quick edit/ } ).click();
	const editor = page.locator( `#edit-${ id }` );
	await editor.locator( 'input[name="post_title"]' ).fill( `${ title } typo` );
	await editor.getByRole( 'button', { name: 'Update' } ).click();

	const notice = page.locator( '.su-quick-undo' );
	await expect( notice ).toContainText( `Title of “${ title } typo” changed.` );
	await notice.getByRole( 'button', { name: 'Undo' } ).click();
	await expect( notice ).toContainText( 'Undone.' );

	await notice.getByRole( 'link', { name: 'Reload the page' } ).click();
	await expect( page.locator( `#post-${ id } .row-title` ) ).toHaveText( title );
	// The last change is now the undo itself.
	await expect( page.locator( `#post-${ id } .column-selective_undo_last_change` ) ).toContainText( /admin · .* ago · Title/ );
} );

test( 'outline the block changed by an edit, only for users with access', async ( { page, browser } ) => {
	const paragraph = ( text: string ) => `<!-- wp:paragraph -->\n<p>${ text }</p>\n<!-- /wp:paragraph -->`;
	const content = ( second: string ) => [ paragraph( 'First' ), paragraph( second ), paragraph( 'Third' ) ].join( '\n\n' );
	const id = wp( 'post', 'create', '--post_type=page', '--post_status=publish', `--post_title=Blocks ${ RUN }`, `--post_content=${ content( 'Second' ) }`, '--porcelain' );
	wp( 'post', 'update', id, `--post_content=${ content( 'Second, edited' ) }` );

	await login( page );
	await page.goto( `/?page_id=${ id }` );
	const show = page.locator( '#wp-admin-bar-selective-undo-show a' );
	const url = String( await show.getAttribute( 'href' ) );
	const response = await page.goto( url );
	expect( response?.headers()[ 'cache-control' ] ?? '' ).toContain( 'no-cache' );

	const outlined = page.locator( '.su-changed' );
	await expect( outlined ).toHaveCount( 1 );
	await expect( outlined ).toHaveText( 'Second, edited' );
	await expect( page.locator( '.su-changes-banner' ) ).toContainText( '1 block changed by this edit is outlined.' );

	const anonymous = await browser.newPage();
	await anonymous.goto( url );
	await expect( anonymous.getByText( 'Second, edited' ) ).toBeVisible();
	await expect( anonymous.locator( '.su-changed, .su-changes-banner' ) ).toHaveCount( 0 );
	await anonymous.close();
} );
