import { Button, Notice } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { api } from '../api/client';
import type { JobItem, RestoreJob } from '../api/types';
import { TERMINAL_JOB_STATUSES } from '../api/types';
import { ErrorNotice, Loading } from '../components/Feedback';
import { ObjectTitle } from '../components/ObjectTitle';
import { StatusBadge } from '../components/StatusBadge';
import { ViewHeading } from '../components/ViewHeading';
import { usePolling } from '../hooks/usePolling';
import { exactDate } from '../lib/format';
import {
	jobItemStatusLabel,
	jobItemTone,
	jobStatusLabel,
	jobStatusTone,
	reasonText,
} from '../lib/labels';
import { linkHandler, navigate, viewToUrl } from '../lib/router';
import { checkRestore } from './ChangesetPage';

function headline( job: RestoreJob ): string {
	switch ( job.status ) {
		case 'completed':
			return __( 'Restore completed', 'selective-undo' );
		case 'completed_with_conflicts':
			return __(
				'Restore completed, some fields were skipped',
				'selective-undo'
			);
		case 'partially_failed':
			return __( 'Restore partially failed', 'selective-undo' );
		case 'failed':
			return __( 'Restore failed', 'selective-undo' );
		case 'cancelled':
			return __( 'Restore stopped', 'selective-undo' );
		default:
			return __( 'Restoring changes', 'selective-undo' );
	}
}

function Counters( { job }: { job: RestoreJob } ) {
	const c = job.counters;
	const rows: Array< [ string, number ] > = [
		[ __( 'Restored', 'selective-undo' ), c.restored ],
		[
			__( 'Already in the desired state', 'selective-undo' ),
			c.already_restored,
		],
		[ __( 'Skipped: changed again', 'selective-undo' ), c.conflict ],
		[ __( 'Skipped for another reason', 'selective-undo' ), c.skipped ],
		[ __( 'Failed', 'selective-undo' ), c.failed ],
		[ __( 'Not started', 'selective-undo' ), c.cancelled ],
	];

	return (
		<dl className="su-counters">
			{ rows
				.filter( ( [ , n ], i ) => n > 0 || i === 0 )
				.map( ( [ label, n ] ) => (
					<div key={ label }>
						<dt>{ label }</dt>
						<dd>{ n }</dd>
					</div>
				) ) }
		</dl>
	);
}

