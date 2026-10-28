import { useCommand } from '@wordpress/commands';
import { ToggleControl } from '@wordpress/components';
import { dispatch, select as selectFrom, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { backup } from '@wordpress/icons';
import { registerPlugin } from '@wordpress/plugins';
import { api } from '../admin/api/client';
import type { TimelineItem } from '../admin/api/types';
import { exactDate, relativeDate } from '../admin/lib/format';
import { eventLabel } from '../admin/lib/labels';
import { registerBlockHistory, setShowAuthors } from './block-history';
import { registerSaveToken } from './save-token';
import { EDITOR_FIELDS, UndoInEditorButton } from './undo-in-editor';
import './editor.scss';

interface EditorConfig {
	historyUrl: string;
	canRestore: boolean;
}

declare global {
	interface Window {
		selectiveUndoEditor?: EditorConfig;
	}
}

const LIMIT = 5;
const PANEL = 'selective-undo/selective-undo-history';

/**
 * Opens the document sidebar with the history panel expanded.
 */
function showPanel(): void {
	const area = dispatch( 'core/interface' ) as {
		enableComplementaryArea?: ( scope: string, id: string ) => void;
	};
	area.enableComplementaryArea?.( 'core', 'edit-post/document' );
	if ( ! selectFrom( editorStore ).isEditorPanelOpened( PANEL ) ) {
		dispatch( editorStore ).toggleEditorPanelOpened( PANEL );
	}
}

function changeUrl( base: string, item: TimelineItem ): string {
	return `${ base }&view=changeset&id=${ encodeURIComponent(
		item.changeset.id
	) }`;
}

/**
 * Recent recorded changes of the post being edited. Title, content and excerpt
 * changes can be undone right here, against the editor's own value; other
 * changes are undone from the change history.
 */
function HistoryPanel() {
	const config = window.selectiveUndoEditor;
	const { postId, saving } = useSelect( ( select ) => {
		const editor = select( editorStore );

		return {
			postId: editor.getCurrentPostId() as number | null,
			saving: editor.isSavingPost() && ! editor.isAutosavingPost(),
		};
	}, [] );
	const [ items, setItems ] = useState< TimelineItem[] | null >( null );
	const [ failed, setFailed ] = useState( false );
	const [ version, setVersion ] = useState( 0 );
	const [ authors, setAuthors ] = useState( false );
	const wasSaving = useRef( false );

	// Reload after every save so the change just made is listed.
	useEffect( () => {
		if ( wasSaving.current && ! saving ) {
			setVersion( ( v ) => v + 1 );
		}
		wasSaving.current = saving;
	}, [ saving ] );

	useEffect( () => {
		if ( ! postId ) {
			return;
		}
		const controller = new AbortController();
		api.objectTimeline( postId, null, controller.signal, LIMIT )
			.then( ( page ) => {
				setItems( page.items );
				setFailed( false );
			} )
			.catch( () => {
				if ( ! controller.signal.aborted ) {
					setFailed( true );
				}
			} );
		return () => controller.abort();
	}, [ postId, version ] );

	useCommand( {
		name: 'selective-undo/show-item-history',
		label: __( 'Show the change history of this item', 'selective-undo' ),
		icon: backup,
		callback: ( { close } ) => {
			close();
			showPanel();
		},
	} );

	const toggleAuthors = ( value: boolean ) => {
		setAuthors( value );
		setShowAuthors( value );
	};

	useCommand( {
		name: 'selective-undo/toggle-block-authors',
		label: authors
			? __( 'Hide who changed each block', 'selective-undo' )
			: __( 'Show who changed each block', 'selective-undo' ),
		icon: backup,
		callback: ( { close } ) => {
			close();
			toggleAuthors( ! authors );
		},
	} );

	if ( ! config || ! postId ) {
		return null;
	}

	let body;

	if ( failed ) {
		body = (
			<p className="su-editor__muted">
				{ __( 'The history could not be loaded.', 'selective-undo' ) }
			</p>
		);
	} else if ( items === null ) {
		body = (
			<p className="su-editor__muted">
				{ __( 'Loading…', 'selective-undo' ) }
			</p>
		);
	} else if ( items.length === 0 ) {
		body = (
			<p className="su-editor__muted">
				{ __( 'No changes recorded yet.', 'selective-undo' ) }
			</p>
		);
	} else {
		body = (
			<ul className="su-editor__list">
				{ items.map( ( item ) => (
					<li key={ item.id }>
						<a href={ changeUrl( config.historyUrl, item ) }>
							{ item.field
								? item.field_label
								: eventLabel( item.event ) }
						</a>
						<span className="su-editor__muted">
							{ item.changeset.actor?.name ??
								__( 'System', 'selective-undo' ) }
							{ ' · ' }
							<time
								dateTime={ item.created_at }
								title={ exactDate( item.created_at ) }
							>
								{ relativeDate( item.created_at ) }
							</time>
						</span>
						{ config.canRestore &&
							item.restorable &&
							EDITOR_FIELDS.includes( item.field ) && (
								<UndoInEditorButton
									changeId={ item.id }
									field={ item.field }
								/>
							) }
					</li>
				) ) }
			</ul>
		);
	}

	return (
		<PluginDocumentSettingPanel
			name="selective-undo-history"
			title={ __( 'Change history', 'selective-undo' ) }
		>
			{ body }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Show who changed each block', 'selective-undo' ) }
				help={ __(
					'Marks every block with a stripe in the color of the person who last changed it. Select a block to see its change.',
					'selective-undo'
				) }
				checked={ authors }
				onChange={ toggleAuthors }
			/>
			<p>
				<a href={ `${ config.historyUrl }&view=object&id=${ postId }` }>
					{ __( 'Compare and undo changes', 'selective-undo' ) }
				</a>
			</p>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'selective-undo', { render: HistoryPanel } );
registerBlockHistory();
registerSaveToken();
