import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, _x, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { api } from '../api/client';
import type {
	Gap,
	HealthCheck,
	Overview,
	PurgePreview,
	Settings,
	SettingsPayload,
} from '../api/types';
import { ErrorNotice, Loading } from '../components/Feedback';
import { StatusBadge } from '../components/StatusBadge';
import { ViewHeading } from '../components/ViewHeading';
import { useAsync } from '../hooks/useAsync';
import { exactDate, formatBytes } from '../lib/format';
import { displayTitle, gapReasonText, type Tone } from '../lib/labels';
import { linkHandler, viewToUrl } from '../lib/router';

const TABS = [
	{ key: 'recording', label: () => __( 'What to record', 'selective-undo' ) },
	{ key: 'retention', label: () => __( 'Storage', 'selective-undo' ) },
	{ key: 'access', label: () => __( 'Access', 'selective-undo' ) },
	{ key: 'diagnostics', label: () => __( 'Diagnostics', 'selective-undo' ) },
	{ key: 'removal', label: () => __( 'Removal', 'selective-undo' ) },
];

function download( filename: string, content: string, type: string ) {
	const url = URL.createObjectURL( new Blob( [ content ], { type } ) );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = filename;
	document.body.appendChild( a );
	a.click();
	a.remove();
	URL.revokeObjectURL( url );
}

function SaveBar( {
	dirty,
	busy,
	onSave,
}: {
	dirty: boolean;
	busy: boolean;
	onSave: () => void;
} ) {
	return (
		<div className="su-actions">
			<Button
				variant="primary"
				onClick={ onSave }
				disabled={ ! dirty || busy }
				isBusy={ busy }
				accessibleWhenDisabled
			>
				{ __( 'Save changes', 'selective-undo' ) }
			</Button>
		</div>
	);
}

function RecordingTab( {
	payload,
	onSave,
}: {
	payload: SettingsPayload;
	onSave: ( s: Partial< Settings > ) => Promise< void >;
} ) {
	const [ draft, setDraft ] = useState( payload.settings );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => setDraft( payload.settings ), [ payload.settings ] );
	const dirty =
		JSON.stringify( draft ) !== JSON.stringify( payload.settings );
	const toggleType = ( name: string, checked: boolean ) =>
		setDraft( ( d ) => ( {
			...d,
			tracked_post_types: checked
				? [ ...d.tracked_post_types, name ]
				: d.tracked_post_types.filter( ( t ) => t !== name ),
		} ) );

	return (
		<>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Record changes', 'selective-undo' ) }
				help={ __(
					'When turned off, changes are not recorded and cannot be undone later. The period appears as a gap in the history.',
					'selective-undo'
				) }
				checked={ draft.capture_enabled }
				onChange={ ( v: boolean ) =>
					setDraft( { ...draft, capture_enabled: v } )
				}
			/>
			<fieldset className="su-fieldset">
				<legend>{ __( 'Content types', 'selective-undo' ) }</legend>
				<p className="su-muted">
					{ __(
						'Title, content, excerpt and order of existing items are recorded.',
						'selective-undo'
					) }
				</p>
				{ payload.post_types.map( ( t ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ t.name }
						label={ `${ t.label } (${ t.name })` }
						checked={ draft.tracked_post_types.includes( t.name ) }
						onChange={ ( checked: boolean ) =>
							toggleType( t.name, checked )
						}
					/>
				) ) }
			</fieldset>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Merge autosaves of drafts', 'selective-undo' ) }
				help={ __(
					'Autosaves of a draft by its author become one entry per editing session instead of one entry per minute.',
					'selective-undo'
				) }
				checked={ draft.coalesce_autosaves }
				onChange={ ( v: boolean ) =>
					setDraft( { ...draft, coalesce_autosaves: v } )
				}
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Create a WordPress revision after a restore',
					'selective-undo'
				) }
				checked={ draft.create_revision_after_restore }
				onChange={ ( v: boolean ) =>
					setDraft( { ...draft, create_revision_after_restore: v } )
				}
			/>
			<details className="su-details">
				<summary>
					{ __(
						'What is not recorded or restored',
						'selective-undo'
					) }
				</summary>
				<p>
					{ __(
						'Deleted items, creation of new items, status, slug, author, dates, custom fields, comments, users, settings, files, orders and payments. Changes made directly in the database, bypassing WordPress, are detected but not recorded. Emails, webhooks and other external effects are never undone.',
						'selective-undo'
					) }
				</p>
			</details>
			<SaveBar
				dirty={ dirty }
				busy={ busy }
				onSave={ async () => {
					setBusy( true );
					await onSave( {
						capture_enabled: draft.capture_enabled,
						tracked_post_types: draft.tracked_post_types,
						coalesce_autosaves: draft.coalesce_autosaves,
						create_revision_after_restore:
							draft.create_revision_after_restore,
					} );
					setBusy( false );
				} }
			/>
		</>
	);
}

