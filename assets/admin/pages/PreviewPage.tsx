import { Button, Notice } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { ApiError, api } from '../api/client';
import type { PlanItem, RestorePlan, Uuid } from '../api/types';
import { DiffLoader } from '../components/DiffViewer';
import { ErrorNotice, Loading, errorMessage } from '../components/Feedback';
import { ObjectTitle } from '../components/ObjectTitle';
import { StatusBadge } from '../components/StatusBadge';
import { ViewHeading } from '../components/ViewHeading';
import { useAsync } from '../hooks/useAsync';
import { useIdempotencyKey } from '../hooks/useIdempotencyKey';
import { exactDate, fieldsCount, itemsCount } from '../lib/format';
import {
	planStatusLabel,
	planStatusTone,
	reasonText,
	untouchedLabel,
	warningText,
} from '../lib/labels';
import { navigate } from '../lib/router';

function Item( { plan, item }: { plan: RestorePlan; item: PlanItem } ) {
	const [ view, setView ] = useState< null | 'current_target' | 'conflict' >(
		null
	);
	const load = useCallback(
		( offset: number, signal?: AbortSignal ) =>
			api.planItemDiff(
				plan.id,
				item.id,
				view ?? 'current_target',
				offset,
				signal
			),
		[ plan.id, item.id, view ]
	);
	const comparable =
		item.status !== 'missing_object' &&
		item.status !== 'forbidden' &&
		item.status !== 'unsupported';

	return (
		<li className={ `su-plan-item su-plan-item--${ item.status }` }>
			<div className="su-plan-item__head">
				<span className="su-plan-item__field">
					{ item.field_label }
				</span>
				<StatusBadge tone={ planStatusTone( item.status ) }>
					{ planStatusLabel( item.status ) }
				</StatusBadge>
			</div>
			<ObjectTitle object={ item.object } />
			{ item.reason_code && <p>{ reasonText( item.reason_code ) }</p> }
			{ item.change_ids.length > 1 && (
				<p className="su-muted">
					{ sprintf(
						/* translators: %d: number of changes. */
						_n(
							'%d change is undone together.',
							'%d consecutive changes are undone together.',
							item.change_ids.length,
							'selective-undo'
						),
						item.change_ids.length
					) }
				</p>
			) }
			{ item.warnings.length > 0 && (
				<ul className="su-warnings">
					{ item.warnings.map( ( w ) => (
						<li key={ w }>{ warningText( w ) }</li>
					) ) }
				</ul>
			) }
			{ comparable && (
				<div className="su-plan-item__actions">
					<Button
						variant="link"
						aria-expanded={ view === 'current_target' }
						onClick={ () =>
							setView(
								view === 'current_target'
									? null
									: 'current_target'
							)
						}
					>
						{ __(
							'Compare current and restored value',
							'selective-undo'
						) }
					</Button>
					{ item.status === 'conflict' && (
						<Button
							variant="link"
							aria-expanded={ view === 'conflict' }
							onClick={ () =>
								setView(
									view === 'conflict' ? null : 'conflict'
								)
							}
						>
							{ __( 'What changed since', 'selective-undo' ) }
						</Button>
					) }
				</div>
			) }
			{ view && <DiffLoader load={ load } /> }
		</li>
	);
}

function RestoreActions( {
	plan,
	onExpired,
}: {
	plan: RestorePlan;
	onExpired: () => void;
} ) {
	const key = useIdempotencyKey( plan.id );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< unknown >( null );
	const noticeRef = useRef< HTMLDivElement >( null );
	const expired =
		plan.status !== 'ready' || Date.parse( plan.expires_at ) <= Date.now();
	const ready = plan.summary.ready;

	useEffect( () => {
		if ( error ) {
			noticeRef.current?.focus();
			speak( errorMessage( error ), 'assertive' );
		}
	}, [ error ] );

	const start = async () => {
		if ( busy || expired || ready === 0 ) {
			return;
		}
		setBusy( true );
		setError( null );
		try {
			const job = await api.startRestore( plan.id, key );
			navigate( { name: 'job', id: job.id } );
		} catch ( e: unknown ) {
			setError( e );
			setBusy( false );
		}
	};

	const consumedJob: Uuid | null =
		error instanceof ApiError && error.code === 'su_plan_consumed'
			? ( ( error.details.job_id as Uuid | null ) ?? plan.job_id )
			: plan.job_id;

	let errorActions: Array< { label: string; onClick: () => void } > = [];

	if ( error instanceof ApiError && error.code === 'su_plan_expired' ) {
		errorActions = [
			{
				label: __( 'Check again', 'selective-undo' ),
				onClick: onExpired,
			},
		];
	} else if ( consumedJob ) {
		errorActions = [
			{
				label: __( 'Open that restore', 'selective-undo' ),
				onClick: () => navigate( { name: 'job', id: consumedJob } ),
			},
		];
	}

	return (
		<section
			className="su-actions"
			aria-label={ __( 'Restore actions', 'selective-undo' ) }
		>
			{ error !== null && (
				<div ref={ noticeRef } tabIndex={ -1 }>
					<Notice
						status="error"
						isDismissible={ false }
						actions={ errorActions }
					>
						{ error instanceof ApiError &&
						error.code !== 'su_unknown_error'
							? errorMessage( error )
							: __(
									'Could not confirm that the restore started. You can safely try again: the same request will not run twice.',
									'selective-undo'
								) }
					</Notice>
				</div>
			) }
			{ expired && plan.status !== 'consumed' && (
				<Notice
					status="warning"
					isDismissible={ false }
					actions={ [
						{
							label: __( 'Check again', 'selective-undo' ),
							onClick: onExpired,
						},
					] }
				>
					{ __(
						'This preview has expired. Check the changes again before restoring.',
						'selective-undo'
					) }
				</Notice>
			) }
			{ plan.status === 'consumed' && plan.job_id && (
				<Notice
					status="info"
					isDismissible={ false }
					actions={ [
						{
							label: __( 'Open that restore', 'selective-undo' ),
							onClick: () =>
								navigate( { name: 'job', id: plan.job_id! } ),
						},
					] }
				>
					{ __(
						'This preview has already been used to start a restore.',
						'selective-undo'
					) }
				</Notice>
			) }
			<Button
				variant="primary"
				isBusy={ busy }
				disabled={ busy || expired || ready === 0 }
				accessibleWhenDisabled
				onClick={ start }
			>
				{ sprintf(
					/* translators: %d: number of fields that will be restored. */
					_n(
						'Restore %d field',
						'Restore %d fields',
						ready,
						'selective-undo'
					),
					ready
				) }
			</Button>
			{ ready === 0 && ! expired && (
				<p className="su-muted">
					{ __(
						'Nothing in this preview can be restored.',
						'selective-undo'
					) }
				</p>
			) }
		</section>
	);
}

