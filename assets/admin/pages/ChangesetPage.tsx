import { Button, CheckboxControl, Notice } from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api } from '../api/client';
import type {
	ChangeSummary,
	ChangesetDetail,
	Overview,
	PlanSelection,
} from '../api/types';
import { DiffLoader } from '../components/DiffViewer';
import { ErrorNotice, Loading, errorMessage } from '../components/Feedback';
import { ObjectTitle } from '../components/ObjectTitle';
import { StatusBadge } from '../components/StatusBadge';
import { ViewHeading } from '../components/ViewHeading';
import { useAsync } from '../hooks/useAsync';
import { exactDate } from '../lib/format';
import {
	changesetTitle,
	displayTitle,
	eventLabel,
	groupingLabel,
	reasonText,
	sourceLabel,
} from '../lib/labels';
import { navigate } from '../lib/router';

export function ChangeRow( {
	change,
	selected,
	onToggle,
	selectable,
}: {
	change: ChangeSummary;
	selected: boolean;
	onToggle: ( checked: boolean ) => void;
	selectable: boolean;
} ) {
	const [ open, setOpen ] = useState( false );
	const load = useCallback(
		( offset: number, signal?: AbortSignal ) =>
			api.changeDiff( change.id, offset, signal ),
		[ change.id ]
	);
	const isField = change.field !== '';
	const label = isField ? change.field_label : eventLabel( change.event );

	return (
		<li className="su-change">
			<div className="su-change__head">
				{ isField && selectable && change.restorable ? (
					<CheckboxControl
						__nextHasNoMarginBottom
						label={
							change.event === 'restore'
								? `${ label } (${ __( 'restored value', 'selective-undo' ) })`
								: label
						}
						checked={ selected }
						onChange={ onToggle }
					/>
				) : (
					<span className="su-change__label">{ label }</span>
				) }
				{ isField && ! change.restorable && (
					<StatusBadge tone="warning">
						{ __( 'Not restorable', 'selective-undo' ) }
					</StatusBadge>
				) }
				{ isField && (
					<Button
						variant="link"
						aria-expanded={ open }
						onClick={ () => setOpen( ! open ) }
					>
						{ open
							? __( 'Hide comparison', 'selective-undo' )
							: __( 'Compare before / after', 'selective-undo' ) }
					</Button>
				) }
			</div>
			{ isField && ! change.restorable && (
				<p className="su-muted">{ reasonText( change.reason_code ) }</p>
			) }
			{ open && <DiffLoader load={ load } /> }
		</li>
	);
}

export async function checkRestore(
	selection: PlanSelection,
	setError: ( e: unknown ) => void,
	setBusy: ( b: boolean ) => void
) {
	setBusy( true );
	setError( null );
	try {
		const plan = await api.createPlan( selection );
		navigate( { name: 'preview', id: plan.id } );
	} catch ( e: unknown ) {
		setError( e );
		setBusy( false );
	}
}

