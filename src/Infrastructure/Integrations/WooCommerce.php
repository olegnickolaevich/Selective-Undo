<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Integrations;

use SelectiveUndo\Domain\Contracts\FieldDefinition;

/**
 * WooCommerce products. Their titles, descriptions, status, categories, tags and
 * images are post fields and terms, recorded once "Products" is a recorded content
 * type. This adds the regular price, the sale price and the SKU of products and
 * of their variations (recorded together with the products), and refreshes what
 * WooCommerce derives from them right after a restore: the active price, the price
 * range of a variable product, the lookup table and the product caches.
 *
 * Stock is not recorded: it changes with every order.
 */
final class WooCommerce
{
    private const PRODUCT_TYPES = ['product', 'product_variation'];
    private const PRICE_META = ['_regular_price', '_sale_price'];

    public static function available(): bool
    {
        return class_exists(\WooCommerce::class) && function_exists('wc_get_product');
    }

    public function register(): void
    {
        add_filter('selective_undo/meta_fields', [$this, 'fields'], 10, 2);
        add_filter('selective_undo/tracked_post_types', [$this, 'withVariations']);
        add_action('selective_undo/object_restored', [$this, 'afterRestore']);
    }

    /**
     * @param list<mixed> $fields
     *
     * @return list<mixed>
     */
    public function fields(array $fields, string $postType): array
    {
        if (!in_array($postType, self::PRODUCT_TYPES, true)) {
            return $fields;
        }

        $fields[] = new FieldDefinition('meta:_regular_price', __('Regular price', 'selective-undo'), 'text', group: 'WooCommerce');
        $fields[] = new FieldDefinition('meta:_sale_price', __('Sale price', 'selective-undo'), 'text', group: 'WooCommerce');
        $fields[] = new FieldDefinition('meta:_sku', __('SKU', 'selective-undo'), 'text', group: 'WooCommerce');

        return $fields;
    }

    /**
     * Variations have no screen of their own: they are recorded with their products.
     *
     * @param array<mixed> $types
     *
     * @return array<mixed>
     */
    public function withVariations(array $types): array
    {
        if (in_array('product', $types, true) && !in_array('product_variation', $types, true)) {
            $types[] = 'product_variation';
        }

        return $types;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function afterRestore(array $payload): void
    {
        $id = (int) ($payload['object_id'] ?? 0);
        $fields = array_map('strval', (array) ($payload['fields'] ?? []));

        if (($payload['object_type'] ?? '') !== 'post' || !in_array((string) ($payload['object_subtype'] ?? ''), self::PRODUCT_TYPES, true)) {
            return;
        }

        wc_delete_product_transients($id);
        $product = wc_get_product($id);

        if (!$product instanceof \WC_Product) {
            return;
        }

        if (array_intersect($fields, array_map(static fn (string $k): string => 'meta:' . $k, self::PRICE_META)) !== []) {
            // The active price, as WooCommerce derives it when a regular or sale price is saved
            // (WC_Product_Data_Store_CPT::handle_updated_props()).
            update_post_meta($id, '_price', $product->is_on_sale('edit') ? $product->get_sale_price('edit') : $product->get_regular_price('edit'));
        }

        $store = $product->get_data_store();

        // WooCommerce 10.8+; the data store wrapper forwards the call (and ignores it before).
        if (is_object($store) && is_callable([$store, 'refresh_product_lookup_table'])) {
            $store->refresh_product_lookup_table($id);
        }

        wc_delete_product_transients($id);

        if ($product instanceof \WC_Product_Variation && $product->get_parent_id() > 0) {
            // The price range of the variable product.
            \WC_Product_Variable::sync($product->get_parent_id());
            wc_delete_product_transients($product->get_parent_id());
        }
    }
}
