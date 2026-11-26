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
		merge_conflict: __(
			'Some parts were changed again after this change. Choose what to keep for them.',
			'undoly'
		),
		current_value_changed: __(
			'This field was changed again after the selected change. It is skipped so that the newer version is not lost.',
			'undoly'
		),
		object_missing: __(
			'The item no longer exists. To bring it back, restore the change that deleted it.',
			'undoly'
		),
		not_allowed: __( 'You are not allowed to edit this item.', 'undoly' ),
		object_id_taken: __(
			'Another item now has the ID of the deleted one. It is not overwritten.',
			'undoly'
		),
		deletion_not_restorable: __(
			'Undoing this would delete the item again. Move it to the trash instead.',
			'undoly'
		),
		object_trashed: __(
			'The item is in the trash. Restore it from the trash first, then check again.',
			'undoly'
		),
		requires_unfiltered_html: __(
			'The previous value contains markup that your account is not allowed to publish. Ask an administrator to restore it.',
			'undoly'
		),
		chain_broken: __(
			'The selected changes of this field do not follow each other. Select one change, or a continuous sequence of changes.',
			'undoly'
		),
		duplicate_change: __( 'The same change was selected twice.', 'undoly' ),
		payload_not_stored: __(
			'The value was too large to be stored, so it cannot be restored.',
			'undoly'
		),
		capture_ambiguous: __(
			'This change was recorded in an inconsistent situation, so automatic restore is not allowed.',
			'undoly'
		),
		change_not_restorable: __(
			'This change cannot be restored.',
			'undoly'
		),
		informational_event: __(
			'This is an informational event without values to restore.',
			'undoly'
		),
		field_not_supported: __( 'This field is not supported.', 'undoly' ),
		post_type_not_tracked: __(
			'This content type is no longer recorded. Enable it in the settings to restore it.',
			'undoly'
		),
		adapter_unavailable: __(
			'The component that restores this kind of data is not active.',
			'undoly'
		),
		adapter_version_mismatch: __(
			'This change was recorded in an older, incompatible format.',
			'undoly'
		),
		target_type_mismatch: __(
			'The stored value has an unexpected type.',
			'undoly'
		),
		meta_not_allowed: __(
			'You are not allowed to edit this custom field.',
			'undoly'
		),
		attachment_missing: __(
			'The image no longer exists in the media library.',
			'undoly'
		),
		terms_not_allowed: __(
			'You are not allowed to assign terms of this taxonomy.',
			'undoly'
		),
		term_missing: __(
			'A category or tag that was assigned then has been deleted.',
			'undoly'
		),
		slug_taken: __( 'Another item uses this slug now.', 'undoly' ),
		status_not_restorable: __(
			'This status cannot be restored. Use the trash to bring back trashed items.',
			'undoly'
		),
		publish_not_allowed: __(
			'You are not allowed to publish this item.',
			'undoly'
		),
		author_invalid: __(
			'The previous author no longer exists or cannot edit this content type.',
			'undoly'
		),
		author_not_allowed: __(
			'You are not allowed to change the author of items written by others.',
			'undoly'
		),
		parent_invalid: __(
			'The previous parent no longer exists or is now a child of this item.',
			'undoly'
		),
		target_unavailable: __(
			'The stored value could not be read.',
			'undoly'
		),
		current_value_unreadable: __(
			'The current value could not be read.',
			'undoly'
		),
		payload_missing: __(
			'The stored value is missing from the history.',
			'undoly'
		),
		payload_hash_mismatch: __(
			'The stored value is damaged (checksum mismatch). It will not be restored.',
			'undoly'
		),
		payload_malformed: __(
			'The stored value is damaged. It will not be restored.',
			'undoly'
		),
		payload_not_canonical: __(
			'The stored value is damaged. It will not be restored.',
			'undoly'
		),
		payload_size_mismatch: __(
			'The stored value is damaged. It will not be restored.',
			'undoly'
		),
		payload_unknown_format: __(
			'The stored value uses an unknown format.',
			'undoly'
		),
		payload_unknown_encoding: __(
			'The stored value uses an unknown format.',
			'undoly'
		),
		payload_too_large: __(
			'The stored value exceeds the current size limit.',
			'undoly'
		),
		payload_codec_unavailable: __(
			'The server cannot decompress the stored value (zlib is missing).',
			'undoly'
		),
		lock_wait_timeout: __(
			'The item was locked by another save for too long. It will be retried.',
			'undoly'
		),
		deadlock: __(
			'A database conflict occurred. It will be retried.',
			'undoly'
		),
		connection_lost: __(
			'The database connection was lost. It will be retried.',
			'undoly'
		),
	};

	return (
		map[ code ] ??
		sprintf(
			/* translators: %s: technical reason code. */
			__( 'Blocked by a site rule (%s).', 'undoly' ),
			code
		)
	);
}

