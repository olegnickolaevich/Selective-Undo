<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Queue;

/**
 * Runs a hook later, in the background: continuing restore jobs, importing
 * revisions and other work that does not fit in one request.
 *
 * @api
 */
interface JobQueue
{
    /**
     * Schedules do_action($hook, ...$args) once, unless the same call is already waiting.
     *
     * @param list<scalar> $args
     */
    public function enqueue(string $hook, array $args = [], int $delaySeconds = 0): void;

    /**
     * @param list<scalar> $args
     */
    public function isScheduled(string $hook, array $args = []): bool;

    /** Removes every waiting call of the hook (deactivation). */
    public function clear(string $hook): void;

    /** Short name shown in diagnostics. */
    public function name(): string;
}
