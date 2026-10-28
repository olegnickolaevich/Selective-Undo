<?php

/**
 * Functions of optional integrations, for static analysis only.
 */

/**
 * @param array<string, mixed> $filter
 *
 * @return list<array<string, mixed>>
 */
function acf_get_field_groups(array $filter = []): array
{
    return [];
}

/**
 * @param array<string, mixed>|int|string $parent
 *
 * @return list<array<string, mixed>>|false
 */
function acf_get_fields($parent)
{
    return [];
}

/**
 * Action Scheduler (WooCommerce and other plugins).
 *
 * @param array<mixed> $args
 */
function as_enqueue_async_action(string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int
{
    return 0;
}

/**
 * @param array<mixed> $args
 */
function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false, int $priority = 10): int
{
    return 0;
}

/**
 * @param array<mixed>|null $args
 */
function as_has_scheduled_action(string $hook, ?array $args = null, string $group = ''): bool
{
    return false;
}

/**
 * @param array<mixed> $args
 */
function as_unschedule_all_actions(string $hook, array $args = [], string $group = ''): void
{
}