function PurgeForm( { canPurge }: { canPurge: boolean } ) {
	const [ scope, setScope ] = useState< 'object' | 'before' | 'all' >(
		'object'
	);
	const [ objectId, setObjectId ] = useState( '' );
	const [ before, setBefore ] = useState( '' );
	const [ preview, setPreview ] = useState< PurgePreview | null >( null );
	const [ confirmation, setConfirmation ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< unknown >( null );
	const [ result, setResult ] = useState< string | null >( null );

	if ( ! canPurge ) {
		return null;
	}

	const doPreview = async () => {
		setBusy( true );
		setError( null );
		setResult( null );
		try {
			setPreview(
				await api.purgePreview( {
					scope,
					object_id:
						scope === 'object' ? Number( objectId ) : undefined,
					before:
						scope === 'before' && before
							? new Date( before ).toISOString()
							: undefined,
				} )
			);
		} catch ( e: unknown ) {
			setError( e );
		} finally {
			setBusy( false );
		}
	};

	const doPurge = async () => {
		if ( ! preview ) {
			return;
		}
		setBusy( true );
		try {
			const r = await api.purge( preview.token, confirmation );
			const message = sprintf(
				/* translators: %d: number of deleted changes. */
				_n(
					'Deleted %d recorded change.',
					'Deleted %d recorded changes.',
					r.deleted_changes,
					'selective-undo'
				),
				r.deleted_changes
			);
			setResult(
				r.remaining
					? `${ message } ${ __( 'Some history is left; run the deletion again.', 'selective-undo' ) }`
					: message
			);
			speak( message );
			setPreview( null );
			setConfirmation( '' );
		} catch ( e: unknown ) {
			setError( e );
		} finally {
			setBusy( false );
		}
	};

	return (
		<fieldset className="su-fieldset">
			<legend>{ __( 'Delete history', 'selective-undo' ) }</legend>
			<p className="su-muted">
				{ __(
					'Text removed from pages can stay in the history until it expires. Delete it here, for example after removing personal data.',
					'selective-undo'
				) }
			</p>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'What to delete', 'selective-undo' ) }
				value={ scope }
				options={ [
					{
						value: 'object',
						label: __( 'History of one item', 'selective-undo' ),
					},
					{
						value: 'before',
						label: __(
							'History older than a date',
							'selective-undo'
						),
					},
					{
						value: 'all',
						label: __( 'All history', 'selective-undo' ),
					},
				] }
				onChange={ ( v: string ) => {
					setScope( v as typeof scope );
					setPreview( null );
				} }
			/>
			{ scope === 'object' && (
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Item ID', 'selective-undo' ) }
					type="number"
					min={ 1 }
					value={ objectId }
					onChange={ ( v: string ) => {
						setObjectId( v );
						setPreview( null );
					} }
				/>
			) }
			{ scope === 'before' && (
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Older than', 'selective-undo' ) }
					type="date"
					value={ before }
					onChange={ ( v: string ) => {
						setBefore( v );
						setPreview( null );
					} }
				/>
			) }
			{ error !== null && <ErrorNotice error={ error } /> }
			{ result && (
				<Notice status="success" isDismissible={ false }>
					{ result }
				</Notice>
			) }
			{ ! preview ? (
				<Button
					variant="secondary"
					onClick={ doPreview }
					isBusy={ busy }
					disabled={
						busy ||
						( scope === 'object' && ! objectId ) ||
						( scope === 'before' && ! before )
					}
				>
					{ __( 'Preview deletion', 'selective-undo' ) }
				</Button>
			) : (
				<div className="su-danger-zone">
					<p>
						{ sprintf(
							/* translators: %d: number of recorded changes. */
							_n(
								'%d recorded change will be deleted permanently.',
								'%d recorded changes will be deleted permanently.',
								preview.changes,
								'selective-undo'
							),
							preview.changes
						) }
						{ preview.protected_changesets > 0 &&
							' ' +
								sprintf(
									/* translators: %d: number of operations. */
									_n(
										'%d operation is kept because an open preview or a running restore uses it.',
										'%d operations are kept because an open preview or a running restore uses them.',
										preview.protected_changesets,
										'selective-undo'
									),
									preview.protected_changesets
								) }
					</p>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Type DELETE to confirm',
							'selective-undo'
						) }
						value={ confirmation }
						onChange={ setConfirmation }
					/>
					<Button
						variant="primary"
						isDestructive
						onClick={ doPurge }
						isBusy={ busy }
						disabled={ busy || confirmation !== 'DELETE' }
						accessibleWhenDisabled
					>
						{ __( 'Delete permanently', 'selective-undo' ) }
					</Button>{ ' ' }
					<Button
						variant="tertiary"
						onClick={ () => setPreview( null ) }
					>
						{ __( 'Cancel', 'selective-undo' ) }
					</Button>
				</div>
			) }
		</fieldset>
	);
}

