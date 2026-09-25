<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Domain\Change\ObjectRef;

/**
 * Lets integrations (for example an editorial workflow) forbid a restore.
 * Evaluated while building the plan and again before the write, outside transactions.
 */
final class RestorePreconditions
{
    public function check(ObjectRef $object, string $field, int $actorUserId): ?string
    {
        /**
         * Return a reason code (lowercase letters, digits, underscores) to block the restore.
         *
         * @param string|null $reason      Null to allow.
         * @param ObjectRef   $object      Object being restored.
         * @param string      $field       Field key.
         * @param int         $actorUserId User who requested the restore.
         */
        $reason = apply_filters('selective_undo/restore_preconditions', null, $object, $field, $actorUserId);

        return is_string($reason) && preg_match('/^[a-z0-9_]{1,64}$/', $reason) === 1 ? $reason : null;
    }
}
