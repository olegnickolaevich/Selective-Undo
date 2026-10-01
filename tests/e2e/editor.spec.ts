import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';

const ROOT = process.cwd() + '/';
const SITE = process.env.SU_TEST_SITE ?? ROOT + '.work/site';
const MU_PLUGIN = SITE + '/wp-content/mu-plugins/su-e2e-meta-box.php';

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

// A classic meta box like those of ACF and SEO plugins, saved by the block editor
// in a second request after the REST request that saves the post.
test.beforeAll( () => {
	mkdirSync( SITE + '/wp-content/mu-plugins', { recursive: true } );
	writeFileSync(
		MU_PLUGIN,
		`<?php
add_action( 'add_meta_boxes', function () {
	add_meta_box( 'su_e2e_box', 'E2E box', function ( $post ) {
		echo '<input name="su_e2e_field" id="su-e2e-field" value="' . esc_attr( get_post_meta( $post->ID, 'su_e2e_field', true ) ) . '">';
	}, 'post' );
} );
add_action( 'save_post_post', function ( $id ) {
	if ( isset( $_POST['su_e2e_field'] ) ) {
		update_post_meta( $id, 'su_e2e_field', sanitize_text_field( wp_unslash( $_POST['su_e2e_field'] ) ) );
	}
} );
add_filter( 'selective_undo/meta_fields', function ( $fields, $type ) {
	if ( 'post' === $type ) {
		$fields[] = new SelectiveUndo\\Domain\\Contracts\\FieldDefinition( 'meta:su_e2e_field', 'E2E field', 'text' );
	}
	return $fields;
}, 10, 2 );
`
	);
} );

test.afterAll( () => rmSync( MU_PLUGIN, { force: true } ) );

test( 'one Save in the block editor, meta boxes included, is one change and is undone as one', async ( { page } ) => {
	const run = Math.random().toString( 36 ).slice( 2, 8 );
	// With an author: the classic form of the meta boxes sets one when a post has none.
	const id = wp( 'post', 'create', '--post_type=post', '--post_status=publish', '--post_author=1', `--post_title=Box ${ run }`, '--porcelain' );
	wp( 'post', 'meta', 'update', id, 'su_e2e_field', 'first' );

	await login( page );
	await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
	await page.waitForSelector( '.editor-header' );
	await page.evaluate( () => ( window as any ).wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false ) );
	await page.frameLocator( 'iframe[name="editor-canvas"]' ).locator( '.editor-post-title__input' ).fill( `Box ${ run } edited` );
	const boxes = page.getByRole( 'button', { name: 'Meta Boxes' } );
	if ( ( await boxes.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await boxes.focus();
		await page.keyboard.press( 'Enter' );
	}
	await page.locator( '#su-e2e-field' ).fill( 'second' );
	await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await expect( page.locator( '.components-snackbar' ) ).toContainText( 'Post updated' );
	// The meta box request follows the REST request.
	await expect.poll( () => wp( 'post', 'meta', 'get', id, 'su_e2e_field' ) ).toBe( 'second' );

	await page.goto( `/wp-admin/admin.php?page=selective-undo&view=object&id=${ id }` );
	await page.getByRole( 'link', { name: 'Whole operation' } ).first().click();
	const fields = page.locator( '.su-app, #wpbody-content' ).first();
	await expect( fields ).toContainText( 'Title' );
	await expect( fields ).toContainText( 'E2E field' );
	await expect( page.getByRole( 'checkbox' ) ).toHaveCount( 2 );

	for ( const box of await page.getByRole( 'checkbox' ).all() ) {
		await box.check();
	}
	await page.getByRole( 'button', { name: 'Check restore' } ).click();
	await page.getByRole( 'button', { name: 'Restore 2 fields' } ).click();
	await expect( page.locator( '#wpbody-content' ) ).toContainText( 'Restore completed' );

	expect( wp( 'post', 'get', id, '--field=post_title' ) ).toBe( `Box ${ run }` );
	expect( wp( 'post', 'meta', 'get', id, 'su_e2e_field' ) ).toBe( 'first' );
} );
