import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';

/**
 * Objects beyond post fields, through the interface: a permanently deleted post and
 * the site settings.
 */

const ROOT = process.cwd() + '/';
const RUN = Math.random().toString( 36 ).slice( 2, 8 );

function wp( ...args: string[] ): string {
	return execFileSync( 'bash', [ ROOT + 'bin/test-env.sh', 'wp', ...args ], { encoding: 'utf8' } ).trim();
}

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.waitForFunction( () => document.activeElement?.id === 'user_login' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

async function restoreSelected( page: Page ) {
	await page.getByRole( 'button', { name: 'Check restore' } ).click();
	await page.getByRole( 'button', { name: 'Restore 1 field' } ).click();
	await expect( page.getByRole( 'heading', { level: 2, name: 'Restore completed' } ) ).toBeVisible();
}

test( 'a permanently deleted post comes back as a draft from its history', async ( { page } ) => {
	const id = wp( 'post', 'create', '--post_type=post', '--post_status=publish', `--post_title=Price list ${ RUN }`, `--post_name=price-list-${ RUN }`, '--porcelain' );
	wp( 'post', 'delete', id ); // to the trash
	wp( 'post', 'delete', id, '--force' ); // and permanently

	await login( page );
	await page.goto( `/wp-admin/admin.php?page=selective-undo&view=object&id=${ id }` );
	await page.getByRole( 'checkbox', { name: 'Deleted permanently' } ).check();
	await page.getByRole( 'button', { name: 'Check restore' } ).click();
	await expect( page.getByText( 'The item comes back as a draft under its own ID' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Restore 1 field' } ).click();
	await expect( page.getByRole( 'heading', { level: 2, name: 'Restore completed' } ) ).toBeVisible();

	expect( wp( 'post', 'get', id, '--field=post_status' ) ).toBe( 'draft' );
	expect( wp( 'post', 'get', id, '--field=post_name' ) ).toBe( `price-list-${ RUN }` );
} );

test( 'a change of the site settings is undone from the history of the settings', async ( { page } ) => {
	const before = wp( 'option', 'get', 'blogdescription' );
	wp( 'option', 'update', 'blogdescription', `Changed ${ RUN }` );

	try {
		await login( page );
		await page.goto( '/wp-admin/admin.php?page=selective-undo&view=object&id=1&type=site' );
		await expect( page.getByRole( 'heading', { name: 'Site settings' } ) ).toBeVisible();
		await page.getByRole( 'checkbox', { name: 'Tagline' } ).first().check();
		await restoreSelected( page );
		expect( wp( 'option', 'get', 'blogdescription' ) ).toBe( before );
	} finally {
		wp( 'option', 'update', 'blogdescription', before );
	}
} );
