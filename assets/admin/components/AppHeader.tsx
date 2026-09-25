import { Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import type { Overview } from '../api/types';
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
 * Never shows a green "everything is protected" when something is degraded.
 * @param root0
 * @param root0.overview
 */
export function AppHeader( { overview }: { overview: Overview | null } ) {
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
										__(
											'last %1$d days, since %2$s',
											'selective-undo'
										),
										overview.retention_days,
										exactDate( overview.oldest_event_at )
									)
								: sprintf(
										/* translators: %d: number of days. */
										__( 'last %d days', 'selective-undo' ),
										overview.retention_days
									) }
						</dd>
					</div>
				</dl>
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
