<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Tests\IntegrationTestCase;

/**
 * Against the real WooCommerce (installed on the test site in one CI configuration).
 */
final class WooCommerceTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        if (class_exists(\WooCommerce::class)) {
            // Right after WooCommerce was activated from the command line (as in CI), its
            // roles are created by the first request that follows: this one.
            if (!user_can(1, 'edit_products') && class_exists(\WC_Install::class)) {
                \WC_Install::create_roles();
                wp_roles()->for_site(); // add_cap() leaves the role objects of this request as they were
                wp_set_current_user(1);
            }

            $this->s->settings()->update(['tracked_post_types' => ['post', 'page', 'attachment', 'product']]);
            $this->s->metaFields()->flush();
        }
    }

    public function testPriceChangeIsUndoneAndWhatWooCommerceDerivesFollows(): void
    {
        if (!class_exists(\WooCommerce::class)) {
            return;
        }

        $product = new \WC_Product_Simple();
        $product->set_name('Pump');
        $product->set_regular_price('100');
        $product->set_sku('PUMP-' . wp_generate_password(6, false));
        $product->set_stock_quantity(5);
        $product->set_manage_stock(true);
        $id = $product->save();
        $this->endRequest();

        $product = wc_get_product($id);
        $product->set_regular_price('120');
        $product->save();
        $this->endRequest();
        // A sale lowers the stock: not recorded.
        wc_update_product_stock($id, 4, 'set');
        $this->endRequest();

        $fields = array_column($this->changes($id, 'update'), 'field_key');
        $this->assertContains('meta:_regular_price', $fields);
        $this->assertNotContains('meta:_stock', $fields);
        $this->assertSame('WooCommerce: Regular price', $this->s->adapters()->fieldLabel('meta:_regular_price', 'core/post-meta', new \SelectiveUndo\Domain\Change\ObjectRef('post', $id, 'product')));

        $change = (int) array_values(array_filter($this->changes($id, 'update'), static fn (array $r): bool => $r['field_key'] === 'meta:_regular_price'))[1]['id']; // [0]: set when the product was created
        $uuid = $this->plan(['type' => 'changes', 'change_ids' => [$change]]);
        $item = $this->planView($uuid)['items'][0];
        $this->assertSame('ready', $item['status'], (string) $item['reason_code']);
        $this->assertSame('completed', $this->start($uuid)['status']);

        wp_cache_flush();
        $this->assertSame('100', wc_get_product($id)->get_price(), 'the active price follows');
        $this->assertSame('100', (string) get_post_meta($id, '_price', true));
        $this->assertSame(100.0, (float) $this->db->get_var($this->db->prepare("SELECT min_price FROM {$this->db->prefix}wc_product_meta_lookup WHERE product_id = %d", $id)), 'so does the lookup table');
        $this->assertSame(4, wc_get_product($id)->get_stock_quantity(), 'the stock is left alone');
    }

    public function testUndoingASaleDateChangeTakesTheActivePriceBack(): void
    {
        if (!class_exists(\WooCommerce::class)) {
            return;
        }

        $product = new \WC_Product_Simple();
        $product->set_name('Hose');
        $product->set_regular_price('100');
        $product->set_sale_price('90');
        $product->set_date_on_sale_from((string) (time() + DAY_IN_SECONDS)); // the sale starts tomorrow
        $id = $product->save();
        $this->endRequest();
        $this->assertSame('100', wc_get_product($id)->get_price());

        $product = wc_get_product($id);
        $product->set_date_on_sale_from((string) (time() - DAY_IN_SECONDS)); // moved to yesterday: on sale now
        $product->save();
        $this->endRequest();
        $this->assertSame('90', wc_get_product($id)->get_price());

        $rows = array_values(array_filter($this->changes($id, 'update'), static fn (array $r): bool => $r['field_key'] === 'meta:_sale_price_dates_from'));
        $this->assertSame('completed', $this->start($this->plan(['type' => 'changes', 'change_ids' => [(int) end($rows)['id']]]))['status']);

        wp_cache_flush();
        $this->assertSame('100', wc_get_product($id)->get_price(), 'not on sale yet again');
    }

    public function testVariationPriceIsUndoneAndThePriceRangeFollows(): void
    {
        if (!class_exists(\WooCommerce::class)) {
            return;
        }

        $parent = new \WC_Product_Variable();
        $parent->set_name('Filter');
        $parentId = $parent->save();
        $ids = [];

        foreach (['10', '20'] as $price) {
            $variation = new \WC_Product_Variation();
            $variation->set_parent_id($parentId);
            $variation->set_regular_price($price);
            $ids[] = $variation->save();
        }

        \WC_Product_Variable::sync($parentId);
        $this->endRequest();

        $variation = wc_get_product($ids[0]);
        $variation->set_regular_price('15');
        $variation->save();
        \WC_Product_Variable::sync($parentId);
        $this->endRequest();
        $this->assertSame(15.0, (float) wc_get_product($parentId)->get_variation_price('min'));

        $change = (int) array_values(array_filter($this->changes($ids[0], 'update'), static fn (array $r): bool => $r['field_key'] === 'meta:_regular_price'))[1]['id']; // [0]: set when the product was created
        $this->assertSame('completed', $this->start($this->plan(['type' => 'changes', 'change_ids' => [$change]]))['status']);

        wp_cache_flush();
        $this->assertSame('10', wc_get_product($ids[0])->get_price());
        $this->assertSame(10.0, (float) wc_get_product($parentId)->get_variation_price('min'), 'the price range of the product follows');
    }
}
