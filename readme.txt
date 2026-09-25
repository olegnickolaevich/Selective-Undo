=== Selective Undo ===
Contributors: olegnickolaevich
Tags: undo, history, restore, revisions, content
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Undo a specific content change and keep everything else. Restore single fields of posts and pages without overwriting newer edits.

== Description ==

A WordPress revision restores the whole post. Selective Undo restores only what you choose: the title changed by mistake, the excerpt someone rewrote, the content from before a bad paste. Everything else, including edits made after that change, stays as it is.

**How it works**

1. Selective Undo records changes of the title, content, excerpt and order of posts, pages and the custom post types you choose, whatever made them: the block editor, Quick Edit, bulk edit, the REST API, WP-CLI, XML-RPC or another plugin.
2. Open **Change history** from the row actions on the Posts or Pages screen, or go to **Selective Undo → History**.
3. Compare before and after, select the changes to undo and click **Check restore**.
4. The preview shows what will be restored, what is already in the desired state and what needs attention. Nothing is written yet.
5. Click **Restore**. Every value is checked again inside a database transaction right before it is written.

**Safe by design**

* **No silent overwrites.** If a field was changed again after the selected change, it is reported as a conflict and skipped. There is no "force" option.
* **One field, one decision.** Restoring the title does not touch the content, status, slug, author, dates, custom fields or comments.
* **Restores are undoable.** Every restore is recorded like any other change, so it can be undone the same way.
* **Safe to retry.** Clicking twice, a lost connection or a reloaded page never runs the same restore twice.
* **Transactional.** Each item is restored in a short database transaction on a dedicated connection, with row locks and a final comparison. A failure leaves the item unchanged.
* **Honest about gaps.** Changes made directly in the database, bypassing WordPress, are detected and shown as gaps in the history instead of being guessed.

**What the free version covers**

* Existing posts, pages and the custom post types you choose (those with an admin screen).
* Fields: title, content, excerpt and menu order.
* History for up to 7 days, with a size limit (256 MB by default) and automatic removal of the oldest history.
* One item per restore.
* Merged autosaves: autosaves of a draft by its author become one entry per editing session.
* Optional WordPress revision after each restore, so the post's own revision history stays complete.

**What is not recorded or restored**

Deleted items, creation of new items, status, slug, author, dates, custom fields and metadata, comments, users, settings, media files, orders and payments. Emails, webhooks and other effects outside the database are never undone.

**Permissions**

On activation, only administrators can view the history and restore changes. Each permission (view history, restore changes, manage settings, delete history, export history) can be granted to other roles in **Selective Undo → Settings → Access**. A user always needs permission to edit an item to see or restore its history.

**For developers**

* REST API under `selective-undo/v1`, used by the admin interface.
* WP-CLI: `wp selective-undo health`, `wp selective-undo maintenance`, `wp selective-undo queue`.
* Actions and filters use the `selective_undo/` prefix, for example `selective_undo/object_restored` after an item is restored.

The source code of the admin interface (React, TypeScript) is in `assets/admin`; the compiled files are in `build`. Development happens at [github.com/olegnickolaevich/Selective-Undo](https://github.com/olegnickolaevich/Selective-Undo), where you can also report bugs.

== Installation ==

1. Install the plugin from **Plugins → Add New** or upload the `selective-undo` folder to `/wp-content/plugins/`.
2. Activate it on the **Plugins** screen. On a multisite network, activate it on individual sites.
3. Open **Selective Undo → Settings → Diagnostics** and check that restoring is available. It needs InnoDB tables and the PHP mysqli extension.
4. History starts with the first change made after activation. Earlier changes cannot be undone.

For rarely visited sites, run WP-Cron from a system cron job so that background maintenance and restores are not delayed.

== Frequently Asked Questions ==

= How is this different from WordPress revisions? =

A revision restores the entire post as it was, including fields you did not want to change, and overwrites any newer edits. Selective Undo restores individual fields, works for changes made outside the editor (Quick Edit, bulk edit, REST API, WP-CLI, other plugins), checks for newer edits and skips conflicting fields instead of overwriting them.

= What happens if someone edited the field after the change I want to undo? =

The preview marks the field as "Changed again" and it is not restored. You can compare the current value with the value that would be restored and decide what to do in the editor.

= Can I undo several changes of the same field at once? =

Yes. Select consecutive changes of the same field in the item's history and the field returns to the value before the first of them.

= Can I undo a restore? =

Yes. A restore is recorded in the history like any other change. Open it and click **Check undo of this restore**.

= Does it work with page builders? =

Selective Undo restores the `post_content` field. When a page builder stores the layout elsewhere, the preview warns that restoring the content may not change what visitors see.

= How much space does the history use? =

Values are deduplicated and compressed. The history size is shown in the header of the plugin screens and is limited to 256 MB by default. When the limit is reached, the oldest history is removed or, if you prefer, recording pauses.

= Does the plugin work with object and page caches? =

Restored items are removed from the object cache. Page caches with a known integration are purged; for other page caches, the diagnostics screen reminds you to clear the page cache manually.

= What happens when I deactivate or delete the plugin? =

Deactivating keeps the history and settings. Deleting the plugin keeps them too, unless you enable **Delete all history and settings when the plugin is deleted** in **Settings → Removal**.

= Is the interface translated? =

The plugin is fully translatable. Translations are delivered by WordPress.org language packs; you can help translate Selective Undo into your language at [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/selective-undo/).

== Privacy ==

Selective Undo stores, in your site's database, the previous and new values of the fields it records, the user who made each change, when it was made and where it came from (for example, the block editor or the REST API). Text removed from a page can remain in the history until it expires. Nothing is sent to external services.

The plugin adds a suggested paragraph to the privacy policy guide and registers an exporter and an eraser for the WordPress personal data tools. Administrators can delete the history of one item, history older than a date, or all history in **Settings → Removal**.

== Screenshots ==

1. History of recorded changes with filters.
2. One operation: compare the changed fields before and after and select what to undo.
3. Restore preview: what will be restored, what is already in the desired state and what needs attention.
4. Restore report.
5. Settings: what to record, storage, access, diagnostics and removal.

== Changelog ==

= 1.0.0 =
* Initial release.
