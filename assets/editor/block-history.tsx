import {
	InspectorControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect, useState } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import type { ComponentType } from 'react';
import { api } from '../admin/api/client';
import type { BlameResult } from '../admin/api/types';
import { exactDate, relativeDate } from '../admin/lib/format';
import { UndoInEditorButton } from './undo-in-editor';

/**
 * "Who changed this block": the sidebar of a selected top-level block shows the
 * change that last modified it, and can undo just that block's edit. With
 * "Show authors", every top-level block gets a thin stripe in its author's color.
 */
type BlameBlock = BlameResult[ 'blocks' ][ number ];

const COLORS = [
	'#3858e9',
	'#d63638',
	'#00a32a',
	'#dba617',
	'#9b51e0',
	'#0e7c86',
	'#e65054',
	'#7a5c00',
];

/** One request per saved version of the post, shared by all blocks. */
const cache = new Map< string, Promise< BlameResult > >();

/** A value shared by every block, re-rendering the blocks that use it on change. */
interface Shared< T > {
	value: T;
	listeners: Set< () => void >;
}

function shared< T >( value: T ): Shared< T > {
	return { value, listeners: new Set() };
}

function setShared< T >( state: Shared< T >, value: T ): void {
	state.value = value;
	state.listeners.forEach( ( listener ) => listener() );
}

function useShared< T >( state: Shared< T > ): T {
	const [ value, setValue ] = useState( state.value );
	useEffect( () => {
		const listener = () => setValue( state.value );
		state.listeners.add( listener );
		listener();
		return () => {
			state.listeners.delete( listener );
		};
	}, [ state ] );
	return value;
}

const showAuthors = shared( false );
// The panel stays open or closed as the user left it when another block is selected.
const panelOpened = shared( false );

export function setShowAuthors( value: boolean ): void {
	setShared( showAuthors, value );
}

function useBlame( enabled: boolean ): BlameResult | null {
	const { postId, modified } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postId: editor.getCurrentPostId() as number | null,
			// Changes after every save: the cached result is for the saved version.
			modified: String(
				editor.getEditedPostAttribute( 'modified' ) ?? ''
			),
		};
	}, [] );
	const [ result, setResult ] = useState< BlameResult | null >( null );

	useEffect( () => {
		if ( ! enabled || ! postId ) {
			return;
		}
		const key = `${ postId }:${ modified }`;
		let request = cache.get( key );
		if ( ! request ) {
			request = api.blame( postId );
			cache.set( key, request );
		}
		let active = true;
		request
			.then( ( value ) => active && setResult( value ) )
			.catch( () => active && setResult( null ) );
		return () => {
			active = false;
		};
	}, [ enabled, postId, modified ] );

	return enabled ? result : null;
}

/**
 * The same person keeps the same color across saves and posts.
 * @param actorId
 */
function authorColor( actorId: number ): string {
	return COLORS[ actorId % COLORS.length ]!;
}

function BlockOwner( {
	entry,
	complete,
}: {
	entry: BlameBlock | undefined;
	complete: boolean;
} ) {
	const change = entry?.change;

	if ( ! change ) {
		return (
			<p>
				{ complete
					? __(
							'This block has not changed since the history began.',
							'selective-undo'
						)
					: __(
							'This block is older than the recorded history, or the history has a gap.',
							'selective-undo'
						) }
			</p>
		);
	}

	return (
		<>
			<p>
				{ sprintf(
					/* translators: 1: user name, 2: relative time, e.g. "2 hours ago". */
					__( 'Last changed by %1$s, %2$s.', 'selective-undo' ),
					change.actor?.name ?? __( 'System', 'selective-undo' ),
					change.created_at ? relativeDate( change.created_at ) : ''
				) }
				{ change.created_at && (
					<span className="screen-reader-text">
						{ exactDate( change.created_at ) }
					</span>
				) }
			</p>
			{ window.selectiveUndoEditor?.canRestore &&
				change.hunk !== null && (
					<UndoInEditorButton
						changeId={ change.id }
						field="post_content"
						hunks={ [ change.hunk ] }
						label={ __(
							'Undo the change of this block',
							'selective-undo'
						) }
					/>
				) }
		</>
	);
}

interface BlockEditProps {
	clientId: string;
	isSelected: boolean;
}

function useTopLevelIndex( clientId: string ): number | null {
	return useSelect(
		( select ) => {
			const blocks = select( blockEditorStore );
			return blocks.getBlockRootClientId( clientId )
				? null
				: blocks.getBlockIndex( clientId );
		},
		[ clientId ]
	);
}

const withBlockHistory = createHigherOrderComponent(
	( BlockEdit: ComponentType< BlockEditProps > ) =>
		function BlockHistory( props: BlockEditProps ) {
			const index = useTopLevelIndex( props.clientId );
			const active = props.isSelected && index !== null;
			const dirty = useSelect(
				( select ) => select( editorStore ).isEditedPostDirty(),
				[]
			);
			const opened = useShared( panelOpened );
			const blame = useBlame( active && opened && ! dirty );

			return (
				<>
					<BlockEdit { ...props } />
					{ active && (
						<InspectorControls>
							<PanelBody
								title={ __(
									'Change history',
									'selective-undo'
								) }
								opened={ opened }
								onToggle={ () =>
									setShared( panelOpened, ! opened )
								}
							>
								{ dirty && (
									<p>
										{ __(
											'Save the post to see the history of this block.',
											'selective-undo'
										) }
									</p>
								) }
								{ ! dirty && blame && (
									<BlockOwner
										entry={ blame.blocks[ index ] }
										complete={ blame.complete }
									/>
								) }
							</PanelBody>
						</InspectorControls>
					) }
				</>
			);
		},
	'withSelectiveUndoBlockHistory'
);

interface ListBlockProps {
	clientId: string;
	wrapperProps?: Record< string, unknown >;
}

const withAuthorStripe = createHigherOrderComponent(
	( BlockListBlock: ComponentType< ListBlockProps > ) =>
		function AuthorStripe( props: ListBlockProps ) {
			const enabled = useShared( showAuthors );
			const index = useTopLevelIndex( props.clientId );
			const blame = useBlame( enabled && index !== null );
			const change =
				index !== null ? blame?.blocks[ index ]?.change : undefined;

			if ( ! blame || ! change?.actor ) {
				return <BlockListBlock { ...props } />;
			}

			const color = authorColor( change.actor.id );
			return (
				<BlockListBlock
					{ ...props }
					wrapperProps={ {
						...props.wrapperProps,
						title: change.actor.name,
						style: {
							...( props.wrapperProps?.style as object ),
							boxShadow: `inset 3px 0 0 ${ color }`,
						},
					} }
				/>
			);
		},
	'withSelectiveUndoAuthorStripe'
);

export function registerBlockHistory(): void {
	addFilter(
		'editor.BlockEdit',
		'selective-undo/block-history',
		withBlockHistory
	);
	addFilter(
		'editor.BlockListBlock',
		'selective-undo/author-stripe',
		withAuthorStripe
	);
}
