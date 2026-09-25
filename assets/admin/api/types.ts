export type Uuid = string;
export type IsoDateTime = string;

export type ChangesetKind = 'edit' | 'autosave' | 'operation' | 'restore';
export type ChangeEvent =
	'update' | 'restore' | 'created' | 'trashed' | 'untrashed' | 'deleted';
export type PlanStatus =
	'preparing' | 'ready' | 'consumed' | 'expired' | 'failed';
export type PlanItemStatus =
	| 'ready'
	| 'already_restored'
	| 'conflict'
	| 'missing_object'
	| 'forbidden'
	| 'unsupported'
	| 'blocked';
export type JobStatus =
	| 'queued'
	| 'running'
	| 'cancel_requested'
	| 'completed'
	| 'completed_with_conflicts'
	| 'partially_failed'
	| 'failed'
	| 'cancelled';
export type JobItemStatus =
	| 'pending'
	| 'restored'
	| 'already_restored'
	| 'conflict'
	| 'skipped'
	| 'failed'
	| 'cancelled';

export const TERMINAL_JOB_STATUSES: ReadonlySet< JobStatus > = new Set( [
	'completed',
	'completed_with_conflicts',
	'partially_failed',
	'failed',
	'cancelled',
] );

export interface Actor {
	id: number;
	name: string;
}

export interface ObjectSummary {
	type: string;
	id: number;
	subtype: string;
	subtype_label: string;
	/** Current title as raw text. Rendered as a text node only. */
	title: string | null;
	edit_url: string | null;
	exists: boolean;
	status: string | null;
}

export interface FieldRef {
	key: string;
	label: string;
}

export interface ChangesetSummary {
	id: Uuid;
	kind: ChangesetKind;
	source: string;
	grouping: 'request' | 'operation' | 'session';
	label: string;
	status: 'open' | 'sealed';
	quality: 'verified' | 'observed' | 'ambiguous';
	actor: Actor | null;
	created_at: IsoDateTime;
	updated_at: IsoDateTime;
	change_count: number;
	object_count: number;
	hidden_objects: number;
	fields: FieldRef[];
	events: ChangeEvent[];
	has_limitations: boolean;
	objects: ObjectSummary[];
	restore_job_id: Uuid | null;
}

export interface ChangeSummary {
	id: number;
	event: ChangeEvent;
	field: string;
	field_label: string;
	restorable: boolean;
	reason_code: string | null;
	quality: 'verified' | 'observed' | 'ambiguous';
	reverts_change_id: number | null;
	created_at: IsoDateTime;
}

export interface ChangesetDetail extends Omit< ChangesetSummary, 'objects' > {
	objects: Array< { object: ObjectSummary; changes: ChangeSummary[] } >;
}

export interface TimelineItem extends ChangeSummary {
	changeset: {
		id: Uuid;
		kind: ChangesetKind;
		source: string;
		actor: Actor | null;
		label: string;
	};
}

export interface Page< T > {
	items: T[];
	next_cursor: string | null;
}

export interface ObjectTimeline extends Page< TimelineItem > {
	object: ObjectSummary;
}

export type DiffOp = 'equal' | 'insert' | 'delete' | 'collapsed';

export interface DiffLine {
	op: DiffOp;
	/** Raw text. Rendered ONLY as a text node. */
	text?: string;
	count?: number;
}

export interface DiffChunk {
	mode: 'before_after' | 'current_target' | 'conflict';
	left_label: string;
	right_label: string;
	unavailable: boolean;
	binary: boolean;
	lines: DiffLine[];
	truncated: boolean;
	next_offset: number | null;
	total_lines: number;
}

export interface PlanItem {
	id: number;
	object: ObjectSummary;
	field: string;
	field_label: string;
	change_ids: number[];
	status: PlanItemStatus;
	reason_code: string | null;
	warnings: string[];
}

