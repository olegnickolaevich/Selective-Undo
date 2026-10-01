import { store as commandsStore } from '@wordpress/commands';
import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { backup, undo } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';
import { api } from '../admin/api/client';
import {
	previewUrl,
	showMessage,
	showUndoNotice,
	type QuickUndoConfig,
} from '../shared/quick-undo';

/**
 * Commands in the WordPress command palette (Ctrl+K / ⌘K).
 */
const LAST_CHANGE_SECONDS = 600;
const config = (
	window as unknown as { selectiveUndoCommands?: QuickUndoConfig }
 ).selectiveUndoCommands;

// The block and site editors show snackbars; classic screens get a notice under the title.
const hasSnackbars = () =>
	document.body.classList.contains( 'block-editor-page' ) ||
	document.body.classList.contains( 'site-editor-php' );

function message( text: string, kind: 'info' | 'success' | 'error' ): void {
	if ( ! hasSnackbars() ) {
		showMessage( text, kind === 'error' ? 'error' : 'info' );
		return;
	}
	const notices = dispatch( noticesStore );
	const create = {
		info: notices.createInfoNotice,
		success: notices.createSuccessNotice,
		error: notices.createErrorNotice,
	}[ kind ];
	create( text, { type: 'snackbar' } );
}

async function undoMyLastChange( settings: QuickUndoConfig ): Promise< void > {
	const page = await api.recentChangesets( LAST_CHANGE_SECONDS );
	const latest = page.items[ 0 ];

	if ( ! latest ) {
		message(
			__(
				'You made no changes in the last 10 minutes.',
				'selective-undo'
			),
			'info'
		);
		return;
	}

	if ( ! hasSnackbars() ) {
		// The notice explains what will be undone and asks for the click.
		showUndoNotice( settings, latest );
		return;
	}

	const result = await api.quickUndo( latest.id, window.crypto.randomUUID() );
	if ( result.status === 'restored' ) {
		message(
			__(
				'Your last change was undone. Reload the page to see it.',
				'selective-undo'
			),
			'success'
		);
	} else if ( result.status === 'nothing_to_undo' ) {
		message(
			__( 'There is nothing left to undo.', 'selective-undo' ),
			'info'
		);
	} else if ( result.plan_id ) {
		document.location.href = previewUrl( settings, result.plan_id );
	}
}

if ( config ) {
	const { registerCommand } = dispatch( commandsStore );

	registerCommand( {
		name: 'selective-undo/history',
		label: __( 'Selective Undo: change history', 'selective-undo' ),
		icon: backup,
		callback: () => {
			document.location.href = config.historyUrl;
		},
	} );

	registerCommand( {
		name: 'selective-undo/restores',
		label: __( 'Selective Undo: restores', 'selective-undo' ),
		icon: backup,
		callback: () => {
			document.location.href = config.restoresUrl;
		},
	} );

	if ( config.canRestore ) {
		registerCommand( {
			name: 'selective-undo/undo-my-last-change',
			label: __( 'Undo my last change', 'selective-undo' ),
			icon: undo,
			callback: ( { close }: { close: () => void } ) => {
				close();
				undoMyLastChange( config ).catch( () => {
					message(
						__(
							'Could not undo. Open the history to try again.',
							'selective-undo'
						),
						'error'
					);
				} );
			},
		} );
	}
}
