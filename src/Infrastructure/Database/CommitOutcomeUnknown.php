<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Database;

/**
 * COMMIT failed: the transaction may or may not be committed. Never retried
 * blindly; job recovery decides from the committed job item status.
 */
final class CommitOutcomeUnknown extends \RuntimeException
{
}
