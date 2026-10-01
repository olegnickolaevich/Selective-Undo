import { speak } from '@wordpress/a11y';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../admin/api/client';
import type { ChangesetSummary, QuickUndoResult } from '../admin/api/types';

/**
 * "Undo" notice for classic admin screens, without React: it is shown on list
 * screens after Quick Edit and bulk edit, and by the "Undo my last change" command
 * where the block editor's snackbars are not available.
 */
export interface QuickUndoConfig {
	canRestore: boolean;
	historyUrl: string;
	restoresUrl: string;
}

export function previewUrl( config: QuickUndoConfig, planId: string ): string {
	return `${ config.historyUrl }&view=preview&id=${ encodeURIComponent(
		planId
	) }`;
}

function describe( cs: ChangesetSummary ): string {
	const fields = cs.fields.map( ( f ) => f.label ).join( ', ' );
	if ( cs.object_count === 1 && fields !== '' ) {
		return sprintf(
			/* translators: 1: changed fields, e.g. "Title", 2: post title. */
			__( '%1$s of “%2$s” changed.', 'selective-undo' ),
			fields,
			cs.objects[ 0 ]?.title ?? ''
		);
	}
	return sprintf(
		/* translators: %d: number of items. */
		_n(
			'Changes of %d item saved.',
			'Changes of %d items saved.',
			cs.object_count,
			'selective-undo'
		),
		cs.object_count
	);
}

function link( href: string, text: string ): HTMLAnchorElement {
	const a = document.createElement( 'a' );
	a.href = href;
	a.textContent = text;
	return a;
}

/**
 * Replaces the notice text with the outcome of an undo and announces it.
 * @param config Screen configuration.
 * @param text   Paragraph of the notice.
 * @param result Response of the quick undo endpoint.
 */
export function settle(
	config: QuickUndoConfig,
	text: HTMLElement,
	result: QuickUndoResult
): void {
	text.textContent = '';

	if ( result.status === 'restored' && result.job ) {
		const complete = result.job.status === 'completed';
		text.append(
			( complete
				? __( 'Undone.', 'selective-undo' )
				: __( 'Partly undone.', 'selective-undo' ) ) + ' ',
			// The screen still shows the old value until the page is reloaded.
			link(
				window.location.href,
				__( 'Reload the page', 'selective-undo' )
			),
			' · ',
			link(
				`${ config.restoresUrl }&view=job&id=${ encodeURIComponent(
					result.job.id
				) }`,
				__( 'Details', 'selective-undo' )
			)
		);
		speak(
			complete
				? __( 'The change was undone.', 'selective-undo' )
				: __( 'The change was partly undone.', 'selective-undo' )
		);
		return;
	}

	if ( result.status === 'nothing_to_undo' ) {
		text.textContent = __(
			'There is nothing left to undo: the values are already back.',
			'selective-undo'
		);
		speak( text.textContent );
		return;
	}

	text.append(
		__(
			'Some of these fields were changed again since. Review before undoing.',
			'selective-undo'
		) + ' ',
		link(
			result.plan_id
				? previewUrl( config, result.plan_id )
				: config.historyUrl,
			__( 'Review undo', 'selective-undo' )
		)
	);
	speak( text.textContent ?? '' );
}

function place( box: HTMLElement ): void {
	document.querySelector( '.su-quick-undo' )?.remove();
	box.classList.add( 'su-quick-undo' );
	const anchor =
		document.querySelector( '.wp-header-end' ) ??
		document.querySelector( '#wpbody-content > .wrap > h1' );
	if ( anchor ) {
		anchor.after( box );
	} else {
		document.querySelector( '#wpbody-content' )?.prepend( box );
	}
}

/**
 * A plain message in the same place as the "Undo" notice.
 * @param text Message.
 * @param kind Notice type.
 */
export function showMessage( text: string, kind: 'info' | 'error' ): void {
	const box = document.createElement( 'div' );
	box.className = `notice notice-${ kind }`;
	const p = document.createElement( 'p' );
	p.textContent = text;
	box.append( p );
	place( box );
	speak( text, kind === 'error' ? 'assertive' : 'polite' );
}

export function showUndoNotice(
	config: QuickUndoConfig,
	cs: ChangesetSummary
): void {
	const box = document.createElement( 'div' );
	box.className = 'notice notice-info';
	const text = document.createElement( 'p' );
	text.textContent = describe( cs ) + ' ';
	box.append( text );

	if ( config.canRestore ) {
		// One key per notice: a double click or a lost response cannot undo twice.
		const key = window.crypto.randomUUID();
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button-link';
		button.textContent = __( 'Undo', 'selective-undo' );
		button.addEventListener( 'click', async () => {
			button.disabled = true;
			try {
				settle( config, text, await api.quickUndo( cs.id, key ) );
			} catch {
				text.textContent = __(
					'Could not undo. Open the history to try again.',
					'selective-undo'
				);
				speak( text.textContent );
			}
		} );
		text.append( button );
	}

	const dismiss = document.createElement( 'button' );
	dismiss.type = 'button';
	dismiss.className = 'notice-dismiss';
	const label = document.createElement( 'span' );
	label.className = 'screen-reader-text';
	label.textContent = __( 'Dismiss this notice.', 'selective-undo' );
	dismiss.append( label );
	dismiss.addEventListener( 'click', () => box.remove() );
	box.classList.add( 'is-dismissible' );
	box.append( dismiss );

	place( box );
	speak( text.firstChild?.textContent ?? '' );
}

/**
 * Shows the notice for the current user's latest operation, if it is recent.
 * @param config   Screen configuration.
 * @param within   Age limit in seconds.
 * @param objectId Only operations that changed this post.
 * @return The operation the notice is about, or null.
 */
export async function showRecent(
	config: QuickUndoConfig,
	within: number,
	objectId?: number
): Promise< ChangesetSummary | null > {
	const page = await api.recentChangesets( within, objectId );
	const latest = page.items[ 0 ] ?? null;
	if ( latest ) {
		showUndoNotice( config, latest );
	}
	return latest;
}
