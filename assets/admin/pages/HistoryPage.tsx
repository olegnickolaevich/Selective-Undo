import { Button, SearchControl, SelectControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api } from '../api/client';
import type { ChangesetSummary, Overview } from '../api/types';
import {
	EmptyState,
	ErrorNotice,
	LoadMore,
	Loading,
} from '../components/Feedback';
import { StatusBadge } from '../components/StatusBadge';
import { ViewHeading } from '../components/ViewHeading';
import { exactDate, itemsCount, relativeDate } from '../lib/format';
import { changesetTitle, displayTitle, sourceLabel } from '../lib/labels';
import { linkHandler, viewToUrl } from '../lib/router';

interface Filters {
	search: string;
	period: '' | '1' | '7';
	actor: '' | 'me';
	subtype: string;
	kind: '' | 'edit' | 'restore' | 'operation' | 'autosave';
}

const EMPTY: Filters = {
	search: '',
	period: '',
	actor: '',
	subtype: '',
	kind: '',
};

function readFilters(): Filters {
	const p = new URLSearchParams( window.location.search );
	return {
		search: p.get( 's' ) ?? '',
		period: ( p.get( 'period' ) as Filters[ 'period' ] ) ?? '',
		actor: p.get( 'actor' ) === 'me' ? 'me' : '',
		subtype: p.get( 'subtype' ) ?? '',
		kind: ( p.get( 'kind' ) as Filters[ 'kind' ] ) ?? '',
	};
}

function writeFilters( f: Filters ): void {
	const url = new URL( window.location.href );
	const map: Record< string, string > = {
		s: f.search,
		period: f.period,
		actor: f.actor,
		subtype: f.subtype,
		kind: f.kind,
	};
	for ( const [ k, v ] of Object.entries( map ) ) {
		if ( v ) {
			url.searchParams.set( k, v );
		} else {
			url.searchParams.delete( k );
		}
	}
	window.history.replaceState( window.history.state, '', url.toString() );
}

function Row( { cs }: { cs: ChangesetSummary } ) {
	const view = { name: 'changeset' as const, id: cs.id };
	const first = cs.objects[ 0 ];

	return (
		<tr>
			<td>
				<a
					className="su-row-title"
					href={ viewToUrl( view ) }
					onClick={ linkHandler( view ) }
				>
					{ changesetTitle( cs ) }
				</a>
				<span className="su-muted su-block">
					{ sourceLabel( cs.source ) }
				</span>
			</td>
			<td>
				{ cs.object_count === 1 && first
					? displayTitle( first.title, first.id )
					: itemsCount( cs.object_count ) }
				{ cs.hidden_objects > 0 && (
					<span className="su-muted su-block">
						{ __( '+ items you cannot view', 'undoly' ) }
					</span>
				) }
			</td>
			<td>
				{ cs.fields.length > 0
					? cs.fields.map( ( f ) => f.label ).join( ', ' )
					: '—' }
			</td>
			<td>{ cs.actor?.name ?? __( 'System', 'undoly' ) }</td>
			<td>
				<time
					dateTime={ cs.created_at }
					title={ exactDate( cs.created_at ) }
				>
					{ relativeDate( cs.created_at ) }
				</time>
			</td>
			<td>
				{ cs.has_limitations ? (
					<StatusBadge tone="warning">
						{ __( 'Partly restorable', 'undoly' ) }
					</StatusBadge>
				) : (
					<StatusBadge tone="neutral">
						{ __( 'History available', 'undoly' ) }
					</StatusBadge>
				) }
			</td>
		</tr>
	);
}

function Card( { cs }: { cs: ChangesetSummary } ) {
	const view = { name: 'changeset' as const, id: cs.id };

	return (
		<li className="su-card">
			<p className="su-card__title">{ changesetTitle( cs ) }</p>
			<p className="su-muted">
				{ cs.actor?.name ?? __( 'System', 'undoly' ) } ·{ ' ' }
				<time
					dateTime={ cs.created_at }
					title={ exactDate( cs.created_at ) }
				>
					{ relativeDate( cs.created_at ) }
				</time>
			</p>
			{ cs.fields.length > 0 && (
				<p>
					{ __( 'Changed:', 'undoly' ) }{ ' ' }
					{ cs.fields
						.map( ( f ) => f.label.toLowerCase() )
						.join( ', ' ) }
				</p>
			) }
			<a
				className="components-button is-secondary"
				href={ viewToUrl( view ) }
				onClick={ linkHandler( view ) }
			>
				{ __( 'View changes', 'undoly' ) }
			</a>
		</li>
	);
}

