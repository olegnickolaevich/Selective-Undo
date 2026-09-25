import { __, _n, _x, sprintf } from '@wordpress/i18n';
import type {
	ChangeEvent,
	ChangesetSummary,
	JobItemStatus,
	JobStatus,
	PlanItemStatus,
} from '../api/types';

/**
 * Every code the server returns has a human explanation here. Unknown codes fall
 * back to a generic message that still shows the code for support.
 */

export function reasonText( code: string | null ): string {
	if ( ! code ) {
		return '';
	}

	const map: Record< string, string > = {
		current_value_changed: __(
			'This field was changed again after the selected change. It is skipped so that the newer version is not lost.',
			'selective-undo'
		),
		object_missing: __(
			'The item no longer exists. Deleted items cannot be restored in this version.',
			'selective-undo'
		),
		not_allowed: __(
			'You are not allowed to edit this item.',
			'selective-undo'
		),
		object_trashed: __(
			'The item is in the trash. Restore it from the trash first, then check again.',
			'selective-undo'
		),
		requires_unfiltered_html: __(
			'The previous value contains markup that your account is not allowed to publish. Ask an administrator to restore it.',
			'selective-undo'
		),
		chain_broken: __(
			'The selected changes of this field do not follow each other. Select one change, or a continuous sequence of changes.',
			'selective-undo'
		),
		duplicate_change: __(
			'The same change was selected twice.',
			'selective-undo'
		),
		payload_not_stored: __(
			'The value was too large to be stored, so it cannot be restored.',
			'selective-undo'
		),
		capture_ambiguous: __(
			'This change was recorded in an inconsistent situation, so automatic restore is not allowed.',
			'selective-undo'
		),
		change_not_restorable: __(
			'This change cannot be restored.',
			'selective-undo'
		),
		informational_event: __(
			'This is an informational event without values to restore.',
			'selective-undo'
		),
		field_not_supported: __(
			'This field is not supported.',
			'selective-undo'
		),
		post_type_not_tracked: __(
			'This content type is no longer recorded. Enable it in the settings to restore it.',
			'selective-undo'
		),
		adapter_unavailable: __(
			'The component that restores this kind of data is not active.',
			'selective-undo'
		),
		adapter_version_mismatch: __(
			'This change was recorded in an older, incompatible format.',
			'selective-undo'
		),
		target_type_mismatch: __(
			'The stored value has an unexpected type.',
			'selective-undo'
		),
		target_unavailable: __(
			'The stored value could not be read.',
			'selective-undo'
		),
		current_value_unreadable: __(
			'The current value could not be read.',
			'selective-undo'
		),
		payload_missing: __(
			'The stored value is missing from the history.',
			'selective-undo'
		),
		payload_hash_mismatch: __(
			'The stored value is damaged (checksum mismatch). It will not be restored.',
			'selective-undo'
		),
		payload_malformed: __(
			'The stored value is damaged. It will not be restored.',
			'selective-undo'
		),
		payload_not_canonical: __(
			'The stored value is damaged. It will not be restored.',
			'selective-undo'
		),
		payload_size_mismatch: __(
			'The stored value is damaged. It will not be restored.',
			'selective-undo'
		),
		payload_unknown_format: __(
			'The stored value uses an unknown format.',
			'selective-undo'
		),
		payload_unknown_encoding: __(
			'The stored value uses an unknown format.',
			'selective-undo'
		),
		payload_too_large: __(
			'The stored value exceeds the current size limit.',
			'selective-undo'
		),
		payload_codec_unavailable: __(
			'The server cannot decompress the stored value (zlib is missing).',
			'selective-undo'
		),
		lock_wait_timeout: __(
			'The item was locked by another save for too long. It will be retried.',
			'selective-undo'
		),
		deadlock: __(
			'A database conflict occurred. It will be retried.',
			'selective-undo'
		),
		connection_lost: __(
			'The database connection was lost. It will be retried.',
			'selective-undo'
		),
	};

	return (
		map[ code ] ??
		sprintf(
			/* translators: %s: technical reason code. */
			__( 'Blocked by a site rule (%s).', 'selective-undo' ),
			code
		)
	);
}

export function warningText( code: string ): string {
	const map: Record< string, string > = {
		intervening_changes: __(
			'Between the selected changes there are other changes that cancel each other out.',
			'selective-undo'
		),
		later_changes_exist: __(
			'This field was changed later and then returned to the same value.',
			'selective-undo'
		),
		post_locked: __(
			'Someone is editing this item right now. If they save their open editor, the restored value will be overwritten.',
			'selective-undo'
		),
		page_builder_detected: __(
			'This page is built with a page builder. Restoring the content may not change what visitors see.',
			'selective-undo'
		),
		linked_field_present: __(
			'Another plugin keeps a copy of this content (for example Markdown source) and may overwrite the restored content on the next edit.',
			'selective-undo'
		),
		capture_gap_in_range: __(
			'The history of this item is incomplete for this period.',
			'selective-undo'
		),
	};

	return map[ code ] ?? code;
}

