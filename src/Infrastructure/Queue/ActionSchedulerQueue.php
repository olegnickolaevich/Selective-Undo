<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Queue;

/**
 * Action Scheduler (bundled with WooCommerce and other plugins): its own runner,
 * retries and a log under Tools → Scheduled Actions.
 */
final class ActionSchedulerQueue implements JobQueue
{
    public const GROUP = 'selective-undo';

    /** Whether Action Scheduler is loaded and ready to store actions. */
    public static function available(): bool
    {
        return function_exists('as_enqueue_async_action') && function_exists('as_schedule_single_action')
            && function_exists('as_has_scheduled_action') && function_exists('as_unschedule_all_actions')
            && did_action('action_scheduler_init') > 0;
    }

    public function enqueue(string $hook, array $args = [], int $delaySeconds = 0): void
    {
        if ($this->isScheduled($hook, $args)) {
            return;
        }

        $delaySeconds > 0
            ? as_schedule_single_action(time() + $delaySeconds, $hook, $args, self::GROUP, true)
            : as_enqueue_async_action($hook, $args, self::GROUP, true);
    }

    public function isScheduled(string $hook, array $args = []): bool
    {
        return as_has_scheduled_action($hook, $args, self::GROUP);
    }

    public function clear(string $hook): void
    {
        // Hook only: with a group, Action Scheduler would cancel only the calls without arguments.
        as_unschedule_all_actions($hook);
    }

    public function name(): string
    {
        return 'action-scheduler';
    }
}
