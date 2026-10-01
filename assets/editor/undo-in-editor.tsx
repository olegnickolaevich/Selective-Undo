import { parse } from '@wordpress/blocks';
import { Button, Modal, RadioControl } from '@wordpress/components';
import { dispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { api } from '../admin/api/client';
import type { ConflictSide, EditorMergePreview } from '../admin/api/types';

/**
 * "Undo in the editor": the change is undone against the editor's own, possibly
 * unsaved, value. The result becomes an ordinary edit (Ctrl+Z reverts it) that
 * the user saves; restoring on the server would be overwritten by the editor.
 */
export const EDITOR_FIELDS = [ 'post_title', 'post_content', 'post_excerpt' ];

const ATTRIBUTE: Record< string, 'title' | 'excerpt' > = {
	post_title: 'title',
	post_excerpt: 'excerpt',
};

type Conflicts = NonNullable< EditorMergePreview[ 'conflicts' ] >;

function editorValue( field: string ): string {
	const editor = select( editorStore );
	if ( field === 'post_content' ) {
		return editor.getEditedPostContent();
	}
	const value = editor.getEditedPostAttribute( ATTRIBUTE[ field ] ?? '' );
	return typeof value === 'string' ? value : '';
}

function apply( field: string, value: string, changeId: number ): void {
	const editor = dispatch( editorStore );
	if ( field === 'post_content' ) {
		editor.resetEditorBlocks( parse( value ) );
	} else {
		editor.editPost( { [ ATTRIBUTE[ field ] ?? field ]: value } );
	}

	// The save request carries the intent: history links the save to this change.
	const intent = select( editorStore ).getEditedPostAttribute(
		'selective_undo_intent'
	) as { reverts?: number[] } | undefined;
	editor.editPost( {
		selective_undo_intent: {
			reverts: [ ...( intent?.reverts ?? [] ), changeId ],
		},
	} );

	dispatch( noticesStore ).createSuccessNotice(
		__(
			'The change was undone in the editor. Save the post to keep it.',
			'selective-undo'
		),
		{
			type: 'snackbar',
			actions: [
				{
					label: __( 'Revert', 'selective-undo' ),
					onClick: () => dispatch( editorStore ).undo(),
				},
			],
		}
	);
}

function stripTags( html: string ): string {
	return (
		new window.DOMParser()
			.parseFromString( html, 'text/html' )
			.body.textContent?.trim() ?? ''
	);
}

function ConflictModal( {
	conflicts,
	onResolve,
	onClose,
}: {
	conflicts: Conflicts;
	onResolve: ( resolutions: Record< number, ConflictSide > ) => void;
	onClose: () => void;
} ) {
	const [ choice, setChoice ] = useState< Record< number, ConflictSide > >(
		{}
	);

	return (
		<Modal
			title={ __( 'Some parts were changed twice', 'selective-undo' ) }
			onRequestClose={ onClose }
		>
			<p>
				{ __(
					'These parts were changed again after the change you are undoing. Choose what to keep for each part; everything else keeps its later edits.',
					'selective-undo'
				) }
			</p>
			{ conflicts.map( ( c ) => (
				<fieldset key={ c.index } className="su-editor__conflict">
					<legend>{ c.label }</legend>
					<div className="su-editor__sides">
						<div>
							<p className="su-editor__muted">
								{ __( 'Now', 'selective-undo' ) }
							</p>
							<p>{ stripTags( c.current ) }</p>
						</div>
						<div>
							<p className="su-editor__muted">
								{ __( 'Comes back', 'selective-undo' ) }
							</p>
							<p>{ stripTags( c.restored ) }</p>
						</div>
					</div>
					<RadioControl
						label={ __( 'Keep', 'selective-undo' ) }
						selected={ choice[ c.index ] ?? 'current' }
						options={ [
							{
								label: __(
									'The current text',
									'selective-undo'
								),
								value: 'current',
							},
							{
								label: __(
									'The earlier text',
									'selective-undo'
								),
								value: 'restored',
							},
						] }
						onChange={ ( side ) =>
							setChoice( ( prev ) => ( {
								...prev,
								[ c.index ]: side as ConflictSide,
							} ) )
						}
					/>
				</fieldset>
			) ) }
			<Button
				variant="primary"
				onClick={ () =>
					onResolve(
						Object.fromEntries(
							conflicts.map( ( c ) => [
								c.index,
								choice[ c.index ] ?? 'current',
							] )
						)
					)
				}
			>
				{ __( 'Undo with this choice', 'selective-undo' ) }
			</Button>
		</Modal>
	);
}

/**
 * Button for one recorded change in the editor's history panel.
 * @param root0
 * @param root0.changeId
 * @param root0.field
 */
export function UndoInEditorButton( {
	changeId,
	field,
}: {
	changeId: number;
	field: string;
} ) {
	const [ busy, setBusy ] = useState( false );
	const [ conflicts, setConflicts ] = useState< Conflicts | null >( null );

	const run = async ( resolutions: Record< number, ConflictSide > = {} ) => {
		setBusy( true );
		setConflicts( null );
		const notices = dispatch( noticesStore );
		try {
			const preview = await api.mergePreview(
				changeId,
				editorValue( field ),
				resolutions
			);
			if ( preview.status === 'clean' && preview.value !== undefined ) {
				apply( field, preview.value, changeId );
			} else if ( preview.status === 'conflict' ) {
				setConflicts( preview.conflicts ?? [] );
			} else if ( preview.status === 'already' ) {
				notices.createInfoNotice(
					__( 'This change is already undone.', 'selective-undo' ),
					{ type: 'snackbar' }
				);
			} else {
				notices.createWarningNotice(
					__(
						'This change cannot be undone in the editor. Use the change history instead.',
						'selective-undo'
					),
					{ type: 'snackbar' }
				);
			}
		} catch {
			notices.createErrorNotice(
				__(
					'Could not undo. Open the history to try again.',
					'selective-undo'
				),
				{ type: 'snackbar' }
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<>
			<Button
				variant="link"
				className="su-editor__undo"
				isBusy={ busy }
				disabled={ busy }
				accessibleWhenDisabled
				onClick={ () => run() }
			>
				{ __( 'Undo in the editor', 'selective-undo' ) }
			</Button>
			{ conflicts && (
				<ConflictModal
					conflicts={ conflicts }
					onClose={ () => setConflicts( null ) }
					onResolve={ ( resolutions ) => run( resolutions ) }
				/>
			) }
		</>
	);
}
