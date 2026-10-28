import { Button, TextControl } from '@wordpress/components';
import { date, getDate } from '@wordpress/date';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api/client';
import type { ObjectSummary, Overview, TimelineItem } from '../api/types';
import {
	EmptyState,
	ErrorNotice,
	LoadMore,
	Loading,
} from '../components/Feedback';
import { ObjectTitle } from '../components/ObjectTitle';
import { ViewHeading } from '../components/ViewHeading';
import { exactDate, relativeDate } from '../lib/format';
import { displayTitle, sourceLabel } from '../lib/labels';
import { linkHandler, viewToUrl } from '../lib/router';
import { ChangeRow, checkRestore } from './ChangesetPage';

/**
 * "2026-10-01T10:30" in the site's time zone, for a datetime-local input. The
 * history shows times in the site's time zone, so the input uses it too.
 * @param iso
 */
function siteInput( iso: string ): string {
	return date( 'Y-m-d\\TH:i', iso );
}

/**
 * Back to the state at a moment: every field changed after it is restored to its
 * value at that moment.
 * @param root0
 * @param root0.objectId
 * @param root0.initial
 */
function AsOfForm( {
	objectId,
	initial,
}: {
	objectId: number;
	initial: string;
} ) {
	const [ at, setAt ] = useState( initial );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< unknown >( null );
	const moment = getDate( at );

	return (
		<form
			className="su-as-of"
			onSubmit={ ( event ) => {
				event.preventDefault();
				if ( ! Number.isNaN( moment.getTime() ) ) {
					void checkRestore(
						{
							type: 'as_of',
							object_id: objectId,
							at: moment.toISOString(),
						},
						setError,
						setBusy
					);
				}
			} }
		>
			<TextControl
				__nextHasNoMarginBottom
				type="datetime-local"
				label={ __( 'Return to the state at', 'selective-undo' ) }
				value={ at }
				onChange={ setAt }
			/>
			<Button
				variant="secondary"
				type="submit"
				isBusy={ busy }
				disabled={ busy || Number.isNaN( moment.getTime() ) }
				accessibleWhenDisabled
			>
				{ __( 'Check', 'selective-undo' ) }
			</Button>
			{ error !== null && <ErrorNotice error={ error } /> }
		</form>
	);
}

/**
 * History of one item. Several consecutive changes of the same field can be
 * selected together: the preview folds them into one restore.
 * @param root0
 * @param root0.id
 * @param root0.overview
 */
export function ObjectPage( {
	id,
	overview,
}: {
	id: number;
	overview: Overview | null;
} ) {
	const [ object, setObject ] = useState< ObjectSummary | null >( null );
	const [ items, setItems ] = useState< TimelineItem[] >( [] );
	const [ cursor, setCursor ] = useState< string | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ more, setMore ] = useState( false );
	const [ error, setError ] = useState< unknown >( null );
	const [ selected, setSelected ] = useState< number[] >( [] );
	const [ busy, setBusy ] = useState( false );
	const canRestore = overview?.user.can.restore ?? false;

	useEffect( () => {
		const controller = new AbortController();
		setLoading( true );
		api.objectTimeline( id, null, controller.signal )
			.then( ( page ) => {
				setObject( page.object );
				setItems( page.items );
				setCursor( page.next_cursor );
				setLoading( false );
			} )
			.catch( ( e: unknown ) => {
				if ( ! controller.signal.aborted ) {
					setError( e );
					setLoading( false );
				}
			} );
		return () => controller.abort();
	}, [ id ] );

	const loadMore = async () => {
		setMore( true );
		try {
			const page = await api.objectTimeline( id, cursor );
			setItems( ( prev ) => [ ...prev, ...page.items ] );
			setCursor( page.next_cursor );
		} catch ( e: unknown ) {
			setError( e );
		} finally {
			setMore( false );
		}
	};

	if ( loading ) {
		return <Loading />;
	}
	if ( error !== null && ! object ) {
		return <ErrorNotice error={ error } />;
	}

	return (
		<section className="su-panel">
			<ViewHeading>
				{ object
					? displayTitle( object.title, object.id )
					: __( 'Item history', 'selective-undo' ) }
			</ViewHeading>
			{ object && <ObjectTitle object={ object } withLinks={ false } /> }
			{ object?.edit_url && (
				<p>
					<a href={ object.edit_url }>
						{ __( 'Open editor', 'selective-undo' ) }
					</a>
				</p>
			) }
			{ items.length === 0 ? (
				<EmptyState
					title={ __(
						'No recorded changes for this item.',
						'selective-undo'
					) }
				/>
			) : (
				<ol className="su-timeline">
					{ items.map( ( item ) => {
						const view = {
							name: 'changeset' as const,
							id: item.changeset.id,
						};
						return (
							<li key={ item.id } className="su-timeline__item">
								<p className="su-timeline__meta">
									<time
										dateTime={ item.created_at }
										title={ exactDate( item.created_at ) }
									>
										{ relativeDate( item.created_at ) }
									</time>{ ' ' }
									·{ ' ' }
									{ item.changeset.actor?.name ??
										__(
											'System',
											'selective-undo'
										) }{ ' ' }
									· { sourceLabel( item.changeset.source ) } ·{ ' ' }
									<a
										href={ viewToUrl( view ) }
										onClick={ linkHandler( view ) }
									>
										{ __(
											'Whole operation',
											'selective-undo'
										) }
									</a>
								</p>
								<ul className="su-changes">
									<ChangeRow
										change={ item }
										selectable={ canRestore }
										selected={ selected.includes(
											item.id
										) }
										onToggle={ ( checked ) =>
											setSelected( ( prev ) =>
												checked
													? [ ...prev, item.id ]
													: prev.filter(
															( x ) =>
																x !== item.id
														)
											)
										}
									/>
								</ul>
							</li>
						);
					} ) }
				</ol>
			) }
			{ cursor && <LoadMore onClick={ loadMore } busy={ more } /> }
			{ canRestore && items.some( ( i ) => i.restorable ) && (
				<div className="su-actions su-actions--sticky-free">
					{ error !== null && <ErrorNotice error={ error } /> }
					<Button
						variant="primary"
						disabled={ selected.length === 0 || busy }
						isBusy={ busy }
						accessibleWhenDisabled
						onClick={ () =>
							checkRestore(
								{ type: 'changes', change_ids: selected },
								setError,
								setBusy
							)
						}
					>
						{ __( 'Check restore', 'selective-undo' ) }
					</Button>
					<p className="su-muted">
						{ __(
							'Select one change, or several consecutive changes of the same field to return to the state before the first one.',
							'selective-undo'
						) }
					</p>
				</div>
			) }
			{ canRestore && items.length > 1 && (
				<AsOfForm
					objectId={ id }
					initial={ siteInput(
						( items[ 1 ] ?? items[ 0 ] )!.created_at
					) }
				/>
			) }
		</section>
	);
}