function RetentionTab( {
	payload,
	overview,
	onSave,
}: {
	payload: SettingsPayload;
	overview: Overview | null;
	onSave: ( s: Partial< Settings > ) => Promise< void >;
} ) {
	const [ draft, setDraft ] = useState( payload.settings );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => setDraft( payload.settings ), [ payload.settings ] );
	const dirty =
		JSON.stringify( draft ) !== JSON.stringify( payload.settings );
	const max = payload.limits.retention_max_days;

	return (
		<>
			{ overview && (
				<dl className="su-meta">
					<div>
						<dt>{ __( 'Used', 'selective-undo' ) }</dt>
						<dd>
							{ formatBytes( overview.storage.logical_bytes ) } /{ ' ' }
							{ formatBytes( overview.storage.quota_bytes ) }
						</dd>
					</div>
					<div>
						<dt>{ __( 'On disk', 'selective-undo' ) }</dt>
						<dd>
							{ formatBytes( overview.storage.physical_bytes ) }
						</dd>
					</div>
					<div>
						<dt>
							{ __(
								'Oldest available change',
								'selective-undo'
							) }
						</dt>
						<dd>
							{ overview.oldest_event_at
								? exactDate( overview.oldest_event_at )
								: '—' }
						</dd>
					</div>
				</dl>
			) }
			<p className="su-muted">
				{ __(
					'The database file does not shrink automatically after deletions; freed space is reused for new history.',
					'selective-undo'
				) }
			</p>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="number"
				min={ 1 }
				max={ max }
				label={ __( 'Keep history for (days)', 'selective-undo' ) }
				help={ sprintf(
					/* translators: %d: maximum number of days. */
					_n(
						'From 1 to %d day. The size limit below may remove older history earlier.',
						'From 1 to %d days. The size limit below may remove older history earlier.',
						max,
						'selective-undo'
					),
					max
				) }
				value={ String( draft.retention_days ) }
				onChange={ ( v: string ) =>
					setDraft( { ...draft, retention_days: Number( v ) } )
				}
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="number"
				min={ payload.limits.quota_min_mb }
				max={ payload.limits.quota_max_mb }
				label={ __( 'History size limit (MB)', 'selective-undo' ) }
				value={ String( draft.quota_mb ) }
				onChange={ ( v: string ) =>
					setDraft( { ...draft, quota_mb: Number( v ) } )
				}
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Remove the oldest history when the limit is reached',
					'selective-undo'
				) }
				help={ __(
					'When off, recording pauses at the limit instead.',
					'selective-undo'
				) }
				checked={ draft.evict_oldest_on_quota }
				onChange={ ( v: boolean ) =>
					setDraft( { ...draft, evict_oldest_on_quota: v } )
				}
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				type="number"
				min={ 16 }
				max={ 65536 }
				label={ __( 'Largest value to store (KB)', 'selective-undo' ) }
				help={ sprintf(
					/* translators: %s: size. */
					__(
						'Effective limit on this server: %s. Larger values are listed in the history but cannot be restored.',
						'selective-undo'
					),
					formatBytes( payload.limits.effective_max_value_bytes )
				) }
				value={ String( draft.max_value_kb ) }
				onChange={ ( v: string ) =>
					setDraft( { ...draft, max_value_kb: Number( v ) } )
				}
			/>
			<SaveBar
				dirty={ dirty }
				busy={ busy }
				onSave={ async () => {
					setBusy( true );
					await onSave( {
						retention_days: draft.retention_days,
						quota_mb: draft.quota_mb,
						evict_oldest_on_quota: draft.evict_oldest_on_quota,
						max_value_kb: draft.max_value_kb,
					} );
					setBusy( false );
				} }
			/>
			<PurgeForm
				canPurge={ overview?.user.can.manage_retention ?? false }
			/>
		</>
	);
}