export function planStatusLabel( status: PlanItemStatus ): string {
	switch ( status ) {
		case 'ready':
			return __( 'Ready to restore', 'selective-undo' );
		case 'already_restored':
			return __( 'Already in the desired state', 'selective-undo' );
		case 'conflict':
			return __( 'Changed again', 'selective-undo' );
		case 'missing_object':
			return __( 'Item deleted', 'selective-undo' );
		case 'forbidden':
			return __( 'No permission', 'selective-undo' );
		case 'unsupported':
			return __( 'Not supported', 'selective-undo' );
		case 'blocked':
			return __( 'Cannot be restored', 'selective-undo' );
	}
}

export function jobItemStatusLabel( status: JobItemStatus ): string {
	switch ( status ) {
		case 'pending':
			return __( 'Waiting', 'selective-undo' );
		case 'restored':
			return __( 'Restored', 'selective-undo' );
		case 'already_restored':
			return __( 'Already in the desired state', 'selective-undo' );
		case 'conflict':
			return __( 'Skipped: changed again', 'selective-undo' );
		case 'skipped':
			return __( 'Skipped', 'selective-undo' );
		case 'failed':
			return __( 'Failed', 'selective-undo' );
		case 'cancelled':
			return __( 'Not started (stopped)', 'selective-undo' );
	}
}

export function jobStatusLabel( status: JobStatus ): string {
	switch ( status ) {
		case 'queued':
			return __( 'Waiting to start', 'selective-undo' );
		case 'running':
			return __( 'In progress', 'selective-undo' );
		case 'cancel_requested':
			return __( 'Stopping', 'selective-undo' );
		case 'completed':
			return __( 'Completed', 'selective-undo' );
		case 'completed_with_conflicts':
			return __( 'Completed with skipped fields', 'selective-undo' );
		case 'partially_failed':
			return __( 'Partially failed', 'selective-undo' );
		case 'failed':
			return __( 'Failed', 'selective-undo' );
		case 'cancelled':
			return __( 'Stopped', 'selective-undo' );
	}
}

export type Tone = 'success' | 'warning' | 'error' | 'info' | 'neutral';

const PLAN_TONES: Record< PlanItemStatus, Tone > = {
	ready: 'success',
	already_restored: 'neutral',
	conflict: 'warning',
	missing_object: 'error',
	forbidden: 'error',
	unsupported: 'error',
	blocked: 'error',
};

export function planStatusTone( status: PlanItemStatus ): Tone {
	return PLAN_TONES[ status ];
}

export function jobStatusTone( status: JobStatus ): Tone {
	switch ( status ) {
		case 'completed':
			return 'success';
		case 'completed_with_conflicts':
		case 'cancelled':
		case 'cancel_requested':
			return 'warning';
		case 'partially_failed':
		case 'failed':
			return 'error';
		default:
			return 'info';
	}
}

const JOB_ITEM_TONES: Record< JobItemStatus, Tone > = {
	pending: 'info',
	restored: 'success',
	already_restored: 'neutral',
	conflict: 'warning',
	skipped: 'warning',
	failed: 'error',
	cancelled: 'warning',
};

export function jobItemTone( status: JobItemStatus ): Tone {
	return JOB_ITEM_TONES[ status ];
}

export function sourceLabel( source: string ): string {
	const map: Record< string, string > = {
		block_editor: __( 'Block editor or REST', 'selective-undo' ),
		classic_editor: __( 'Classic editor', 'selective-undo' ),
		quick_edit: __( 'Quick Edit', 'selective-undo' ),
		bulk_edit: __( 'Bulk edit', 'selective-undo' ),
		autosave: __( 'Autosave', 'selective-undo' ),
		app_password: __( 'REST API (application password)', 'selective-undo' ),
		rest: __( 'REST API', 'selective-undo' ),
		xmlrpc: __( 'XML-RPC', 'selective-undo' ),
		wp_cli: __( 'WP-CLI', 'selective-undo' ),
		cron: __( 'Scheduled task', 'selective-undo' ),
		ajax: __( 'Admin request', 'selective-undo' ),
		restore: __( 'Selective Undo', 'selective-undo' ),
	};

	return map[ source ] ?? __( 'Other', 'selective-undo' );
}

export function eventLabel( event: ChangeEvent ): string {
	switch ( event ) {
		case 'created':
			return __( 'Created', 'selective-undo' );
		case 'trashed':
			return __( 'Moved to trash', 'selective-undo' );
		case 'untrashed':
			return __( 'Restored from trash', 'selective-undo' );
		case 'deleted':
			return __( 'Deleted permanently', 'selective-undo' );
		case 'restore':
			return __( 'Restored', 'selective-undo' );
		default:
			return __( 'Changed', 'selective-undo' );
	}
}

