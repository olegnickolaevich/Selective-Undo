import { Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import type { DiffChunk, DiffLine } from '../api/types';
import { ErrorNotice, Loading } from './Feedback';

/**
 * Historical content is untrusted data. Only text nodes are rendered: no
 * dangerouslySetInnerHTML, no previews, no blocks or shortcodes executed.
 * @param root0
 * @param root0.line
 */
function Line( { line }: { line: DiffLine } ) {
	if ( line.op === 'collapsed' ) {
		const count = line.count ?? 0;
		return (
			<li className="su-diff__line su-diff__line--collapsed">
				{ sprintf(
					/* translators: %d: number of unchanged lines hidden from the comparison. */
					_n(
						'%d unchanged line hidden',
						'%d unchanged lines hidden',
						count,
						'selective-undo'
					),
					count
				) }
			</li>
		);
	}

	const marker = { equal: ' ', insert: '+', delete: '−' }[ line.op ];
	const label = {
		equal: __( 'Unchanged:', 'selective-undo' ),
		insert: __( 'Added:', 'selective-undo' ),
		delete: __( 'Removed:', 'selective-undo' ),
	}[ line.op ];

	return (
		<li className={ `su-diff__line su-diff__line--${ line.op }` }>
			<span className="su-diff__marker" aria-hidden="true">
				{ marker }
			</span>
			<span className="screen-reader-text">{ label } </span>
			<span className="su-diff__text">
				{ line.text === '' ? ' ' : line.text }
			</span>
		</li>
	);
}

export function DiffViewer( {
	chunk,
	onLoadMore,
	loadingMore,
}: {
	chunk: DiffChunk;
	onLoadMore?: () => void;
	loadingMore?: boolean;
} ) {
	if ( chunk.unavailable ) {
		return (
			<p className="su-muted">
				{ __(
					'The stored values are not available for comparison.',
					'selective-undo'
				) }
			</p>
		);
	}
	if ( chunk.binary ) {
		return (
			<p className="su-muted">
				{ __(
					'This value contains binary data and cannot be shown as text.',
					'selective-undo'
				) }
			</p>
		);
	}

	return (
		<figure className="su-diff">
			<figcaption className="su-diff__legend">
				<span className="su-diff__legend-item su-diff__legend-item--delete">
					− { chunk.left_label }
				</span>
				<span className="su-diff__legend-item su-diff__legend-item--insert">
					+ { chunk.right_label }
				</span>
			</figcaption>
			{ chunk.lines.length === 0 ? (
				<p className="su-muted">
					{ __( 'Both values are empty.', 'selective-undo' ) }
				</p>
			) : (
				<ol className="su-diff__lines">
					{ chunk.lines.map( ( line, index ) => (
						<Line key={ index } line={ line } />
					) ) }
				</ol>
			) }
			{ chunk.next_offset !== null && onLoadMore && (
				<Button
					variant="secondary"
					onClick={ onLoadMore }
					isBusy={ loadingMore }
					disabled={ loadingMore }
				>
					{ __( 'Show more of the comparison', 'selective-undo' ) }
				</Button>
			) }
		</figure>
	);
}

/**
 * Loads a diff lazily and appends further pages on demand.
 * @param root0
 * @param root0.load
 */
export function DiffLoader( {
	load,
}: {
	load: ( offset: number, signal?: AbortSignal ) => Promise< DiffChunk >;
} ) {
	const [ chunk, setChunk ] = useState< DiffChunk | null >( null );
	const [ error, setError ] = useState< unknown >( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		const controller = new AbortController();
		setChunk( null );
		setError( null );
		load( 0, controller.signal )
			.then( setChunk )
			.catch( ( e: unknown ) => {
				if ( ! ( e instanceof Error && e.name === 'AbortError' ) ) {
					setError( e );
				}
			} );
		return () => controller.abort();
	}, [ load ] );

	if ( error ) {
		return <ErrorNotice error={ error } />;
	}
	if ( ! chunk ) {
		return (
			<Loading label={ __( 'Loading comparison…', 'selective-undo' ) } />
		);
	}

	const more = async () => {
		if ( chunk.next_offset === null ) {
			return;
		}
		setBusy( true );
		try {
			const next = await load( chunk.next_offset );
			setChunk( { ...next, lines: [ ...chunk.lines, ...next.lines ] } );
		} catch ( e: unknown ) {
			setError( e );
		} finally {
			setBusy( false );
		}
	};

	return (
		<DiffViewer chunk={ chunk } onLoadMore={ more } loadingMore={ busy } />
	);
}
