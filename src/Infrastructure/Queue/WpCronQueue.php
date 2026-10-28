<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Queue;

/**
 * Single WP-Cron events: available on every site.
 */
final class WpCronQueue implements JobQueue
{
    public function enqueue(string $hook, array $args = [], int $delaySeconds = 0): void
    {
        if (!$this->isScheduled($hook, $args)) {
            wp_schedule_single_event(time() + max(0, $delaySeconds), $hook, $args);
        }
    }

    public function isScheduled(string $hook, array $args = []): bool
    {
        return wp_next_scheduled($hook, $args) !== false;
    }

    public function clear(string $hook): void
    {
        wp_unschedule_hook($hook);
    }

    public function name(): string
    {
        return 'wp-cron';
    }
}