function AccessTab( {
	payload,
	onSaveRoles,
}: {
	payload: SettingsPayload;
	onSaveRoles: (
		roles: Record< string, Record< string, boolean > >
	) => Promise< void >;
} ) {
	const [ draft, setDraft ] = useState( payload.roles );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => setDraft( payload.roles ), [ payload.roles ] );
	const dirty = JSON.stringify( draft ) !== JSON.stringify( payload.roles );

	return (
		<>
			<p className="su-muted">
				{ __(
					'Each permission is granted separately. Users additionally need permission to edit an item to see or restore its history.',
					'selective-undo'
				) }
			</p>
			<div className="su-table-scroll">
				<table className="su-table su-table--matrix widefat">
					<caption className="screen-reader-text">
						{ __( 'Permissions by role', 'selective-undo' ) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Role', 'selective-undo' ) }
							</th>
							{ payload.capabilities.map( ( c ) => (
								<th scope="col" key={ c.key }>
									{ c.label }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ Object.entries( draft ).map( ( [ slug, role ] ) => (
							<tr key={ slug }>
								<th scope="row">{ role.name }</th>
								{ payload.capabilities.map( ( c ) => (
									<td key={ c.key }>
										<input
											type="checkbox"
											id={ `su-cap-${ slug }-${ c.key }` }
											checked={ !! role.caps[ c.key ] }
											disabled={
												slug === 'administrator' &&
												c.key ===
													'sundo_manage_settings'
											}
											onChange={ ( e ) => {
												const checked =
													e.currentTarget.checked;
												setDraft( ( d ) => ( {
													...d,
													[ slug ]: {
														...d[ slug ]!,
														caps: {
															...d[ slug ]!.caps,
															[ c.key ]: checked,
														},
													},
												} ) );
											} }
										/>
										<label
											htmlFor={ `su-cap-${ slug }-${ c.key }` }
											className="screen-reader-text"
										>
											{ sprintf(
												/* translators: 1: user role name. 2: permission name. */
												_x(
													'%1$s: %2$s',
													'role: permission',
													'selective-undo'
												),
												role.name,
												c.label
											) }
										</label>
									</td>
								) ) }
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			<SaveBar
				dirty={ dirty }
				busy={ busy }
				onSave={ async () => {
					setBusy( true );
					const roles: Record<
						string,
						Record< string, boolean >
					> = {};
					for ( const [ slug, role ] of Object.entries( draft ) ) {
						roles[ slug ] = role.caps;
					}
					await onSaveRoles( roles );
					setBusy( false );
				} }
			/>
		</>
	);
}

const CHECK_TONE: Record< HealthCheck[ 'status' ], Tone > = {
	ok: 'success',
	info: 'info',
	warning: 'warning',
	error: 'error',
};

function DiagnosticsTab( { overview }: { overview: Overview | null } ) {
	const health = useAsync( ( signal ) => api.health( signal ), [] );
	const gaps = useAsync< { items: Gap[] } >(
		( signal ) => api.gaps( signal ),
		[]
	);
	const [ error, setError ] = useState< unknown >( null );

	return (
		<>
			{ health.loading && <Loading /> }
			{ health.error !== null && (
				<ErrorNotice error={ health.error } onRetry={ health.reload } />
			) }
			{ health.data && (
				<ul className="su-checks">
					{ health.data.checks.map( ( c ) => (
						<li key={ c.id } className="su-check">
							<StatusBadge tone={ CHECK_TONE[ c.status ] }>
								{ c.label }
							</StatusBadge>
							<p>{ c.message }</p>
						</li>
					) ) }
				</ul>
			) }
			<h3 className="su-subtitle">
				{ __( 'History gaps', 'selective-undo' ) }
			</h3>
			{ gaps.data && gaps.data.items.length === 0 && (
				<p className="su-muted">
					{ __( 'No gaps recorded.', 'selective-undo' ) }
				</p>
			) }
			{ gaps.data && gaps.data.items.length > 0 && (
				<ul className="su-gaps">
					{ gaps.data.items.map( ( g ) => {
						const view = g.object
							? { name: 'object' as const, id: g.object.id }
							: null;
						return (
							<li key={ g.id }>
								<p>{ gapReasonText( g.reason ) }</p>
								<p className="su-muted">
									{ view && g.object && (
										<>
											<a
												href={ viewToUrl( view ) }
												onClick={ linkHandler( view ) }
											>
												{ displayTitle(
													g.object.title,
													g.object.id
												) }
											</a>{ ' ' }
											·{ ' ' }
										</>
									) }
									{ g.ended_at === null &&
									g.scope === 'global'
										? sprintf(
												/* translators: %s: date. */
												__(
													'since %s, ongoing',
													'selective-undo'
												),
												exactDate( g.started_at )
											)
										: exactDate( g.last_seen_at ) }
									{ g.occurrences > 1 &&
										` · ×${ g.occurrences }` }
								</p>
							</li>
						);
					} ) }
				</ul>
			) }
			{ error !== null && <ErrorNotice error={ error } /> }
			<div className="su-actions">
				<Button
					variant="secondary"
					onClick={ async () => {
						try {
							const data = await api.diagnostics();
							download(
								`selective-undo-diagnostics-${ Date.now() }.json`,
								JSON.stringify( data, null, 2 ),
								'application/json'
							);
						} catch ( e: unknown ) {
							setError( e );
						}
					} }
				>
					{ __(
						'Download diagnostics (no content)',
						'selective-undo'
					) }
				</Button>
				{ overview?.user.can.export && (
					<Button
						variant="secondary"
						onClick={ async () => {
							try {
								const file = await api.exportHistory();
								download(
									file.filename,
									file.content,
									'text/csv'
								);
							} catch ( e: unknown ) {
								setError( e );
							}
						} }
					>
						{ __(
							'Export history list (CSV, no values)',
							'selective-undo'
						) }
					</Button>
				) }
			</div>
		</>
	);
}

function RemovalTab( {
	payload,
	onSave,
}: {
	payload: SettingsPayload;
	onSave: ( s: Partial< Settings > ) => Promise< void >;
} ) {
	const [ value, setValue ] = useState(
		payload.settings.delete_data_on_uninstall
	);
	const [ busy, setBusy ] = useState( false );

	return (
		<>
			<p>
				{ __(
					'Deactivating the plugin keeps the history. Deleting the plugin keeps the history too, unless you allow it below.',
					'selective-undo'
				) }
			</p>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Delete all history and settings when the plugin is deleted',
					'selective-undo'
				) }
				checked={ value }
				onChange={ setValue }
			/>
			{ value && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'When the plugin is deleted from the Plugins screen, the history is destroyed and cannot be recovered.',
						'selective-undo'
					) }
				</Notice>
			) }
			<SaveBar
				dirty={ value !== payload.settings.delete_data_on_uninstall }
				busy={ busy }
				onSave={ async () => {
					setBusy( true );
					await onSave( { delete_data_on_uninstall: value } );
					setBusy( false );
				} }
			/>
		</>
	);
}