export function warningText( code: string ): string {
	const map: Record< string, string > = {
		intervening_changes: __(
			'Between the selected changes there are other changes that cancel each other out.',
			'undoly'
		),
		later_changes_exist: __(
			'This field was changed later and then returned to the same value.',
			'undoly'
		),
		merged_with_later_changes: __(
			'The field was changed again later. Only this change is undone; the later edits of other parts are kept.',
			'undoly'
		),
		merge_conflicts_resolved: __(
			'Your choices for the parts changed twice are applied; the other later edits are kept.',
			'undoly'
		),
		post_locked: __(
			'Someone is editing this item right now. If they save their open editor, the restored value will be overwritten.',
			'undoly'
		),
		status_change_visible: __(
			'Restoring the status changes who can see this item on the site.',
			'undoly'
		),
		page_builder_detected: __(
			'This page is built with a page builder. Restoring the content may not change what visitors see.',
			'undoly'
		),
		linked_field_present: __(
			'Another plugin keeps a copy of this content (for example Markdown source) and may overwrite the restored content on the next edit.',
			'undoly'
		),
		restored_as_draft: __(
			'The item comes back as a draft under its own ID, with its custom fields and terms; publish it when ready. Its comments, revisions, child items and media files are not restored.',
			'undoly'
		),
		capture_gap_in_range: __(
			'The history of this item is incomplete for this period.',
			'undoly'
		),
	};

	return map[ code ] ?? code;
}

export function planStatusLabel( status: PlanItemStatus ): string {
	switch ( status ) {
		case 'ready':
			return __( 'Ready to restore', 'undoly' );
		case 'already_restored':
			return __( 'Already in the desired state', 'undoly' );
		case 'conflict':
			return __( 'Changed again', 'undoly' );
		case 'missing_object':
			return __( 'Item deleted', 'undoly' );
		case 'forbidden':
			return __( 'No permission', 'undoly' );
		case 'unsupported':
			return __( 'Not supported', 'undoly' );
		case 'blocked':
			return __( 'Cannot be restored', 'undoly' );
	}
}

export function jobItemStatusLabel( status: JobItemStatus ): string {
	switch ( status ) {
		case 'pending':
			return __( 'Waiting', 'undoly' );
		case 'restored':
			return __( 'Restored', 'undoly' );
		case 'already_restored':
			return __( 'Already in the desired state', 'undoly' );
		case 'conflict':
			return __( 'Skipped: changed again', 'undoly' );
		case 'skipped':
			return __( 'Skipped', 'undoly' );
		case 'failed':
			return __( 'Failed', 'undoly' );
		case 'cancelled':
			return __( 'Not started (stopped)', 'undoly' );
	}
}

export function jobStatusLabel( status: JobStatus ): string {
	switch ( status ) {
		case 'queued':
			return __( 'Waiting to start', 'undoly' );
		case 'running':
			return __( 'In progress', 'undoly' );
		case 'cancel_requested':
			return __( 'Stopping', 'undoly' );
		case 'completed':
			return __( 'Completed', 'undoly' );
		case 'completed_with_conflicts':
			return __( 'Completed with skipped fields', 'undoly' );
		case 'partially_failed':
			return __( 'Partially failed', 'undoly' );
		case 'failed':
			return __( 'Failed', 'undoly' );
		case 'cancelled':
			return __( 'Stopped', 'undoly' );
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
		block_editor: __( 'Block editor or REST', 'undoly' ),
		classic_editor: __( 'Classic editor', 'undoly' ),
		settings_screen: __( 'Settings screen', 'undoly' ),
		menus_screen: __( 'Menus screen', 'undoly' ),
		quick_edit: __( 'Quick Edit', 'undoly' ),
		bulk_edit: __( 'Bulk edit', 'undoly' ),
		autosave: __( 'Autosave', 'undoly' ),
		app_password: __( 'REST API (application password)', 'undoly' ),
		rest: __( 'REST API', 'undoly' ),
		xmlrpc: __( 'XML-RPC', 'undoly' ),
		wp_cli: __( 'WP-CLI', 'undoly' ),
		cron: __( 'Scheduled task', 'undoly' ),
		ajax: __( 'Admin request', 'undoly' ),
		restore: __( 'Undoly', 'undoly' ),
		revisions: __( 'WordPress revisions', 'undoly' ),
		editor_undo: __( 'Undo in the editor', 'undoly' ),
		graphql: __( 'GraphQL', 'undoly' ),
		external: __( 'Outside WordPress', 'undoly' ),
	};

	return map[ source ] ?? __( 'Other', 'undoly' );
}