export function HistoryPage( { overview }: { overview: Overview | null } ) {
	const [ filters, setFilters ] = useState< Filters >( readFilters );
	const [ items, setItems ] = useState< ChangesetSummary[] >( [] );
	const [ cursor, setCursor ] = useState< string | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ loadingMore, setLoadingMore ] = useState( false );
	const [ error, setError ] = useState< unknown >( null );
	const [ searchDraft, setSearchDraft ] = useState( filters.search );

	const query = ( f: Filters, next: string | null ) => ( {
		search: f.search || null,
		from: f.period
			? new Date(
					Date.now() - Number( f.period ) * 86400000
				).toISOString()
			: null,
		actor: f.actor || null,
		subtype: f.subtype || null,
		kind: f.kind || null,
		cursor: next,
		limit: 25,
	} );

	useEffect( () => {
		const controller = new AbortController();
		setLoading( true );
		setError( null );
		writeFilters( filters );
		api.changesets( query( filters, null ), controller.signal )
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
	}, [ filters ] );

	useEffect( () => {
		const timer = setTimeout( () => {
			if ( searchDraft !== filters.search ) {
				setFilters( ( f ) => ( { ...f, search: searchDraft } ) );
			}
		}, 400 );
		return () => clearTimeout( timer );
	}, [ searchDraft, filters.search ] );

	const more = async () => {
		setLoadingMore( true );
		try {
			const page = await api.changesets( query( filters, cursor ) );
			setItems( ( prev ) => [ ...prev, ...page.items ] );
			setCursor( page.next_cursor );
		} catch ( e: unknown ) {
			setError( e );
		} finally {
			setLoadingMore( false );
		}
	};

	const set =
		< K extends keyof Filters >( key: K ) =>
		( value: string ) =>
			setFilters( ( f ) => ( { ...f, [ key ]: value } ) );
	const types = overview?.tracked_post_types ?? [];
	const filtered = JSON.stringify( filters ) !== JSON.stringify( EMPTY );

	return (
		<section className="su-panel">
			<ViewHeading focus={ false }>
				{ __( 'History', 'undoly' ) }
			</ViewHeading>
			<div className="su-filters" role="search">
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search by current title or ID', 'undoly' ) }
					value={ searchDraft }
					onChange={ ( v: string ) => setSearchDraft( v ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Period', 'undoly' ) }
					value={ filters.period }
					options={ [
						{
							value: '',
							label: __( 'Whole history', 'undoly' ),
						},
						{
							value: '1',
							label: __( 'Last 24 hours', 'undoly' ),
						},
						{
							value: '7',
							label: __( 'Last 7 days', 'undoly' ),
						},
					] }
					onChange={ set( 'period' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Author', 'undoly' ) }
					value={ filters.actor }
					options={ [
						{
							value: '',
							label: __( 'Everyone', 'undoly' ),
						},
						{
							value: 'me',
							label: __( 'My changes', 'undoly' ),
						},
					] }
					onChange={ set( 'actor' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Content type', 'undoly' ) }
					value={ filters.subtype }
					options={ [
						{
							value: '',
							label: __( 'All types', 'undoly' ),
						},
						...types.map( ( t ) => ( { value: t, label: t } ) ),
					] }
					onChange={ set( 'subtype' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Operation', 'undoly' ) }
					value={ filters.kind }
					options={ [
						{
							value: '',
							label: __(
								'Edits, restores and operations',
								'undoly'
							),
						},
						{
							value: 'edit',
							label: __( 'Edits', 'undoly' ),
						},
						{
							value: 'restore',
							label: __( 'Restores', 'undoly' ),
						},
						{
							value: 'operation',
							label: __( 'Integrated operations', 'undoly' ),
						},
						{
							value: 'autosave',
							label: __( 'Autosaved drafts', 'undoly' ),
						},
					] }
					onChange={ set( 'kind' ) }
				/>
				{ filtered && (
					<Button
						variant="tertiary"
						onClick={ () => {
							setSearchDraft( '' );
							setFilters( EMPTY );
						} }
					>
						{ __( 'Clear filters', 'undoly' ) }
					</Button>
				) }
			</div>

			{ error !== null && (
				<ErrorNotice
					error={ error }
					onRetry={ () => setFilters( { ...filters } ) }
				/>
			) }
			{ loading && <Loading /> }
			{ ! loading && ! error && items.length === 0 && (
				<EmptyState
					title={
						filtered
							? __( 'Nothing matches these filters.', 'undoly' )
							: __( 'No changes recorded yet.', 'undoly' )
					}
				>
					{ ! filtered && (
						<p>
							{ __(
								'Edits of titles, content, excerpts and order of tracked content will appear here.',
								'undoly'
							) }
						</p>
					) }
				</EmptyState>
			) }
			{ ! loading && items.length > 0 && (
				<>
					<table className="su-table widefat striped">
						<caption className="screen-reader-text">
							{ __(
								'Recorded operations, newest first',
								'undoly'
							) }
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Operation', 'undoly' ) }
								</th>
								<th scope="col">{ __( 'Items', 'undoly' ) }</th>
								<th scope="col">
									{ __( 'Changes', 'undoly' ) }
								</th>
								<th scope="col">
									{ __( 'Author', 'undoly' ) }
								</th>
								<th scope="col">{ __( 'Time', 'undoly' ) }</th>
								<th scope="col">{ __( 'Notes', 'undoly' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ items.map( ( cs ) => (
								<Row key={ cs.id } cs={ cs } />
							) ) }
						</tbody>
					</table>
					<ul className="su-cards">
						{ items.map( ( cs ) => (
							<Card key={ cs.id } cs={ cs } />
						) ) }
					</ul>
					{ cursor && (
						<LoadMore onClick={ more } busy={ loadingMore } />
					) }
				</>
			) }
		</section>
	);
}
