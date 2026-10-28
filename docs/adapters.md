# Extending Selective Undo

Selective Undo records changes of fields and restores the ones a user selects. Each kind of
data is handled by an **adapter**. This guide shows two ways to add your data:

1. **Custom fields stored in post meta** (Meta Box, Pods, your own fields): register them with
   the `selective_undo/meta_fields` filter. No adapter code is needed.
2. **Data stored elsewhere** (your own tables, options): write an adapter and register it with
   the `selective_undo/register_adapters` action.

Interfaces and classes marked `@api` in `src/Domain/Contracts/` are the public surface. Other
classes may change between versions.

## 1. Custom fields in post meta

The built-in `core/post-meta` adapter records and restores any meta key you register for a
post type. It handles everything: recording around `update_post_meta()` and friends, history,
conflicts, permissions (`edit_post_meta`) and cache invalidation.

```php
use SelectiveUndo\Domain\Contracts\FieldDefinition;

add_filter( 'selective_undo/meta_fields', function ( array $fields, string $post_type ): array {
	if ( 'product' === $post_type ) {
		// Key "meta:<meta key>", label, value type ("text", "html", "attachment", "raw", ...).
		$fields[] = new FieldDefinition( 'meta:price', __( 'Price', 'my-plugin' ), 'text', group: 'My Shop' );
	}
	return $fields;
}, 10, 2 );
```

Rules:

- Only the post types the site owner chose in **Settings → What to record** are recorded.
- Values are the exact `meta_value` strings: one string for one row, a list of strings for a key
  with several rows (in `meta_id` order). Serialized values are stored and written back byte for
  byte and are never unserialized on the restore path.
- One row with an empty string is treated as no value, because `get_post_meta()` returns `""`
  for both.
- `group` is shown before the label ("My Shop: Price"). Use the plugin name, so that fields of
  two plugins with the same label can be told apart.
- Register a field only when its value lives in that one meta key. A field whose value is spread
  over several keys (repeaters, flexible layouts) cannot be restored consistently key by key.
  For the same reason, ACF repeater, group, flexible content and clone fields are not recorded.
- Do not register secrets (passwords, API keys): history keeps every value and shows it in
  comparisons.

### Example: Meta Box

Meta Box stores a simple field under its ID. Fields with `multiple` or `clone` store several
rows or a serialized array, which are recorded as they are.

```php
add_filter( 'selective_undo/meta_fields', function ( array $fields, string $post_type ): array {
	if ( ! function_exists( 'rwmb_get_object_fields' ) ) {
		return $fields;
	}
	$skip = [ 'group', 'password', 'custom_html', 'heading', 'divider', 'button' ];
	foreach ( rwmb_get_object_fields( $post_type ) as $id => $field ) {
		if ( in_array( $field['type'] ?? '', $skip, true ) ) {
			continue;
		}
		$fields[] = new SelectiveUndo\Domain\Contracts\FieldDefinition(
			'meta:' . $id,
			(string) ( $field['name'] ?: $id ),
			in_array( $field['type'], [ 'wysiwyg', 'textarea' ], true ) ? 'html' : 'text',
			group: 'Meta Box'
		);
	}
	return $fields;
}, 10, 2 );
```

### Example: Pods

Pods that extend a post type and use meta storage keep each field under its name.

```php
add_filter( 'selective_undo/meta_fields', function ( array $fields, string $post_type ): array {
	if ( ! function_exists( 'pods_api' ) ) {
		return $fields;
	}
	$pod = pods_api()->load_pod( [ 'name' => $post_type ], false );
	if ( empty( $pod ) || 'meta' !== ( $pod['storage'] ?? '' ) ) {
		return $fields;
	}
	foreach ( $pod['fields'] as $name => $field ) {
		if ( in_array( $field['type'], [ 'password', 'pick', 'file' ], true ) ) {
			continue; // relationships and files are stored across several rows and tables
		}
		$fields[] = new SelectiveUndo\Domain\Contracts\FieldDefinition( 'meta:' . $name, (string) $field['label'], 'text', group: 'Pods' );
	}
	return $fields;
}, 10, 2 );
```

Check the result in **Selective Undo → History** after editing a field: the change should list
the field with its label, and **Check restore** should bring the old value back.

