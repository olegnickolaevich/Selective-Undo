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
            $this->gaps->openGlobal(self::GAP_REASON);
        }
    }

    public function isMoved(): bool
    {
        return (int) get_option(self::MOVED_OPTION, 0) > 0;
    }

    /** The history belongs to this site from now on ("Keep history", or after clearing it). */
    public function accept(): void
    {
        update_option(self::OPTION, $this->current(), false);
        delete_option(self::MOVED_OPTION);
        $this->gaps->closeGlobal(self::GAP_REASON);
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
