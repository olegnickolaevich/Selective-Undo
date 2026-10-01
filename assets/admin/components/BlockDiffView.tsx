import { Button, CheckboxControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api/client';
import type { BlockDiff, DiffBlock } from '../api/types';
import { useAsync } from '../hooks/useAsync';
import { navigate } from '../lib/router';
import { ErrorNotice, Loading } from './Feedback';

/**
 * "core/heading" and "heading" both read as "heading".
 * @param block
 */
function blockName( block: DiffBlock ): string {
	return block.name === ''
		? __( 'text', 'selective-undo' )
		: block.name.replace( /^core\//, '' );
}

function Blocks( {
	blocks,
	title,
	tone,
	html,
}: {
	blocks: DiffBlock[];
	title: string;
	tone: 'delete' | 'insert';
	html: boolean;
} ) {
	return (
		<div className={ `su-block-diff__side su-block-diff__side--${ tone }` }>
			<p className="su-block-diff__side-title">{ title }</p>
			{ blocks.length === 0 ? (
				<p className="su-muted">
					{ __( '(nothing)', 'selective-undo' ) }
				</p>
			) : (
				blocks.map( ( block, i ) => (
					<div key={ i } className="su-block-diff__block">
						<span className="su-block-diff__name">
							{ blockName( block ) }
						</span>
						{ html ? (
							<pre className="su-block-diff__html">
								{ block.html }
							</pre>
						) : (
							<p className="su-block-diff__text">
								{ block.text === '' ? '—' : block.text }
							</p>
						) }
					</div>
				) )
			) }
		</div>
	);
}

/**
 * One change of the content as numbered parts; each part can be undone alone.
 * @param root0
 * @param root0.changeId
 * @param root0.canUndo
 */
export function BlockDiffView( {
	changeId,
	canUndo,
}: {
	changeId: number;
	canUndo: boolean;
} ) {
	const { data, error, loading, reload } = useAsync< BlockDiff >(
		( signal ) => api.changeBlocks( changeId, signal ),
		[ changeId ]
	);
	const [ chosen, setChosen ] = useState< number[] >( [] );
	const [ html, setHtml ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ planError, setPlanError ] = useState< unknown >( null );

	if ( loading && ! data ) {
		return <Loading label={ __( 'Loading…', 'selective-undo' ) } />;
	}
	if ( error || ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data.available ) {
		return (
			<p className="su-muted">
				{ __(
					'This change cannot be shown block by block.',
					'selective-undo'
				) }
			</p>
		);
	}

	const undo = async ( hunks: number[] ) => {
		setBusy( true );
		setPlanError( null );
		try {
			const plan = await api.createPlan( {
				type: 'segments',
				change_id: changeId,
				hunks,
			} );
			navigate( { name: 'preview', id: plan.id } );
		} catch ( e: unknown ) {
			setPlanError( e );
			setBusy( false );
		}
	};

	return (
		<div className="su-block-diff">
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Show HTML', 'selective-undo' ) }
				checked={ html }
				onChange={ setHtml }
			/>
			<ol className="su-block-diff__list">
				{ data.hunks.map( ( hunk, i ) =>
					hunk.kind === 'same' ? (
						hunk.blocks > 0 && (
							<li key={ i } className="su-block-diff__same">
								{ sprintf(
									/* translators: %d: number of blocks. */
									_n(
										'%d unchanged block',
										'%d unchanged blocks',
										hunk.blocks,
										'selective-undo'
									),
									hunk.blocks
								) }
							</li>
						)
					) : (
						<li key={ i } className="su-block-diff__change">
							<div className="su-block-diff__sides">
								<Blocks
									blocks={ hunk.before }
									title={ __( 'Before', 'selective-undo' ) }
									tone="delete"
									html={ html }
								/>
								<Blocks
									blocks={ hunk.after }
									title={ __( 'After', 'selective-undo' ) }
									tone="insert"
									html={ html }
								/>
							</div>
							{ canUndo && (
								<div className="su-block-diff__actions">
									<CheckboxControl
										__nextHasNoMarginBottom
										label={ sprintf(
											/* translators: %d: number of the part. */
											__( 'Part %d', 'selective-undo' ),
											hunk.index + 1
										) }
										checked={ chosen.includes(
											hunk.index
										) }
										onChange={ ( checked ) =>
											setChosen( ( prev ) =>
												checked
													? [ ...prev, hunk.index ]
													: prev.filter(
															( n ) =>
																n !== hunk.index
														)
											)
										}
									/>
									<Button
										variant="link"
										disabled={ busy }
										accessibleWhenDisabled
										onClick={ () => undo( [ hunk.index ] ) }
									>
										{ __(
											'Undo this part',
											'selective-undo'
										) }
									</Button>
								</div>
							) }
						</li>
					)
				) }
			</ol>
			{ planError !== null && <ErrorNotice error={ planError } /> }
			{ canUndo && (
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy || chosen.length === 0 }
					accessibleWhenDisabled
					onClick={ () => undo( chosen ) }
				>
					{ sprintf(
						/* translators: %d: number of selected parts. */
						_n(
							'Check undo of %d part',
							'Check undo of %d parts',
							chosen.length,
							'selective-undo'
						),
						chosen.length
					) }
				</Button>
			) }
		</div>
	);
}