export function changesetTitle(
	cs: Pick<
		ChangesetSummary,
		'kind' | 'label' | 'objects' | 'object_count' | 'events'
	>
): string {
	if ( cs.label ) {
		return cs.label;
	}

	const first = cs.objects[ 0 ];
	const title = first ? displayTitle( first.title, first.id ) : '';

	if ( cs.kind === 'restore' ) {
		if ( cs.object_count !== 1 ) {
			return _x(
				'Restore',
				'noun: a restore operation',
				'selective-undo'
			);
		}

		return sprintf(
			/* translators: %s: post title. */
			__( 'Restore of “%s”', 'selective-undo' ),
			title
		);
	}

	if ( cs.kind === 'autosave' ) {
		return sprintf(
			/* translators: %s: post title. */
			__( 'Autosaved draft “%s”', 'selective-undo' ),
			title
		);
	}

	if ( cs.kind === 'operation' ) {
		return __( 'Operation', 'selective-undo' );
	}

	if (
		cs.events.length === 1 &&
		cs.events[ 0 ] !== 'update' &&
		cs.events[ 0 ] !== 'restore' &&
		cs.object_count === 1
	) {
		return sprintf(
			/* translators: 1: event name, for example "Created". 2: post title. */
			_x( '%1$s: “%2$s”', 'event: post title', 'selective-undo' ),
			eventLabel( cs.events[ 0 ]! ),
			title
		);
	}

	if ( cs.object_count === 1 ) {
		return sprintf(
			/* translators: %s: post title. */
			__( 'Edit of “%s”', 'selective-undo' ),
			title
		);
	}

	return sprintf(
		/* translators: %d: number of items. */
		_n(
			'Edit of %d item',
			'Edit of %d items',
			cs.object_count,
			'selective-undo'
		),
		cs.object_count
	);
}

export function displayTitle( title: string | null, id: number ): string {
	if ( title === null ) {
		return sprintf(
			/* translators: %d: post ID. */
			__( 'Deleted item #%d', 'selective-undo' ),
			id
		);
	}

	if ( title.trim() === '' ) {
		return sprintf(
			/* translators: %d: post ID. */
			__( '(no title) #%d', 'selective-undo' ),
			id
		);
	}

	return title;
}

export function groupingLabel(
	cs: Pick< ChangesetSummary, 'grouping' | 'label' >
): string {
	switch ( cs.grouping ) {
		case 'operation':
			return sprintf(
				/* translators: %s: operation name. */
				__( 'Confirmed operation: %s', 'selective-undo' ),
				cs.label || __( 'integration', 'selective-undo' )
			);
		case 'session':
			return __( 'Autosave session', 'selective-undo' );
		default:
			return __( 'One request', 'selective-undo' );
	}
}

export function untouchedLabel( code: string ): string {
	const map: Record< string, string > = {
		unselected_fields: __( 'fields you did not select', 'selective-undo' ),
		status_and_dates: __(
			'status and publication dates',
			'selective-undo'
		),
		slug_and_author: __( 'slug and author', 'selective-undo' ),
		metadata: __( 'custom fields and metadata', 'selective-undo' ),
		comments: __( 'comments', 'selective-undo' ),
		users: __( 'users', 'selective-undo' ),
		new_items: __( 'items created later', 'selective-undo' ),
	};

	return map[ code ] ?? code;
}

export function gapReasonText( reason: string ): string {
	const map: Record< string, string > = {
		external_write_detected: __(
			'A field was changed outside WordPress (for example by a direct database query). That change is not in the history.',
			'selective-undo'
		),
		capture_failed: __(
			'A change could not be recorded because of an error. See diagnostics.',
			'selective-undo'
		),
		payload_not_stored: __(
			'A value was too large to be stored.',
			'selective-undo'
		),
		nesting_too_deep: __(
			'Too many nested saves of one item in a single request; some were not recorded.',
			'selective-undo'
		),
		stale_object_cache: __(
			'WordPress saved an item based on outdated cached data. The recorded values are correct.',
			'selective-undo'
		),
		quota_exceeded: __(
			'Recording was paused because the history size limit was reached.',
			'selective-undo'
		),
		schema_outdated: __(
			'Recording was paused while the database was being updated.',
			'selective-undo'
		),
		capture_disabled: __(
			'Recording was turned off in the settings.',
			'selective-undo'
		),
	};

	return map[ reason ] ?? reason;
}

export function blockerText( code: string ): string {
	const map: Record< string, string > = {
		schema_outdated: __(
			'the database update has not finished',
			'selective-undo'
		),
		posts_table_not_innodb: __(
			'the posts table does not support transactions (InnoDB is required)',
			'selective-undo'
		),
		plugin_tables_invalid: __(
			'history tables are missing or do not support transactions',
			'selective-undo'
		),
		restore_connection_failed: __(
			'a dedicated database connection could not be opened',
			'selective-undo'
		),
		restore_connection_wrong_database: __(
			'the dedicated database connection reaches a different database',
			'selective-undo'
		),
		restore_connection_charset: __(
			'the database connection character set could not be set',
			'selective-undo'
		),
		db_constants_missing: __(
			'database settings in wp-config.php are not available',
			'selective-undo'
		),
		db_host_unparseable: __(
			'DB_HOST in wp-config.php could not be read',
			'selective-undo'
		),
		mysqli_unavailable: __(
			'the PHP mysqli extension is missing',
			'selective-undo'
		),
	};

	return map[ code ] ?? code;
}