export function PreviewPage( { id }: { id: string } ) {
	const {
		data: plan,
		error,
		loading,
		reload,
	} = useAsync< RestorePlan >( ( signal ) => api.plan( id, signal ), [ id ] );
	const [ rechecking, setRechecking ] = useState( false );
	const [ recheckError, setRecheckError ] = useState< unknown >( null );

	const recheck = async () => {
		if ( ! plan?.selection ) {
			navigate( { name: 'history' } );
			return;
		}
		setRechecking( true );
		try {
			const next = await api.createPlan( plan.selection );
			navigate( { name: 'preview', id: next.id }, true );
		} catch ( e: unknown ) {
			setRecheckError( e );
		} finally {
			setRechecking( false );
		}
	};

	if ( loading && ! plan ) {
		return (
			<Loading
				label={ __( 'Checking the current state…', 'selective-undo' ) }
			/>
		);
	}
	if ( error || ! plan ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}

	const s = plan.summary;
	const attention =
		s.conflicts + s.blocked + s.missing + s.forbidden + s.unsupported;
	const groups: Array< { key: string; title: string; items: PlanItem[] } > = [
		{
			key: 'attention',
			title: __( 'Need attention', 'selective-undo' ),
			items: plan.items.filter(
				( i ) => ! [ 'ready', 'already_restored' ].includes( i.status )
			),
		},
		{
			key: 'ready',
			title: __( 'Ready to restore', 'selective-undo' ),
			items: plan.items.filter( ( i ) => i.status === 'ready' ),
		},
		{
			key: 'already',
			title: __( 'Already in the desired state', 'selective-undo' ),
			items: plan.items.filter(
				( i ) => i.status === 'already_restored'
			),
		},
	];

	return (
		<section className="su-panel">
			<ViewHeading>
				{ __( 'Check restore', 'selective-undo' ) }
			</ViewHeading>
			<p>
				{ __( 'Will be processed:', 'selective-undo' ) }{ ' ' }
				{ itemsCount( s.objects ) } · { fieldsCount( s.fields ) }
			</p>
			<dl className="su-summary">
				<div className="su-summary__cell su-summary__cell--success">
					<dt>{ __( 'Ready to restore', 'selective-undo' ) }</dt>
					<dd>{ s.ready }</dd>
				</div>
				<div className="su-summary__cell">
					<dt>
						{ __(
							'Already in the desired state',
							'selective-undo'
						) }
					</dt>
					<dd>{ s.already_restored }</dd>
				</div>
				<div
					className={ `su-summary__cell ${ attention > 0 ? 'su-summary__cell--warning' : '' }` }
				>
					<dt>{ __( 'Need attention', 'selective-undo' ) }</dt>
					<dd>{ attention }</dd>
				</div>
			</dl>
			<p className="su-untouched">
				<strong>
					{ __( 'Will not be changed:', 'selective-undo' ) }
				</strong>{ ' ' }
				{ plan.untouched.map( untouchedLabel ).join( ', ' ) }.
			</p>
			{ plan.status === 'ready' && (
				<p className="su-muted">
					{ sprintf(
						/* translators: %s: date and time. */
						__(
							'This preview is valid until %s. Values are checked again right before writing.',
							'selective-undo'
						),
						exactDate( plan.expires_at )
					) }
				</p>
			) }
			{ recheckError !== null && <ErrorNotice error={ recheckError } /> }
			{ groups.map(
				( group ) =>
					group.items.length > 0 && (
						<section key={ group.key } className="su-plan-group">
							<h3 className="su-subtitle">
								{ group.title } ({ group.items.length })
							</h3>
							<ul className="su-plan-items">
								{ group.items.map( ( item ) => (
									<Item
										key={ item.id }
										plan={ plan }
										item={ item }
									/>
								) ) }
							</ul>
						</section>
					)
			) }
			{ rechecking ? (
				<Loading
					label={ __(
						'Checking the current state…',
						'selective-undo'
					) }
				/>
			) : (
				<RestoreActions plan={ plan } onExpired={ recheck } />
			) }
		</section>
	);
}
