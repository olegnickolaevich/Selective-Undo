<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Support;

use SelectiveUndo\Application\Capture\GapRecorder;

/**
 * Notices when the history was copied with the database to another site (a staging
 * clone, a move to another address or database): the history may then describe
 * edits made elsewhere. The administrator decides to keep or to clear it.
 */
final class SiteFingerprint
{
    public const GAP_REASON = 'site_moved';
    public const RESTORED_REASON = 'database_restored';
    private const OPTION = 'selective_undo_site_fingerprint';
    private const MOVED_OPTION = 'selective_undo_site_moved';
    private const CHECKED_TRANSIENT = 'selective_undo_fingerprint_checked';
    private const INSTANCE_OPTION = 'selective_undo_instance_id';

    public function __construct(private readonly \wpdb $db, private readonly GapRecorder $gaps)
    {
    }

    /** Compares the stored fingerprint with this site, at most once an hour. */
    public function check(bool $force = false): void
    {
        if (!$force && get_transient(self::CHECKED_TRANSIENT) !== false) {
            return;
        }

        set_transient(self::CHECKED_TRANSIENT, 1, HOUR_IN_SECONDS);
        $stored = get_option(self::OPTION);

        if (!is_string($stored) || $stored === '') {
            update_option(self::OPTION, $this->current(), false);

            return;
        }

        if (!hash_equals($stored, $this->current()) && !$this->isMoved()) {
            update_option(self::MOVED_OPTION, time(), false);
            update_option(self::MOVED_OPTION . '_reason', self::GAP_REASON, false);
            $this->gaps->openGlobal(self::GAP_REASON);
        }
    }

    public function isMoved(): bool
    {
        return (int) get_option(self::MOVED_OPTION, 0) > 0;
    }

    /** Why the history may not match this database: site_moved or database_restored. */
    public function reason(): ?string
    {
        if (!$this->isMoved()) {
            return null;
        }

        return get_option(self::MOVED_OPTION . '_reason') === self::RESTORED_REASON ? self::RESTORED_REASON : self::GAP_REASON;
    }

    /**
     * The reconciliation check found most items changed at once: the database was most
     * likely restored from a backup. Same choice as for a copy: keep or clear (FR-8.7.3).
     */
    public function flagRestoredBackup(): void
    {
        if (!$this->isMoved()) {
            update_option(self::MOVED_OPTION, time(), false);
            update_option(self::MOVED_OPTION . '_reason', self::RESTORED_REASON, false);
        }

        $this->gaps->openGlobal(self::RESTORED_REASON);
    }

    /** The history belongs to this site from now on ("Keep history", or after clearing it). */
    public function accept(): void
    {
        update_option(self::OPTION, $this->current(), false);
        delete_option(self::MOVED_OPTION);
        delete_option(self::MOVED_OPTION . '_reason');
        $this->gaps->closeGlobal(self::GAP_REASON);
        $this->gaps->closeGlobal(self::RESTORED_REASON);
    }

    public function current(): string
    {
        return hash('sha256', implode("\n", [
            untrailingslashit((string) home_url()),
            defined('DB_NAME') ? (string) DB_NAME : '',
            $this->db->prefix,
            (string) get_option(self::INSTANCE_OPTION, ''),
        ]));
    }
}
