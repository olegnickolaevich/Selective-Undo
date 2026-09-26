import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { api } from '../admin/api/client';
import type { TimelineItem } from '../admin/api/types';
import { exactDate, relativeDate } from '../admin/lib/format';
import { eventLabel } from '../admin/lib/labels';
import './editor.scss';

interface EditorConfig {
	historyUrl: string;
}

declare global {
	interface Window {
		selectiveUndoEditor?: EditorConfig;
	}
}

const LIMIT = 5;

function changeUrl( base: string, item: TimelineItem ): string {
	return `${ base }&view=changeset&id=${ encodeURIComponent(
		item.changeset.id
	) }`;
}

/**
 * Recent recorded changes of the post being edited. Undo happens in Selective
 * Undo itself: the editor keeps its own copy of the content and would write it
 * back on the next save.
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
			<p>
				<a href={ `${ config.historyUrl }&view=object&id=${ postId }` }>
					{ __( 'Compare and undo changes', 'selective-undo' ) }
				</a>
			</p>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'selective-undo', { render: HistoryPanel } );