export function eventLabel( event: ChangeEvent ): string {
	switch ( event ) {
		case 'created':
			return __( 'Created', 'undoly' );
		case 'trashed':
			return __( 'Moved to trash', 'undoly' );
		case 'untrashed':
			return __( 'Restored from trash', 'undoly' );
		case 'deleted':
			return __( 'Deleted permanently', 'undoly' );
		case 'restore':
			return __( 'Restored', 'undoly' );
		default:
			return __( 'Changed', 'undoly' );
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
			return _x( 'Restore', 'noun: a restore operation', 'undoly' );
		}

		return sprintf(
			/* translators: %s: post title. */
			__( 'Restore of “%s”', 'undoly' ),
			title
		);
	}

	if ( cs.kind === 'autosave' ) {
		return sprintf(
			/* translators: %s: post title. */
			__( 'Autosaved draft “%s”', 'undoly' ),
			title
		);
	}

	if ( cs.kind === 'operation' ) {
		return __( 'Operation', 'undoly' );
	}

	if (
		cs.events.length === 1 &&
		cs.events[ 0 ] !== 'update' &&
		cs.events[ 0 ] !== 'restore' &&
		cs.object_count === 1
	) {
		return sprintf(
			/* translators: 1: event name, for example "Created". 2: post title. */
			_x( '%1$s: “%2$s”', 'event: post title', 'undoly' ),
			eventLabel( cs.events[ 0 ]! ),
			title
		);
	}

	if ( cs.object_count === 1 ) {
		return sprintf(
			/* translators: %s: post title. */
			__( 'Edit of “%s”', 'undoly' ),
			title
		);
	}

	return sprintf(
		/* translators: %d: number of items. */
		_n( 'Edit of %d item', 'Edit of %d items', cs.object_count, 'undoly' ),
		cs.object_count
	);
}

/**
 * The link to the history of one object.
 * @param type Object type.
 */
export function historyLabel( type: string ): string {
	switch ( type ) {
		case 'site':
			return __( 'History of these settings', 'undoly' );
		case 'menu':
			return __( 'History of this menu', 'undoly' );
		default:
			return __( 'History of this item', 'undoly' );
	}
}

/** The link to where an object is edited. */
export function openLabel( type: string ): string {
	switch ( type ) {
		case 'site':
			return __( 'Open settings', 'undoly' );
		case 'menu':
			return __( 'Open menus', 'undoly' );
		default:
			return __( 'Open editor', 'undoly' );
	}
}

export function displayTitle( title: string | null, id: number ): string {
	if ( title === null ) {
		return sprintf(
			/* translators: %d: post ID. */
			__( 'Deleted item #%d', 'undoly' ),
			id
		);
	}

	if ( title.trim() === '' ) {
		return sprintf(
			/* translators: %d: post ID. */
			__( '(no title) #%d', 'undoly' ),
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
				__( 'Confirmed operation: %s', 'undoly' ),
				cs.label || __( 'integration', 'undoly' )
			);
		case 'session':
			return __( 'Autosave session', 'undoly' );
		default:
			return __( 'One request', 'undoly' );
	}
}

export function untouchedLabel( code: string ): string {
	const map: Record< string, string > = {
		unselected_fields: __( 'fields you did not select', 'undoly' ),
		unrecorded_metadata: __(
			'custom fields that are not recorded',
			'undoly'
		),
		comments: __( 'comments', 'undoly' ),
		users: __( 'users', 'undoly' ),
		new_items: __( 'items created later', 'undoly' ),
	};

	return map[ code ] ?? code;
}

export function gapReasonText( reason: string ): string {
	const map: Record< string, string > = {
		external_write_detected: __(
			'A field was changed outside WordPress (for example by a direct database query). That change is not in the history.',
			'undoly'
		),
		capture_failed: __(
			'A change could not be recorded because of an error. See diagnostics.',
			'undoly'
		),
		payload_not_stored: __(
			'A value was too large to be stored.',
			'undoly'
		),
		nesting_too_deep: __(
			'Too many nested saves of one item in a single request; some were not recorded.',
			'undoly'
		),
		stale_object_cache: __(
			'WordPress saved an item based on outdated cached data. The recorded values are correct.',
			'undoly'
		),
		quota_exceeded: __(
			'Recording was paused because the history size limit was reached.',
			'undoly'
		),
		schema_outdated: __(
			'Recording was paused while the database was being updated.',
			'undoly'
		),
		capture_disabled: __(
			'Recording was turned off in the settings.',
			'undoly'
		),
		site_moved: __( 'The history was copied from another site.', 'undoly' ),
		database_restored: __(
			'The database seems to have been restored from a backup.',
			'undoly'
		),
		circuit_open: __(
			'Recording was paused because writing the history failed repeatedly or was too slow.',
			'undoly'
		),
	};

	return map[ reason ] ?? reason;
}

export function blockerText( code: string ): string {
	const map: Record< string, string > = {
		schema_outdated: __( 'the database update has not finished', 'undoly' ),
		posts_table_not_innodb: __(
			'the posts table does not support transactions (InnoDB is required)',
			'undoly'
		),
		plugin_tables_invalid: __(
			'history tables are missing or do not support transactions',
			'undoly'
		),
		restore_connection_failed: __(
			'a dedicated database connection could not be opened',
			'undoly'
		),
		restore_connection_wrong_database: __(
			'the dedicated database connection reaches a different database',
			'undoly'
		),
		restore_connection_charset: __(
			'the database connection character set could not be set',
			'undoly'
		),
		db_constants_missing: __(
			'database settings in wp-config.php are not available',
			'undoly'
		),
		db_host_unparseable: __(
			'DB_HOST in wp-config.php could not be read',
			'undoly'
		),
		mysqli_unavailable: __(
			'the PHP mysqli extension is missing',
			'undoly'
		),
	};

	return map[ code ] ?? code;
}
