import { showRecent, type QuickUndoConfig } from '../shared/quick-undo';

/**
 * Posts and pages list screens: "Undo" notice after Quick Edit and bulk edit.
 */
interface ListConfig extends QuickUndoConfig {
	afterBulkEdit: boolean;
}

interface JQueryLike {
	( target: Document ): {
		on: (
			event: 'ajaxSuccess',
			handler: (
				event: unknown,
				xhr: unknown,
				settings: { data?: unknown }
			) => void
		) => void;
	};
}

const RECENT_SECONDS = 120;
const config = ( window as unknown as { selectiveUndoList?: ListConfig } )
	.selectiveUndoList;
const jQuery = ( window as unknown as { jQuery?: JQueryLike } ).jQuery;

if ( config ) {
	// Quick Edit saves through admin-ajax (action=inline-save).
	jQuery?.( document ).on( 'ajaxSuccess', ( _event, _xhr, settings ) => {
		const params = new URLSearchParams(
			typeof settings.data === 'string' ? settings.data : ''
		);
		if ( params.get( 'action' ) === 'inline-save' ) {
			const postId = Number( params.get( 'post_ID' ) );
			void showRecent(
				config,
				RECENT_SECONDS,
				postId || undefined
			).catch( () => undefined );
		}
	} );

	// Bulk edit reloads the list with ?updated=N.
	if ( config.afterBulkEdit ) {
		void showRecent( config, RECENT_SECONDS ).catch( () => undefined );
	}
}