export function JobPage( { id }: { id: string } ) {
	const {
		data: job,
		error,
		refresh,
	} = usePolling< RestoreJob >( {
		key: id,
		fetcher: ( signal ) => api.job( id, signal ),
		isDone: ( j ) =>
			TERMINAL_JOB_STATUSES.has( j.status ) &&
			j.postprocess_status !== 'pending',
	} );
	const [ items, setItems ] = useState< JobItem[] | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ actionError, setActionError ] = useState< unknown >( null );
	const resultRef = useRef< HTMLDivElement >( null );
	const terminal = job !== null && TERMINAL_JOB_STATUSES.has( job.status );
	const done = job ? job.counters.total - job.counters.pending : 0;

	useEffect( () => {
		if ( ! terminal || ! job ) {
			return;
		}
		// Announce the result once and move focus to it.
		speak( headline( job ), 'assertive' );
		resultRef.current?.focus();
		api.jobItems( job.id )
			.then( ( r ) => setItems( r.items ) )
			.catch( setActionError );
	}, [ terminal, job?.id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( ! job && error ) {
		return <ErrorNotice error={ error } onRetry={ refresh } />;
	}
	if ( ! job ) {
		return <Loading />;
	}

	const cancel = async () => {
		setBusy( true );
		try {
			await api.cancelJob( job.id );
			refresh();
		} catch ( e: unknown ) {
			setActionError( e );
		} finally {
			setBusy( false );
		}
	};

	const runHere = async () => {
		setBusy( true );
		try {
			await api.runJob( job.id );
			refresh();
		} catch ( e: unknown ) {
			setActionError( e );
		} finally {
			setBusy( false );
		}
	};

	const history = { name: 'history' as const };

	return (
		<section className="su-panel">
			<ViewHeading>{ headline( job ) }</ViewHeading>
			<p>
				<StatusBadge tone={ jobStatusTone( job.status ) }>
					{ jobStatusLabel( job.status ) }
				</StatusBadge>{ ' ' }
				<span className="su-muted">
					{ job.actor?.name } · { exactDate( job.created_at ) }
				</span>
			</p>

			{ ! terminal && (
				<div className="su-progress">
					<label htmlFor="su-job-progress">
						{ sprintf(
							/* translators: 1: processed fields, 2: total fields. */
							__(
								'Processed %1$d of %2$d fields',
								'selective-undo'
							),
							done,
							job.counters.total
						) }
					</label>
					<progress
						id="su-job-progress"
						max={ Math.max( 1, job.counters.total ) }
						value={ done }
					/>
					<p className="su-muted">
						{ __(
							'You can close this page. The restore continues if background tasks on the site work.',
							'selective-undo'
						) }
					</p>
					{ job.queue_health !== 'ok' && (
						<Notice
							status="warning"
							isDismissible={ false }
							actions={ [
								{
									label: __(
										'Continue in this tab',
										'selective-undo'
									),
									onClick: runHere,
								},
							] }
						>
							{ __(
								'Background processing on this site seems delayed.',
								'selective-undo'
							) }
						</Notice>
					) }
					{ job.status !== 'cancel_requested' && (
						<Button
							variant="secondary"
							isDestructive
							onClick={ cancel }
							isBusy={ busy }
							disabled={ busy }
						>
							{ __(
								'Stop after the current item',
								'selective-undo'
							) }
						</Button>
					) }
					<p className="su-muted">
						{ __(
							'Stopping does not undo items that were already restored.',
							'selective-undo'
						) }
					</p>
				</div>
			) }

			<div ref={ resultRef } tabIndex={ -1 } className="su-result">
				<Counters job={ job } />
			</div>

			{ job.postprocess_status === 'pending' && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'The content is restored. Cache clearing and other follow-up tasks are still running.',
						'selective-undo'
					) }
				</Notice>
			) }
			{ job.postprocess_status === 'failed' && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The content is restored, but some follow-up tasks (such as clearing the page cache) failed. Clear the page cache manually if visitors still see the old version.',
						'selective-undo'
					) }
				</Notice>
			) }
			{ actionError !== null && <ErrorNotice error={ actionError } /> }

			{ terminal && items && items.length > 0 && (
				<section>
					<h3 className="su-subtitle">
						{ __( 'Report', 'selective-undo' ) }
					</h3>
					<ul className="su-plan-items">
						{ items.map( ( item ) => (
							<li key={ item.id } className="su-plan-item">
								<div className="su-plan-item__head">
									<span className="su-plan-item__field">
										{ item.field_label }
									</span>
									<StatusBadge
										tone={ jobItemTone( item.status ) }
									>
										{ jobItemStatusLabel( item.status ) }
									</StatusBadge>
								</div>
								<ObjectTitle object={ item.object } />
								{ item.reason_code &&
									item.status !== 'restored' && (
										<p>
											{ reasonText( item.reason_code ) }
										</p>
									) }
							</li>
						) ) }
					</ul>
				</section>
			) }

			{ terminal && (
				<div className="su-actions">
					{ job.counters.restored > 0 && job.restore_changeset_id && (
						<Button
							variant="secondary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () =>
								checkRestore(
									{
										type: 'changeset',
										changeset_id: job.restore_changeset_id!,
									},
									setActionError,
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
					<a
						className="components-button is-tertiary"
						href={ viewToUrl( history ) }
						onClick={ linkHandler( history ) }
					>
						{ __( 'Back to history', 'selective-undo' ) }
					</a>
				</div>
			) }
			{ ! terminal && error !== null && (
				<ErrorNotice error={ error } onRetry={ refresh } />
			) }
			{ job.status === 'queued' && job.mode === 'queued' && (
				<p className="su-muted">
					<Button
						variant="link"
						onClick={ () => navigate( { name: 'restores' } ) }
					>
						{ __( 'All restores', 'selective-undo' ) }
					</Button>
				</p>
			) }
		</section>
	);
}
