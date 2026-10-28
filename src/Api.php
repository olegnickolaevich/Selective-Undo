<?php

declare(strict_types=1);

namespace SelectiveUndo;

use SelectiveUndo\Application\Capture\ChangesetEvents;
use SelectiveUndo\Application\Capture\OperationManagerInterface;
use SelectiveUndo\Bootstrap\Services;

/**
 * Public facade returned by selective_undo().
 *
 * @api
 */
final class Api
{
    public function __construct(private readonly Services $services)
    {
    }

    public function version(): string
    {
        return SELECTIVE_UNDO_API_VERSION;
    }

    public function operations(): OperationManagerInterface
    {
        return $this->services->operations();
    }

    /**
     * Receives every recorded change set in the background (WP-Cron or Action Scheduler),
     * at least once, with the summary of the selective_undo/changeset_recorded action:
     * uuid, kind, source, actor, objects ("post:123"), fields, change_count. Register on
     * every request (plugins_loaded or init). A handler that throws is called again later.
     *
     * @param callable(array<string, mixed>): void $handler
     */
    public function onChangesetRecorded(callable $handler): void
    {
        $this->services->outbox()->on(ChangesetEvents::TOPIC, $handler);
    }
}
