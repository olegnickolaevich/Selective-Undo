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

// WooCommerce (only what the integration uses).

class WooCommerce
{
}

class WC_Product
{
    public function is_on_sale(string $context = 'view'): bool
    {
        return false;
    }

    public function get_sale_price(string $context = 'view'): string
    {
        return '';
    }

    public function get_data_store(): ?object
    {
        return null;
    }

    public function get_regular_price(string $context = 'view'): string
    {
        return '';
    }

    public function get_parent_id(string $context = 'view'): int
    {
        return 0;
    }

    public function save(): int
    {
        return 0;
    }
}

class WC_Product_Variation extends WC_Product
{
}

class WC_Product_Variable extends WC_Product
{
    public static function sync(int $product, bool $save = true): void
    {
    }
}

/**
 * @return WC_Product|null|false
 */
function wc_get_product(mixed $the_product = false, array $deprecated = [])
{
    return null;
}

function wc_delete_product_transients(int $post_id = 0): void
{
}
