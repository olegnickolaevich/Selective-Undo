import { useEffect, useState } from '@wordpress/element';
import { config } from './config';

/**
 * Minimal URL-state router. Views live in query arguments of the admin page, so
 * the browser history, reloads and shared links work.
 */
export type View =
	| { name: 'history' }
	| { name: 'changeset'; id: string }
	| { name: 'object'; id: number }
	| { name: 'preview'; id: string }
	| { name: 'restores' }
	| { name: 'job'; id: string }
	| { name: 'settings'; tab: string };

type TopPage = keyof typeof config.pages;

function pageOf( view: View ): TopPage {
	switch ( view.name ) {
		case 'restores':
		case 'job':
			return 'restores';
		case 'settings':
			return 'settings';
		default:
			return 'history';
	}
}

export function viewToUrl( view: View ): string {
	const params = new URLSearchParams( {
		page: config.pages[ pageOf( view ) ],
	} );

	if ( view.name !== 'history' && view.name !== 'restores' ) {
		params.set( 'view', view.name );
	}
	if ( 'id' in view ) {
		params.set( 'id', String( view.id ) );
	}
	if ( view.name === 'settings' && view.tab !== 'recording' ) {
		params.set( 'tab', view.tab );
	}

	return `${ config.adminUrl }admin.php?${ params.toString() }`;
}

export function urlToView( search: string ): View {
	const params = new URLSearchParams( search );
	const page = params.get( 'page' );

	if ( page === config.pages.settings ) {
		return { name: 'settings', tab: params.get( 'tab' ) ?? 'recording' };
	}

	const view = params.get( 'view' );
	const id = params.get( 'id' ) ?? '';
	if ( view === 'job' && id ) {
		return { name: 'job', id };
	}
	if ( page === config.pages.restores ) {
		return { name: 'restores' };
	}
	if ( view === 'changeset' && id ) {
		return { name: 'changeset', id };
	}
	if ( view === 'object' && /^\d+$/.test( id ) ) {
		return { name: 'object', id: Number( id ) };
	}
	if ( view === 'preview' && id ) {
		return { name: 'preview', id };
	}

	return { name: 'history' };
}

const listeners = new Set< () => void >();

export function navigate( view: View, replace = false ): void {
	const url = viewToUrl( view );
	const samePage =
		new URL( url, window.location.href ).searchParams.get( 'page' ) ===
		new URLSearchParams( window.location.search ).get( 'page' );

	if ( ! samePage ) {
		// Different admin page: a full load keeps the WordPress menu highlight correct.
		window.location.assign( url );
		return;
	}

	window.history[ replace ? 'replaceState' : 'pushState' ]( null, '', url );
	listeners.forEach( ( l ) => l() );
}

export function useView(): View {
	const [ view, setView ] = useState< View >( () =>
		urlToView( window.location.search )
	);

	useEffect( () => {
		const update = () => setView( urlToView( window.location.search ) );
		listeners.add( update );
		window.addEventListener( 'popstate', update );
		return () => {
			listeners.delete( update );
			window.removeEventListener( 'popstate', update );
		};
	}, [] );

	return view;
}

/**
 * Click handler for links that should navigate inside the app.
 * @param view
 */
export function linkHandler( view: View ) {
	return ( event: {
		preventDefault: () => void;
		metaKey: boolean;
		ctrlKey: boolean;
		shiftKey: boolean;
		button: number;
	} ) => {
		if (
			event.button !== 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey
		) {
			return;
		}
		event.preventDefault();
		navigate( view );
	};
}
