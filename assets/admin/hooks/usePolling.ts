import { useEffect, useRef, useState } from '@wordpress/element';

interface Options< T > {
	fetcher: ( signal: AbortSignal ) => Promise< T >;
	isDone: ( value: T ) => boolean;
	intervalMs?: number;
	maxIntervalMs?: number;
	key: string;
}

/**
 * Polling with backoff: paused while the tab is hidden, stops on a terminal state,
 * cancels the request on unmount, never runs requests in parallel.
 * @param root0
 * @param root0.fetcher
 * @param root0.isDone
 * @param root0.intervalMs
 * @param root0.maxIntervalMs
 * @param root0.key
 */
export function usePolling< T >( {
	fetcher,
	isDone,
	intervalMs = 2000,
	maxIntervalMs = 15000,
	key,
}: Options< T > ): {
	data: T | null;
	error: unknown;
	refresh: () => void;
} {
	const [ state, setState ] = useState< { data: T | null; error: unknown } >(
		{ data: null, error: null }
	);
	const fetcherRef = useRef( fetcher );
	const isDoneRef = useRef( isDone );
	const refreshRef = useRef< () => void >( () => undefined );
	fetcherRef.current = fetcher;
	isDoneRef.current = isDone;

	useEffect( () => {
		let timer: ReturnType< typeof setTimeout > | undefined;
		let controller: AbortController | undefined;
		let delay = intervalMs;
		let stopped = false;
		let inFlight = false;

		const schedule = () => {
			if ( ! stopped ) {
				timer = setTimeout( tick, delay );
			}
		};

		const tick = async () => {
			if ( inFlight ) {
				return;
			}
			if ( document.visibilityState === 'hidden' ) {
				return; // resumed by visibilitychange
			}
			inFlight = true;
			controller = new AbortController();
			try {
				const value = await fetcherRef.current( controller.signal );
				setState( { data: value, error: null } );
				delay = intervalMs;
				if ( isDoneRef.current( value ) ) {
					stopped = true;
					return;
				}
			} catch ( error: unknown ) {
				if ( error instanceof Error && error.name === 'AbortError' ) {
					return;
				}
				setState( ( prev ) => ( { data: prev.data, error } ) );
				delay = Math.min( delay * 2, maxIntervalMs );
			} finally {
				inFlight = false;
			}
			schedule();
		};

		const onVisibility = () => {
			if ( document.visibilityState === 'visible' && ! stopped ) {
				clearTimeout( timer );
				void tick();
			}
		};

		refreshRef.current = () => {
			stopped = false;
			clearTimeout( timer );
			void tick();
		};

		document.addEventListener( 'visibilitychange', onVisibility );
		void tick();

		return () => {
			stopped = true;
			clearTimeout( timer );
			controller?.abort();
			document.removeEventListener( 'visibilitychange', onVisibility );
		};
	}, [ intervalMs, maxIntervalMs, key ] );

	return { ...state, refresh: () => refreshRef.current() };
}
