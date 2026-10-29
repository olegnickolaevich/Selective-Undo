<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Tests\IntegrationTestCase;

final class SiteOptionsTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $saved = [];

    public function setUp(): void
    {
        parent::setUp();

        foreach (['blogname', 'page_on_front', 'siteurl'] as $option) {
            $this->saved[$option] = get_option($option);
        }
    }

    public function tearDown(): void
    {
        parent::tearDown();
        remove_all_filters('selective_undo/tracked_options');

        foreach ($this->saved as $option => $value) {
            update_option($option, $value);
        }

        $this->endRequest();
    }

    public function testSiteTitleChangeIsUndoneAndOnlyAdministratorsSeeIt(): void
    {
        update_option('blogname', 'Pumps and filters');
        $this->endRequest();
        update_option('blogname', 'Pumps & filters OLD');
        update_option('siteurl', (string) get_option('siteurl')); // never recorded, even unchanged
        $this->endRequest();

        $rows = $this->siteChanges();
        $this->assertSame(['option:blogname', 'option:blogname'], array_column($rows, 'field_key'));
        $this->assertSame('site:options', $rows[1]['object_subtype']);

        $job = $this->start($this->plan(['type' => 'changes', 'change_ids' => [(int) $rows[1]['id']]]));
        $this->assertSame('completed', $job['status']);
        wp_cache_flush();
        $this->assertSame('Pumps and filters', get_option('blogname'));

        $site = new ObjectRef('site', get_current_blog_id());
        $timeline = $this->s->history()->objectTimeline($site, 1, null, 20);
        $this->assertSame('Site settings', $timeline['object']['title']);
        $this->assertSame('Site title', $timeline['items'][0]['field_label']);

        // Editors may have the history permissions, but not this object.
        $editor = $this->userId('editor');
        get_role('editor')->add_cap('sundo_view_history');

        try {
            $this->assertThrows(DomainError::class, fn () => $this->s->history()->objectTimeline($site, $editor, null, 20));
        } finally {
            get_role('editor')->remove_cap('sundo_view_history');
        }
    }

    public function testAddressesNeverRecordedEvenWhenAskedFor(): void
    {
        add_filter('selective_undo/tracked_options', static fn (array $o): array => [...$o, 'siteurl', 'my_api_key', 'posts_per_rss']);
        update_option('siteurl', (string) get_option('siteurl') . '/x');
        update_option('my_api_key', 'secret-' . wp_generate_password(6, false));
        update_option('posts_per_rss', (int) get_option('posts_per_rss') + 1);
        $this->endRequest();

        $this->assertSame(['option:posts_per_rss'], array_column($this->siteChanges(), 'field_key'));
        delete_option('my_api_key');
        update_option('posts_per_rss', (int) get_option('posts_per_rss') - 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function siteChanges(): array
    {
        return array_values((array) $this->db->get_results(
            "SELECT * FROM {$this->s->tables()->changes} WHERE object_type = 'site' ORDER BY object_seq, id",
            ARRAY_A
        ));
    }
}
