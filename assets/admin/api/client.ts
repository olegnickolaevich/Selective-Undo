import apiFetch, { type APIFetchOptions } from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import type {
	ApiErrorBody,
	ChangesetDetail,
	ChangesetSummary,
	DiffChunk,
	Gap,
	HealthCheck,
	JobItem,
	ObjectTimeline,
	Overview,
	Page,
	PlanSelection,
	PurgePreview,
	RestoreJob,
	RestorePlan,
	Settings,
	SettingsPayload,
	Uuid,
} from './types';

/*
 * WordPress core configures the REST root and the nonce for wp-api-fetch
 * (createRootURLMiddleware / createNonceMiddleware inline scripts).
 */
const NS = '/selective-undo/v1';

export class ApiError extends Error {
	constructor(
		public readonly code: string,
		message: string,
		public readonly status: number | null,
		public readonly details: Record< string, unknown > = {}
	) {
		super( message );
		this.name = 'ApiError';
	}
}

function isErrorBody( value: unknown ): value is ApiErrorBody {
	return (
		typeof value === 'object' &&
		value !== null &&
		typeof ( value as { code?: unknown } ).code === 'string'
	);
}

async function request< T >( options: APIFetchOptions< true > ): Promise< T > {
	try {
		return await apiFetch< T >( options );
	} catch ( error: unknown ) {
		if ( error instanceof Error && error.name === 'AbortError' ) {
			throw error;
		}
		if ( isErrorBody( error ) ) {
			throw new ApiError(
				error.code,
				error.message,
				error.data?.status ?? null,
				error.data?.details ?? {}
			);
		}
		throw new ApiError( 'su_unknown_error', '', null );
	}
}

type Query = Record< string, string | number | undefined | null >;

function clean( query: Query ): Record< string, string | number > {
	const out: Record< string, string | number > = {};
	for ( const [ key, value ] of Object.entries( query ) ) {
		if ( value !== undefined && value !== null && value !== '' ) {
			out[ key ] = value;
		}
	}
	return out;
}

const get = < T >( path: string, query: Query = {}, signal?: AbortSignal ) =>
	request< T >( { path: addQueryArgs( NS + path, clean( query ) ), signal } );

const post = < T >( path: string, data: unknown, method = 'POST' ) =>
	request< T >( { path: NS + path, method, data } );

export const api = {
	overview: ( signal?: AbortSignal ) =>
		get< Overview >( '/overview', {}, signal ),
	changesets: ( query: Query, signal?: AbortSignal ) =>
		get< Page< ChangesetSummary > >( '/changesets', query, signal ),
	changeset: ( id: Uuid, signal?: AbortSignal ) =>
		get< ChangesetDetail >( `/changesets/${ id }`, {}, signal ),
	objectTimeline: (
		postId: number,
		cursor: string | null,
		signal?: AbortSignal
	) =>
		get< ObjectTimeline >(
			`/objects/post/${ postId }/changes`,
			{ cursor, limit: 50 },
			signal
		),
	changeDiff: ( changeId: number, offset = 0, signal?: AbortSignal ) =>
		get< DiffChunk >( `/changes/${ changeId }/diff`, { offset }, signal ),
	createPlan: ( selection: PlanSelection ) =>
		post< RestorePlan >( '/restore-plans', { selection } ),
	plan: ( id: Uuid, signal?: AbortSignal ) =>
		get< RestorePlan >( `/restore-plans/${ id }`, {}, signal ),
	planItemDiff: (
		planId: Uuid,
		itemId: number,
		view: 'current_target' | 'conflict',
		offset = 0,
		signal?: AbortSignal
	) =>
		get< DiffChunk >(
			`/restore-plans/${ planId }/items/${ itemId }/diff`,
			{ view, offset },
			signal
		),
	startRestore: ( planId: Uuid, idempotencyKey: Uuid ) =>
		post< RestoreJob >( '/restore-jobs', {
			plan_id: planId,
			idempotency_key: idempotencyKey,
		} ),
	jobs: ( cursor: string | null, signal?: AbortSignal ) =>
		get< Page< RestoreJob > >(
			'/restore-jobs',
			{ cursor, limit: 25 },
			signal
		),
	job: ( id: Uuid, signal?: AbortSignal ) =>
		get< RestoreJob >( `/restore-jobs/${ id }`, {}, signal ),
	jobItems: ( id: Uuid, after = 0, signal?: AbortSignal ) =>
		get< { items: JobItem[]; next_after: number | null } >(
			`/restore-jobs/${ id }/items`,
			{ after, limit: 100 },
			signal
		),
	cancelJob: ( id: Uuid ) =>
		post< RestoreJob >( `/restore-jobs/${ id }/cancel`, {} ),
	runJob: ( id: Uuid ) =>
		post< RestoreJob >( `/restore-jobs/${ id }/run`, {} ),
	settings: ( signal?: AbortSignal ) =>
		get< SettingsPayload >( '/settings', {}, signal ),
	saveSettings: (
		settings?: Partial< Settings >,
		roles?: Record< string, Record< string, boolean > >
	) => post< SettingsPayload >( '/settings', { settings, roles }, 'PATCH' ),
	health: ( signal?: AbortSignal ) =>
		get< { checks: HealthCheck[]; overview: Overview } >(
			'/health',
			{},
			signal
		),
	gaps: ( signal?: AbortSignal ) =>
		get< { items: Gap[] } >( '/gaps', { limit: 50 }, signal ),
	diagnostics: () => get< Record< string, unknown > >( '/diagnostics' ),
	exportHistory: () =>
		get< { filename: string; content: string } >( '/history/export' ),
	purgePreview: ( criteria: {
		scope: 'object' | 'before' | 'all';
		object_id?: number;
		before?: string;
	} ) => post< PurgePreview >( '/history/purge-preview', criteria ),
	purge: ( token: string, confirmation: string ) =>
		post< {
			deleted_changesets: number;
			deleted_changes: number;
			remaining: boolean;
		} >( '/history/purge', { token, confirmation } ),
};
