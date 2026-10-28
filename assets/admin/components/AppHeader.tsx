import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import { api } from '../api/client';
import type { Overview } from '../api/types';
import { ErrorNotice } from './Feedback';
import { exactDate, formatBytes } from '../lib/format';
import { blockerText } from '../lib/labels';
import { linkHandler, viewToUrl } from '../lib/router';

function captureText( capture: Overview[ 'capture' ] ): string {
	if ( capture.state === 'active' ) {
		return __( 'active', 'selective-undo' );
	}
	if ( capture.state === 'disabled' ) {
		return __( 'turned off', 'selective-undo' );
	}
	if ( capture.reason === 'quota_exceeded' ) {
		return __( 'paused — size limit reached', 'selective-undo' );
	}
	return __( 'paused', 'selective-undo' );
}

/**
 * The history was copied with the database from another site: keep it, or clear it
 * through the two-step deletion.
 * @param root0
 * @param root0.restored
 * @param root0.onKept
 */
function SiteMovedNotice( {
	restored,
	onKept,
}: {
	restored: boolean;
	onKept: () => void;
} ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< unknown >( null );
	// The two-step "Delete history" form is on the Storage tab.
	const removal = { name: 'settings' as const, tab: 'retention' };

	return (
		<Notice status="warning" isDismissible={ false }>
			<p>
				{ restored
					? __(
							'The database seems to have been restored from a backup: most items with history changed at once.',
							'selective-undo'
						)
					: __(
							'The history was copied from another site. It may list changes made there.',
							'selective-undo'
						) }
			</p>
			<p className="su-notice-actions">
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					accessibleWhenDisabled
					onClick={ () => {
						setBusy( true );
						setError( null );
						api.keepSiteHistory()
							.then( onKept )
							.catch( setError )
							.finally( () => setBusy( false ) );
					} }
				>
					{ __( 'Keep history', 'selective-undo' ) }
				</Button>{ ' ' }
				<Button
					variant="secondary"
					href={ viewToUrl( removal ) }
					onClick={ linkHandler( removal ) }
				>
					{ __( 'Clear history', 'selective-undo' ) }
				</Button>
			</p>
			{ error !== null && <ErrorNotice error={ error } /> }
		</Notice>
	);
}

/**
 * Never shows a green "everything is protected" when something is degraded.
 * @param root0
 * @param root0.overview
 * @param root0.onChanged
 */
export function AppHeader( {
	overview,
	onChanged,
}: {
	overview: Overview | null;
	onChanged: () => void;
} ) {
	const diagnostics = { name: 'settings' as const, tab: 'diagnostics' };

	return (
		<header className="su-header">
			<div className="su-header__titles">
				<h1 className="su-header__title">
					{ __( 'Selective Undo', 'selective-undo' ) }
				</h1>
				<p className="su-header__subtitle">
					{ __(
						'Change history and safe restore',
						'selective-undo'
					) }
				</p>
			</div>
			{ overview && (
				<dl className="su-header__facts">
					<div
						className={ `su-fact su-fact--${ overview.capture.state === 'active' ? 'ok' : 'warning' }` }
					>
						<dt>{ __( 'Recording', 'selective-undo' ) }</dt>
						<dd>{ captureText( overview.capture ) }</dd>
					</div>
					<div
						className={ `su-fact su-fact--${ overview.storage.level }` }
					>
						<dt>{ __( 'Storage', 'selective-undo' ) }</dt>
						<dd>
							{ sprintf(
								/* translators: 1: used size, 2: size limit. */
								__( '%1$s of %2$s', 'selective-undo' ),
								formatBytes( overview.storage.logical_bytes ),
								formatBytes( overview.storage.quota_bytes )
							) }
						</dd>
					</div>
					<div className="su-fact">
						<dt>{ __( 'Available', 'selective-undo' ) }</dt>
						<dd>
							{ overview.oldest_event_at
								? sprintf(
										/* translators: 1: number of days, 2: date of the oldest recorded change. */
										_n(
											'last %1$d day, since %2$s',
											'last %1$d days, since %2$s',
											overview.retention_days,
											'selective-undo'
										),
										overview.retention_days,
										exactDate( overview.oldest_event_at )
									)
								: sprintf(
										/* translators: %d: number of days. */
										_n(
											'last %d day',
											'last %d days',
											overview.retention_days,
											'selective-undo'
										),
										overview.retention_days
									) }
						</dd>
					</div>
					{ overview.restore_mode === 'compat' && (
						<div className="su-fact su-fact--warning">
							<dt>
								{ _x(
									'Restore',
									'noun: header label for the restore mode',
									'selective-undo'
								) }
							</dt>
							<dd>
								<a
									href={ viewToUrl( diagnostics ) }
									onClick={ linkHandler( diagnostics ) }
								>
									{ __(
										'compatibility mode',
										'selective-undo'
									) }
								</a>
							</dd>
						</div>
					) }
				</dl>
			) }
			{ overview?.site_moved && overview.user.can.manage_settings && (
				<SiteMovedNotice
					restored={
						overview.site_moved_reason === 'database_restored'
					}
					onKept={ onChanged }
				/>
			) }
			{ overview && overview.restore_blockers.length > 0 && (
				<Notice status="error" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: list of reasons. */
						__(
							'Restoring is unavailable: %s. History is still recorded.',
							'selective-undo'
						),
						overview.restore_blockers
							.map( blockerText )
							.join( '; ' )
					) }{ ' ' }
					<a
						href={ viewToUrl( diagnostics ) }
						onClick={ linkHandler( diagnostics ) }
					>
						{ __( 'Diagnostics', 'selective-undo' ) }
					</a>
				</Notice>
			) }
			{ overview && overview.gaps_last_24h > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The history has gaps in the last 24 hours.',
						'selective-undo'
					) }{ ' ' }
					<a
						href={ viewToUrl( diagnostics ) }
						onClick={ linkHandler( diagnostics ) }
					>
						{ __( 'Details', 'selective-undo' ) }
					</a>
				</Notice>
			) }
			{ overview && overview.storage.level !== 'ok' && (
				<Notice
					status={
						overview.storage.level === 'critical'
							? 'error'
							: 'warning'
					}
					isDismissible={ false }
				>
					{ overview.storage.level === 'critical'
						? __(
								'The history is almost at its size limit. The oldest history will be removed or recording will pause.',
								'selective-undo'
							)
						: __(
								'The history uses more than 80% of its size limit.',
								'selective-undo'
							) }
				</Notice>
			) }
		</header>
	);
}
