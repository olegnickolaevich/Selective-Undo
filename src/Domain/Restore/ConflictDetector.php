<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

/**
 * Three-way comparison over hashes of canonical values.
 *
 * expected (A) — value the field must have now (after of the last change of the chain);
 * target   (B) — value we return to (before of the first change of the chain);
 * current  (C) — value read from the database right now.
 */
final class ConflictDetector
{
    public function compare(string $currentHash, string $expectedHash, string $targetHash): Comparison
    {
        // Target is checked first: for a net-zero chain (A == B) the field is
        // already in the desired state rather than "ready".
        if ($currentHash === $targetHash) {
            return Comparison::AlreadyRestored;
        }

        if ($currentHash === $expectedHash) {
            return Comparison::Ready;
        }

        return Comparison::Conflict;
    }
}
