import apiFetch from '@wordpress/api-fetch';
import { select, subscribe } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';

/** Request header that ties together the requests of one click on Save. */
const HEADER = 'X-Selective-Undo-Save';

/** Requests that follow the end of a save still belong to it (meta boxes, plugin fields). */
const TAIL_MS = 3000;

const WRITE_METHODS = [ 'POST', 'PUT', 'PATCH', 'DELETE' ];

let token: string | null = null;
let saving = false;
let endedAt = 0;

function uuid(): string {
	if ( typeof crypto !== 'undefined' && 'randomUUID' in crypto ) {
		return crypto.randomUUID();
	}

	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, ( c ) => {
		const r = Math.floor( Math.random() * 16 );
		return ( c === 'x' ? r : ( r % 4 ) + 8 ).toString( 16 );
	} );
}

function metaBoxesSaving(): boolean {
	const editPost = select( 'core/edit-post' ) as
		{ isSavingMetaBoxes?: () => boolean } | undefined;
	return editPost?.isSavingMetaBoxes?.() ?? false;
}

/**
 * Follows the editor's save state. Called from the store subscription and from
 * the request middleware, because other plugins may send their requests from
 * their own subscriptions before ours runs.
 */
function track(): void {
	const editor = select( editorStore );
	const now = editor.isSavingPost() && ! editor.isAutosavingPost();

	if ( now && ! saving ) {
		token = uuid();
	} else if ( ! now && saving ) {
		endedAt = Date.now();
	}

	saving = now;
}

function currentToken(): string | null {
	track();

	if (
		token !== null &&
		( saving || metaBoxesSaving() || Date.now() - endedAt < TAIL_MS )
	) {
		return token;
	}

	return null;
}

/**
 * One click on Save sends several requests: the post itself (REST), fields of
 * plugins that save through their own endpoints, and the classic meta boxes.
 * They all carry the same token so that the history shows them as one change.
 */
export function registerSaveToken(): void {
	subscribe( track, editorStore );

	apiFetch.use( ( options, next ) => {
		const method = ( options.method ?? 'GET' ).toUpperCase();
		const current = WRITE_METHODS.includes( method )
			? currentToken()
			: null;

		if ( current === null ) {
			return next( options );
		}

		return next( {
			...options,
			headers: { ...options.headers, [ HEADER ]: current },
		} );
	} );
}
