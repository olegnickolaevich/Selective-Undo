import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const ROOT = process.cwd() + '/';

function wp( ...args: string[] ): string {
	return execFileSync( ROOT + 'bin/test-env.sh', [ 'wp', ...args ], { encoding: 'utf8' } ).trim();
}

async function login( page: Page, user = 'admin' ) {
	await page.goto( '/wp-login.php' );
	// wp-login.php focuses the username field 200 ms after load; wait for it so the
	// focus change cannot race with filling the form.
	await page.waitForFunction( () => document.activeElement?.id === 'user_login' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

async function expectNoSeriousA11yViolations( page: Page ) {
	const results = await new AxeBuilder( { page } ).include( '.su-app' ).analyze();
	const serious = results.violations.filter( ( v ) => v.impact === 'serious' || v.impact === 'critical' );
	expect( serious.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target.join( ' ' ) ).join( ', ' ) }` ) ).toEqual( [] );
}

test.describe.configure( { mode: 'serial' } );

let postId = 0;
const RUN = Math.random().toString( 36 ).slice( 2, 8 );
const TITLE = `Contacts ${ RUN }`;
const BROKEN = `Contacts ${ RUN } OLD`;

test.beforeAll( () => {
	postId = Number( wp( 'post', 'create', '--post_type=page', '--post_status=publish', `--post_title=${ TITLE }`, '--post_content=<p>Body</p>', '--porcelain' ) );
	wp( 'post', 'update', String( postId ), `--post_title=${ BROKEN }` );
} );

test( 'restore a title from the row action and undo the restore', async ( { page } ) => {
	await login( page );
	await page.goto( `/wp-admin/edit.php?post_type=page&s=${ encodeURIComponent( BROKEN ) }` );
	const row = page.locator( `#post-${ postId }` );
	await row.hover();
	await row.getByRole( 'link', { name: `Change history of “${ BROKEN }”` } ).click();

	await expect( page.getByRole( 'heading', { level: 2, name: BROKEN } ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Compare before / after' } ).first().click();
	await expect( page.locator( '.su-diff__line--delete' ).first() ).toHaveText( new RegExp( `${ TITLE }$` ) );
	await expect( page.locator( '.su-diff__line--insert' ).first() ).toHaveText( new RegExp( BROKEN ) );

	await page.getByRole( 'checkbox', { name: 'Title' } ).first().check();
	await page.getByRole( 'button', { name: 'Check restore' } ).click();

	await expect( page.getByRole( 'heading', { level: 2, name: 'Check restore' } ) ).toBeFocused();
	await expect( page.getByText( 'Ready to restore', { exact: true } ).first() ).toBeVisible();
	await expect( page.getByText( /Will not be changed:/ ) ).toBeVisible();
	await expectNoSeriousA11yViolations( page );

	await page.getByRole( 'button', { name: 'Restore 1 field' } ).click();
	await expect( page.getByRole( 'heading', { level: 2, name: 'Restore completed' } ) ).toBeVisible();
	expect( wp( 'post', 'get', String( postId ), '--field=post_title' ) ).toBe( TITLE );
	await expectNoSeriousA11yViolations( page );

	await page.getByRole( 'button', { name: 'Check undo of this restore' } ).click();
	await expect( page.getByRole( 'heading', { level: 2, name: 'Check restore' } ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Restore 1 field' } ).click();
	await expect( page.getByRole( 'heading', { level: 2, name: 'Restore completed' } ) ).toBeVisible();
	expect( wp( 'post', 'get', String( postId ), '--field=post_title' ) ).toBe( BROKEN );
} );

test( 'conflict is shown and nothing is overwritten', async ( { page } ) => {
	wp( 'post', 'update', String( postId ), '--post_title=Edited again' );
	await login( page );
	await page.goto( `/wp-admin/admin.php?page=selective-undo&view=object&id=${ postId }` );
	// The oldest title change (Contacts -> Contacts OLD) is the last Title checkbox.
	await page.getByRole( 'checkbox', { name: 'Title' } ).last().check();
	await page.getByRole( 'button', { name: 'Check restore' } ).click();
	await expect( page.getByText( 'Changed again', { exact: true } ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Restore 0 fields' } ) ).toBeDisabled();
	await page.getByRole( 'button', { name: 'What changed since' } ).click();
	await expect( page.locator( '.su-diff__line--insert' ).first() ).toHaveText( /Edited again/ );
	expect( wp( 'post', 'get', String( postId ), '--field=post_title' ) ).toBe( 'Edited again' );
} );

test( 'history list, filters and accessibility', async ( { page } ) => {
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=selective-undo' );
	await expect( page.getByRole( 'heading', { level: 1, name: 'Selective Undo' } ) ).toBeVisible();
	await expect( page.getByRole( 'table' ) ).toBeVisible();
	await expect( page.getByText( 'Recording', { exact: true } ) ).toBeVisible();
	await page.getByLabel( 'Search by current title or ID' ).fill( String( postId ) );
	await page.waitForURL( new RegExp( `[?&]s=${ postId }` ) );
	await page.getByLabel( 'Operation' ).selectOption( 'restore' );
	await expect( page.locator( '.su-table tbody tr' ) ).toHaveCount( 2 );
	await expectNoSeriousA11yViolations( page );
} );

test( 'mobile layout at 360px has no horizontal scroll', async ( { page } ) => {
	await page.setViewportSize( { width: 360, height: 800 } );
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=selective-undo' );
	await expect( page.locator( '.su-cards .su-card' ).first() ).toBeVisible();
	await expect( page.locator( '.su-table' ) ).toBeHidden();
	const overflow = await page.evaluate( () => document.documentElement.scrollWidth - window.innerWidth );
	expect( overflow ).toBeLessThanOrEqual( 0 );
} );

test( 'settings: diagnostics and access tabs', async ( { page } ) => {
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=selective-undo-settings&tab=diagnostics' );
	await expect( page.getByText( 'Restore connection' ) ).toBeVisible();
	await expect( page.getByText( 'A dedicated transactional connection to the primary database works and reaches the same database.' ) ).toBeVisible();
	await page.getByRole( 'link', { name: 'Access' } ).click();
	await expect( page.getByRole( 'table', { name: 'Permissions by role' } ) ).toBeVisible();
	await expectNoSeriousA11yViolations( page );
} );

test( 'editors without permission do not see the plugin', async ( { page } ) => {
	await login( page, 'editor' );
	const response = await page.goto( '/wp-admin/admin.php?page=selective-undo' );
	expect( response?.status() ).toBe( 403 );
	await page.goto( '/wp-admin/edit.php?post_type=page' );
	await expect( page.getByRole( 'link', { name: /Change history/ } ) ).toHaveCount( 0 );
} );
