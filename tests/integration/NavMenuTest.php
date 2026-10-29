<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Tests\IntegrationTestCase;

final class NavMenuTest extends IntegrationTestCase
{
    private int $menu = 0;

    public function tearDown(): void
    {
        parent::tearDown();

        if ($this->menu > 0) {
            wp_delete_nav_menu($this->menu);
        }
    }

    public function testOneMenuEditIsUndoneKeepingLaterEditsAndADeletedItemComesBack(): void
    {
        $this->menu = (int) wp_create_nav_menu('Main ' . wp_generate_password(6, false));
        $page = $this->createPost(['post_title' => 'Contacts']);
        $add = fn (array $args): int => (int) wp_update_nav_menu_item($this->menu, 0, $args + ['menu-item-status' => 'publish']);
        $a = $add(['menu-item-title' => 'Catalog', 'menu-item-url' => 'https://example.test/catalog', 'menu-item-type' => 'custom']);
        $b = $add(['menu-item-object-id' => $page, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type']);
        $c = $add(['menu-item-title' => 'Delivery', 'menu-item-url' => 'https://example.test/delivery', 'menu-item-type' => 'custom', 'menu-item-parent-id' => $a]);
        $this->save();

        // 1. Rename "Catalog" (one save of the menu screen updates every item).
        wp_update_nav_menu_item($this->menu, $a, ['menu-item-title' => 'Products', 'menu-item-url' => 'https://example.test/catalog', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => 1]);
        $this->save();
        $rename = $this->lastChange();
        // 2. Add "Sale" (at the end). 3. Remove the page.
        $d = $add(['menu-item-title' => 'Sale', 'menu-item-url' => 'https://example.test/sale', 'menu-item-type' => 'custom']);
        $this->save();
        wp_delete_post($b, true);
        $this->save();
        $removal = $this->lastChange();

        $this->assertSame('Menu items', $this->s->adapters()->fieldLabel('items', 'core/nav-menus'));
        $this->assertSame('completed', $this->start($this->plan(['type' => 'changes', 'change_ids' => [$rename]]))['status']);
        $this->assertSame(['Catalog', 'Delivery', 'Sale'], $this->titles(), 'the rename is undone, the later edits stay');

        $this->assertSame('completed', $this->start($this->plan(['type' => 'changes', 'change_ids' => [$removal]]))['status']);
        $items = wp_get_nav_menu_items($this->menu);
        $this->assertSame(['Catalog', 'Contacts', 'Delivery', 'Sale'], $this->titles(), 'the page is back in its place');
        $this->assertContains($b, array_map(static fn ($i): int => (int) $i->ID, $items), 'under its own ID');
        $this->assertSame((string) $a, (string) $items[2]->menu_item_parent, 'the submenu still hangs under its parent');
        $this->assertTrue($d > 0);

        // The merged value reads back as written: the next edit continues the history.
        $add(['menu-item-title' => 'Blog', 'menu-item-url' => 'https://example.test/blog', 'menu-item-type' => 'custom']);
        $this->save();
        $this->assertSame('0', (string) $this->db->get_var("SELECT COUNT(*) FROM {$this->s->tables()->gaps} WHERE reason = 'external_write_detected'"));

        // Undoing the addition of a parent added later under it keeps the child, at the top level.
        $services = $add(['menu-item-title' => 'Services', 'menu-item-url' => 'https://example.test/services', 'menu-item-type' => 'custom']);
        $this->save();
        $addition = $this->lastChange();
        $repair = $add(['menu-item-title' => 'Repair', 'menu-item-url' => 'https://example.test/repair', 'menu-item-type' => 'custom', 'menu-item-parent-id' => $services]);
        $this->save();
        $this->assertSame('completed', $this->start($this->plan(['type' => 'changes', 'change_ids' => [$addition]]))['status']);
        $this->assertNotContains('Services', $this->titles());
        $this->assertSame('0', (string) get_post_meta($repair, '_menu_item_menu_item_parent', true));

        // Only who may edit menus sees this history.
        $this->assertSame('Main', substr((string) $this->s->history()->objectTimeline(new ObjectRef('menu', $this->menu), 1, null, 5)['object']['title'], 0, 4));
        $this->assertFalse(user_can($this->userId('author'), 'sundo_view_object_history', $this->menu, 'menu:nav'));
    }

    private function save(): void
    {
        $this->s->navMenuAdapter()->flush($this->s->capture()); // what the end of the request does
        $this->endRequest();
    }

    private function lastChange(): int
    {
        return (int) $this->db->get_var($this->db->prepare(
            "SELECT MAX(id) FROM {$this->s->tables()->changes} WHERE object_type = 'menu' AND object_id = %d",
            $this->menu
        ));
    }

    /**
     * @return list<string>
     */
    private function titles(): array
    {
        wp_cache_flush();

        return array_map(static fn ($i): string => (string) $i->title, (array) wp_get_nav_menu_items($this->menu));
    }
}
