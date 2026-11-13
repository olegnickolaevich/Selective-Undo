<?php

declare(strict_types=1);

namespace SelectiveUndo;

use SelectiveUndo\Application\Capture\ChangesetEvents;
use SelectiveUndo\Application\Capture\OperationManagerInterface;
use SelectiveUndo\Bootstrap\Services;

defined('ABSPATH') || exit;

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
     * Calls $handler in the background (WP-Cron or Action Scheduler), at least once, for
     * every recorded changeset, with its summary: uuid, kind, source, actor, objects
     * ("post:123"), fields, change_count. Register it on every request; a handler that
     * throws is called again later.
     *
     * @param callable(array<string, mixed>): void $handler
     */
    public function onChangesetRecorded(callable $handler): void
    {
        $this->services->outbox()->on(ChangesetEvents::TOPIC, $handler);
    }
}