## 2. Writing an adapter

Write an adapter when your data is not post meta. An adapter implements
`SelectiveUndo\Domain\Contracts\AdapterInterface`; optional interfaces add features:

| Interface | Adds |
|---|---|
| `CapturingAdapter` | Recording: `registerCapture(CaptureSink $sink)` hooks into your code and reports changes. |
| `DescribesFields` | Field list with labels, value types and merge strategy for an object (`FieldDefinition`). |
| `MergeableAdapter` | Undo of part of a value (sets, keyed values) instead of the whole value. |
| `PresentsValues` / `PresentsImages` | Readable comparisons (names instead of IDs, images). |

Register it once Selective Undo builds its registry:

```php
add_action( 'selective_undo/register_adapters', function ( SelectiveUndo\Application\Restore\AdapterRegistry $registry ) {
	$registry->register( new My_Plugin\Undo\Ratings_Adapter() );
} );
```

### Field keys

Field keys carry a namespace: post fields have none (`post_title`), meta uses `meta:<key>`,
taxonomies `tax:<taxonomy>`. Use your own prefix (`ratings:score`) and return `true` from
`supports()` only for your keys.

### Recording

In `registerCapture()`, read the value right before your code writes it and right after, both
from the database (not from caches), and report the pair:

```php
$sink->record(
	$this,
	new ObjectRef( 'post', $post_id, get_post_type( $post_id ) ),
	[ 'ratings:score' => [ FieldValue::of( $before ), FieldValue::of( $after ) ] ],
	CaptureQuality::Verified
);
```

- Check `$sink->isActive()` first: it is false while recording is paused and while a restore
  writes (so a restore is not recorded twice).
- Report only real changes. The core groups changes into operations, stores values,
  keeps the order of the object's history and detects gaps.
- If you cannot observe a write reliably, call `$sink->recordGap( 'reason_code', $object )`
  instead of guessing values.

### Restoring

All adapters of one object are restored in **one** database transaction, ordered by priority
(post row 10, post meta 20, terms 30, others 100). Inside it:

1. `lockAndRead()` locks **only your rows** (`SELECT … FOR UPDATE` through `RestoreTransaction`)
   and returns the current values as an `ObjectSnapshot`.
2. `validateState()` may refuse a field based on the current state. It must be pure: no hooks,
   no filters, no other queries.
3. `write()` writes **only** the given fields, through `RestoreTransaction::write()`.

Never call WordPress functions that run hooks inside `lockAndRead()`, `validateState()` or
`write()`: other plugins' code must not run while rows are locked. Do that work in:

- `validateTarget()` — checks of the value to write (permissions, sanitizing rules); runs before
  the transaction;
- `afterCommit()` — cache invalidation and notifications after the transaction; it must be
  idempotent, because it may run again after a failure.

`read()` is the non-locking version of `lockAndRead()` used for previews. `version()` is the
format version of your values: change it whenever the format of a stored value changes, and
list the versions you can still restore in `acceptsVersion()`.

The built-in adapters in `src/Infrastructure/WordPress/` (`PostMetaAdapter`, `TermsAdapter`)
are complete examples of recording, locking, writing and cache invalidation.

## 3. Listening to recorded changes

Each time a change set (one save, one request, one restore) is recorded, Selective Undo fires
an action with its summary. The summary holds IDs and metadata only, never field values:

```php
add_action( 'selective_undo/changeset_recorded', function ( array $summary ): void {
	// uuid, kind (edit, restore, ...), source (block_editor, quick_edit, rest, wp_cli, ...),
	// actor (user ID or null), objects (["post:123"]), fields (["post_title"]), change_count
} );
```

The action runs in the request that saved, so keep it fast. For slow work (notifications,
calls to other services), register a background handler instead. It is called later from
WP-Cron or Action Scheduler, at least once per change set; a handler that throws is retried.

```php
add_action( 'plugins_loaded', function (): void {
	if ( function_exists( 'selective_undo' ) ) {
		selective_undo()->onChangesetRecorded( function ( array $summary ): void {
			// Same summary as the action.
		} );
	}
} );
```

When a later request of the same save adds to a change set (meta boxes, plugin fields), the
change set is reported again with the complete summary; use `uuid` to recognize it.