export function SettingsPage( {
	tab,
	overview,
	onChanged,
}: {
	tab: string;
	overview: Overview | null;
	onChanged: () => void;
} ) {
	const { data, error, loading, reload } = useAsync< SettingsPayload >(
		( signal ) => api.settings( signal ),
		[]
	);
	const [ payload, setPayload ] = useState< SettingsPayload | null >( null );
	const [ saveError, setSaveError ] = useState< unknown >( null );
	const [ saved, setSaved ] = useState( false );
	useEffect( () => setPayload( data ), [ data ] );

	const current = TABS.some( ( t ) => t.key === tab ) ? tab : 'recording';

	const save = async (
		settings?: Partial< Settings >,
		roles?: Record< string, Record< string, boolean > >
	) => {
		setSaveError( null );
		setSaved( false );
		try {
			setPayload( await api.saveSettings( settings, roles ) );
			setSaved( true );
			speak( __( 'Settings saved.', 'selective-undo' ) );
			onChanged();
		} catch ( e: unknown ) {
			setSaveError( e );
		}
	};

	return (
		<section className="su-panel">
			<ViewHeading focus={ false }>
				{ __( 'Settings', 'selective-undo' ) }
			</ViewHeading>
			<nav
				className="su-subnav"
				aria-label={ __( 'Settings sections', 'selective-undo' ) }
			>
				<ul>
					{ TABS.map( ( t ) => {
						const view = { name: 'settings' as const, tab: t.key };
						return (
							<li key={ t.key }>
								<a
									href={ viewToUrl( view ) }
									onClick={ linkHandler( view ) }
									aria-current={
										t.key === current ? 'page' : undefined
									}
								>
									{ t.label() }
								</a>
							</li>
						);
					} ) }
				</ul>
			</nav>
			{ saved && (
				<Notice status="success" onRemove={ () => setSaved( false ) }>
					{ __( 'Settings saved.', 'selective-undo' ) }
				</Notice>
			) }
			{ saveError !== null && <ErrorNotice error={ saveError } /> }
			{ loading && ! payload && <Loading /> }
			{ error !== null && (
				<ErrorNotice error={ error } onRetry={ reload } />
			) }
			{ payload && current === 'recording' && (
				<RecordingTab
					payload={ payload }
					onSave={ ( s ) => save( s ) }
				/>
			) }
			{ payload && current === 'retention' && (
				<RetentionTab
					payload={ payload }
					overview={ overview }
					onSave={ ( s ) => save( s ) }
				/>
			) }
			{ payload && current === 'access' && (
				<AccessTab
					payload={ payload }
					onSaveRoles={ ( r ) => save( undefined, r ) }
				/>
			) }
			{ current === 'diagnostics' && (
				<DiagnosticsTab overview={ overview } />
			) }
			{ payload && current === 'removal' && (
				<RemovalTab payload={ payload } onSave={ ( s ) => save( s ) } />
			) }
		</section>
	);
}
