import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

export interface AsyncState< T > {
	data: T | null;
	error: unknown;
	loading: boolean;
	reload: () => void;
}

/**
 * Loads data with cancellation on unmount or dependency change.
 * @param loader
 * @param deps
 */
export function useAsync< T >(
	loader: ( signal: AbortSignal ) => Promise< T >,
	deps: unknown[]
): AsyncState< T > {
	const [ state, setState ] = useState< {
		data: T | null;
		error: unknown;
		loading: boolean;
	} >( { data: null, error: null, loading: true } );
	const [ tick, setTick ] = useState( 0 );
	const loaderRef = useRef( loader );
	loaderRef.current = loader;

	useEffect( () => {
		const controller = new AbortController();
		setState( ( prev ) => ( {
			data: prev.data,
			error: null,
			loading: true,
		} ) );
		loaderRef
			.current( controller.signal )
			.then( ( data ) => {
				if ( ! controller.signal.aborted ) {
					setState( { data, error: null, loading: false } );
				}
			} )
			.catch( ( error: unknown ) => {
				if (
					! controller.signal.aborted &&
					! ( error instanceof Error && error.name === 'AbortError' )
				) {
					setState( { data: null, error, loading: false } );
				}
			} );
		return () => controller.abort();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ ...deps, tick ] );

	const reload = useCallback( () => setTick( ( t ) => t + 1 ), [] );

	return { ...state, reload };
}
