import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api } from '../api/client';
import type { RestoreJob } from '../api/types';
import {
	EmptyState,
	ErrorNotice,
	LoadMore,
	Loading,
} from '../components/Feedback';
import { StatusBadge } from '../components/StatusBadge';
import { ViewHeading } from '../components/ViewHeading';
import { exactDate, relativeDate } from '../lib/format';
import { jobStatusLabel, jobStatusTone } from '../lib/labels';
import { linkHandler, viewToUrl } from '../lib/router';

export function RestoresPage() {
	const [ items, setItems ] = useState< RestoreJob[] >( [] );
	const [ cursor, setCursor ] = useState< string | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ more, setMore ] = useState( false );
	const [ error, setError ] = useState< unknown >( null );

	useEffect( () => {
		const controller = new AbortController();
		api.jobs( null, controller.signal )
			.then( ( page ) => {
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
	}, [] );

	const loadMore = async () => {
		setMore( true );
		try {
			const page = await api.jobs( cursor );
			setItems( ( prev ) => [ ...prev, ...page.items ] );
			setCursor( page.next_cursor );
		} catch ( e: unknown ) {
			setError( e );
		} finally {
			setMore( false );
		}
	};

	return (
		<section className="su-panel">
			<ViewHeading focus={ false }>
				{ __( 'Restores', 'selective-undo' ) }
			</ViewHeading>
			{ error !== null && <ErrorNotice error={ error } /> }
			{ loading && <Loading /> }
			{ ! loading && items.length === 0 && ! error && (
				<EmptyState
					title={ __( 'No restores yet.', 'selective-undo' ) }
				/>
			) }
			{ items.length > 0 && (
				<ul className="su-job-list">
					{ items.map( ( job ) => {
						const view = { name: 'job' as const, id: job.id };
						return (
							<li key={ job.id } className="su-card">
								<p className="su-card__title">
									<a
										href={ viewToUrl( view ) }
										onClick={ linkHandler( view ) }
									>
										{ sprintf(
											/* translators: 1: number restored, 2: total number of fields. */
											__(
												'%1$d of %2$d fields restored',
												'selective-undo'
											),
											job.counters.restored,
											job.counters.total
										) }
									</a>
								</p>
								<StatusBadge
									tone={ jobStatusTone( job.status ) }
								>
									{ jobStatusLabel( job.status ) }
								</StatusBadge>
								<p className="su-muted">
									{ job.actor?.name } ·{ ' ' }
									<time
										dateTime={ job.created_at }
										title={ exactDate( job.created_at ) }
									>
										{ relativeDate( job.created_at ) }
									</time>
								</p>
							</li>
						);
					} ) }
				</ul>
			) }
			{ cursor && <LoadMore onClick={ loadMore } busy={ more } /> }
		</section>
	);
}