export interface PlanSummary {
	objects: number;
	fields: number;
	ready: number;
	already_restored: number;
	conflicts: number;
	blocked: number;
	unsupported: number;
	missing: number;
	forbidden: number;
}

export type PlanSelection =
	| { type: 'changes'; change_ids: number[] }
	| {
			type: 'changeset';
			changeset_id: Uuid;
			fields?: string[];
			object_ids?: number[];
	  };

export interface RestorePlan {
	id: Uuid;
	status: PlanStatus;
	created_at: IsoDateTime;
	expires_at: IsoDateTime;
	summary: PlanSummary;
	untouched: string[];
	items: PlanItem[];
	job_id: Uuid | null;
	selection: PlanSelection | null;
}

export interface JobCounters {
	total: number;
	pending: number;
	restored: number;
	already_restored: number;
	conflict: number;
	skipped: number;
	failed: number;
	cancelled: number;
}

export interface RestoreJob {
	id: Uuid;
	plan_id: Uuid | null;
	status: JobStatus;
	mode: 'inline' | 'queued';
	actor: Actor | null;
	counters: JobCounters;
	postprocess_status: 'none' | 'pending' | 'done' | 'failed';
	queue_health: 'ok' | 'delayed' | 'unavailable';
	restore_changeset_id: Uuid | null;
	created_at: IsoDateTime;
	started_at: IsoDateTime | null;
	finished_at: IsoDateTime | null;
}

export interface JobItem {
	id: number;
	object: ObjectSummary;
	field: string;
	field_label: string;
	status: JobItemStatus;
	reason_code: string | null;
	result_change_id: number | null;
	finished_at: IsoDateTime | null;
}

export interface Overview {
	capture: {
		state: 'active' | 'paused' | 'disabled';
		reason: string | null;
		since: number | null;
	};
	restore_blockers: string[];
	storage: {
		logical_bytes: number;
		physical_bytes: number;
		quota_bytes: number;
		usage_ratio: number;
		level: 'ok' | 'warning' | 'critical';
	};
	retention_days: number;
	retention_max_days: number;
	oldest_event_at: IsoDateTime | null;
	gaps_last_24h: number;
	tracked_post_types: string[];
	user: {
		id: number;
		can: {
			view: boolean;
			restore: boolean;
			manage_settings: boolean;
			manage_retention: boolean;
			export: boolean;
		};
	};
	limits: { max_objects_per_plan: number };
}

export interface Settings {
	capture_enabled: boolean;
	tracked_post_types: string[];
	retention_days: number;
	quota_mb: number;
	evict_oldest_on_quota: boolean;
	max_value_kb: number;
	create_revision_after_restore: boolean;
	coalesce_autosaves: boolean;
	delete_data_on_uninstall: boolean;
}

export interface SettingsPayload {
	settings: Settings;
	limits: {
		retention_max_days: number;
		quota_min_mb: number;
		quota_max_mb: number;
		effective_max_value_bytes: number;
	};
	post_types: Array< {
		name: string;
		label: string;
		tracked: boolean;
		supports_editor: boolean;
	} >;
	roles: Record< string, { name: string; caps: Record< string, boolean > } >;
	capabilities: Array< { key: string; label: string } >;
	capture_state: Overview[ 'capture' ];
}

export interface HealthCheck {
	id: string;
	status: 'ok' | 'info' | 'warning' | 'error';
	label: string;
	message: string;
	blocks_restore: boolean;
}

export interface Gap {
	id: number;
	reason: string;
	scope: 'global' | 'object';
	object: ObjectSummary | null;
	occurrences: number;
	started_at: IsoDateTime | null;
	last_seen_at: IsoDateTime | null;
	ended_at: IsoDateTime | null;
}

export interface PurgePreview {
	token: string;
	changesets: number;
	changes: number;
	protected_changesets: number;
	expires_at: IsoDateTime;
}

export interface ApiErrorBody {
	code: string;
	message: string;
	data?: {
		status?: number;
		request_id?: string;
		details?: Record< string, unknown >;
	};
}