export function ChangesetPage( {
	id,
	overview,
}: {
	id: string;
	overview: Overview | null;
} ) {
	const { data, error, loading, reload } = useAsync< ChangesetDetail >(
		( signal ) => api.changeset( id, signal ),
		[ id ]
	);
	const [ active, setActive ] = useState( 0 );
	const [ selected, setSelected ] = useState< Record< number, number[] > >(
		{}
	);
	const [ busy, setBusy ] = useState( false );
	const [ planError, setPlanError ] = useState< unknown >( null );
	const canRestore = overview?.user.can.restore ?? false;

	if ( loading && ! data ) {
		return <Loading />;
	}
	if ( error || ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}

	const entry = data.objects[ active ] ?? data.objects[ 0 ];
	const objectId = entry?.object.id ?? 0;
	const chosen = selected[ objectId ] ?? [];
	const toggle = ( changeId: number, checked: boolean ) =>
		setSelected( ( prev ) => ( {
			...prev,
			[ objectId ]: checked
				? [ ...( prev[ objectId ] ?? [] ), changeId ]
				: ( prev[ objectId ] ?? [] ).filter( ( c ) => c !== changeId ),
		} ) );
	const restorable = data.objects.some( ( o ) =>
		o.changes.some( ( c ) => c.restorable )
	);

	return (
		<section className="su-panel">
			<ViewHeading>
				{ changesetTitle( {
					...data,
					objects: data.objects.map( ( o ) => o.object ),
				} ) }
			</ViewHeading>
			<dl className="su-meta">
				<div>
					<dt>{ __( 'Author', 'selective-undo' ) }</dt>
					<dd>
						{ data.actor?.name ?? __( 'System', 'selective-undo' ) }
					</dd>
				</div>
				<div>
					<dt>{ __( 'Time', 'selective-undo' ) }</dt>
					<dd>{ exactDate( data.created_at ) }</dd>
				</div>
				<div>
					<dt>{ __( 'Source', 'selective-undo' ) }</dt>
					<dd>{ sourceLabel( data.source ) }</dd>
				</div>
				<div>
					<dt>{ __( 'Group', 'selective-undo' ) }</dt>
					<dd>{ groupingLabel( data ) }</dd>
				</div>
			</dl>
			{ data.hidden_objects > 0 && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: %d: number of items. */
						_n(
							'This operation also changed %d item you are not allowed to view.',
							'This operation also changed %d items you are not allowed to view.',
							data.hidden_objects,
							'selective-undo'
						),
						data.hidden_objects
					) }
				</Notice>
			) }
			{ data.quality !== 'verified' && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Some values of this operation were recorded from WordPress hook data only. They are shown, but check them before restoring.',
						'selective-undo'
					) }
				</Notice>
			) }

			<div className="su-detail-grid">
				{ data.objects.length > 1 && (
					<nav
						className="su-object-list"
						aria-label={ __(
							'Items in this operation',
							'selective-undo'
						) }
					>
						<ul>
							{ data.objects.map( ( o, index ) => (
								<li key={ o.object.id }>
									<button
										type="button"
										className="su-object-list__button"
										aria-current={
											index === active
												? 'true'
												: undefined
										}
										onClick={ () => setActive( index ) }
									>
										{ displayTitle(
											o.object.title,
											o.object.id
										) }
										{ ( selected[ o.object.id ]?.length ??
											0 ) > 0 && (
											<span className="su-count">
												{
													selected[ o.object.id ]!
														.length
												}
											</span>
										) }
									</button>
								</li>
							) ) }
						</ul>
					</nav>
				) }
				{ entry && (
					<div className="su-object-detail">
						<ObjectTitle object={ entry.object } />
						<h3 className="su-subtitle">
							{ __( 'Changed fields', 'selective-undo' ) }
						</h3>
						<ul className="su-changes">
							{ entry.changes.map( ( c ) => (
								<ChangeRow
									key={ c.id }
									change={ c }
									selectable={ canRestore }
									selected={ chosen.includes( c.id ) }
									onToggle={ ( checked ) =>
										toggle( c.id, checked )
									}
								/>
							) ) }
						</ul>
						{ canRestore && restorable && (
							<div className="su-actions">
								{ planError !== null && (
									<ErrorNotice error={ planError } />
								) }
								{ data.objects.length > 1 &&
									( overview?.limits.max_objects_per_plan ??
										1 ) === 1 && (
										<p className="su-muted">
											{ __(
												'Restores are checked one item at a time.',
												'selective-undo'
											) }
										</p>
									) }
								<Button
									variant="primary"
									disabled={ chosen.length === 0 || busy }
									isBusy={ busy }
									accessibleWhenDisabled
									onClick={ () =>
										checkRestore(
											{
												type: 'changes',
												change_ids: chosen,
											},
											setPlanError,
											setBusy
										)
									}
								>
									{ __( 'Check restore', 'selective-undo' ) }
								</Button>
								{ data.kind === 'restore' &&
									data.objects.length === 1 && (
										<Button
											variant="secondary"
											disabled={ busy }
											onClick={ () =>
												checkRestore(
													{
														type: 'changeset',
														changeset_id: data.id,
													},
													setPlanError,
													setBusy
												)
											}
										>
											{ __(
												'Check undo of this restore',
												'selective-undo'
											) }
										</Button>
									) }
							</div>
						) }
						{ planError !== null && ! canRestore && (
							<p>{ errorMessage( planError ) }</p>
						) }
					</div>
				) }
			</div>
		</section>
	);
}
