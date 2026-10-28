<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Tests\IntegrationTestCase;

/**
 * Adapters of third-party plugins, against the real plugins (installed on the test
 * site in one CI configuration; skipped where they are not active).
 */
final class IntegrationsTest extends IntegrationTestCase
{
    public function testAioseoFieldsAreRecordedAndOneOfThemUndone(): void
    {
        if (!function_exists('aioseo')) {
            return;
        }

        $save = static fn (int $id, array $data) => \AIOSEO\Plugin\Common\Models\Post::savePost($id, $data);
        $id = $this->createPost();
        $save($id, ['title' => 'Contacts | Shop', 'description' => 'Call us']);
        $this->endRequest();
        $save($id, ['title' => 'Contacts | Shop OLD']);
        $this->endRequest();
        $titleChange = $this->changeOf($id, 'aioseo:title');
        $save($id, ['description' => 'Call or write to us']);
        $this->endRequest();

        $rows = array_values(array_filter($this->changes($id, 'update'), static fn (array $r): bool => str_starts_with((string) $r['field_key'], 'aioseo:')));
        $this->assertSame(['aioseo:title', 'aioseo:description', 'aioseo:title', 'aioseo:description'], array_column($rows, 'field_key'));
        $this->assertSame('All in One SEO: SEO title', $this->s->adapters()->fieldLabel('aioseo:title'));

        $job = $this->start($this->plan(['type' => 'changes', 'change_ids' => [$titleChange]]));
        $this->assertSame('completed', $job['status']);

        $row = $this->db->get_row($this->db->prepare("SELECT title, description FROM {$this->db->prefix}aioseo_posts WHERE post_id = %d", $id), ARRAY_A);
        $this->assertSame(['title' => 'Contacts | Shop', 'description' => 'Call or write to us'], $row, 'only the title goes back');
        $this->assertSame('Contacts | Shop', get_post_meta($id, '_aioseo_title', true), 'the copy for translation plugins too');
        $this->assertSame('Contacts | Shop', \AIOSEO\Plugin\Common\Models\Post::getPost($id)->title, 'AIOSEO reads the restored value');
    }

    public function testElementorWidgetEditIsUndoneKeepingLaterEditsOfOtherWidgets(): void
    {
        if (!defined('ELEMENTOR_VERSION')) {
            return;
        }

        $id = $this->createPost(['post_type' => 'page']);
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        $save = static function (int $id, string $heading, string $text): void {
            $document = \Elementor\Plugin::$instance->documents->get($id, false);
            $document->save(['elements' => [[
                'id' => 'a1b2c3d', 'elType' => 'container', 'settings' => [], 'isInner' => false, 'elements' => [
                    ['id' => '1234567', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => $heading], 'elements' => []],
                    ['id' => 'e5f6a7b', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => ['editor' => $text], 'elements' => []],
                ],
            ]]]);
        };
        $save($id, 'Welcome', '<p>Our shop</p>');
        $this->endRequest();
        $save($id, 'Welcome OLD', '<p>Our shop</p>');
        $this->endRequest();
        $headingChange = $this->changeOf($id, 'elementor:data');
        $save($id, 'Welcome OLD', '<p>Our shop is open</p>');
        $this->endRequest();
        // What the next view generates from the layout.
        update_post_meta($id, '_elementor_css', ['status' => 'file']);
        update_post_meta($id, '_elementor_element_cache', wp_slash((string) wp_json_encode(['timeout' => time() + 3600, 'value' => []])));

        $this->assertSame('Elementor: Layout', $this->s->adapters()->fieldLabel('elementor:data'));
        $job = $this->start($this->plan(['type' => 'changes', 'change_ids' => [$headingChange]]));
        $this->assertSame('completed', $job['status']);

        $data = json_decode((string) get_post_meta($id, '_elementor_data', true), true);
        $widgets = $data[0]['elements'];
        $this->assertSame('Welcome', $widgets[0]['settings']['title'], 'the heading goes back');
        $this->assertSame('1234567', $widgets[0]['id'], 'a numeric-looking ID stays a string');
        $this->assertSame('<p>Our shop is open</p>', $widgets[1]['settings']['editor'], 'the later text edit stays');
        $this->assertSame('', get_post_meta($id, '_elementor_css', true), 'CSS is built again from the restored layout');
        $this->assertSame('', get_post_meta($id, '_elementor_element_cache', true), 'so is the element cache');
        $this->assertTrue(str_contains((string) \Elementor\Plugin::$instance->frontend->get_builder_content($id), '>Welcome<'), 'the page shows it');
        $text = (string) get_post_field('post_content', $id, 'raw');
        $this->assertTrue(str_contains($text, 'Welcome') && !str_contains($text, 'Welcome OLD'), 'the text copy for search and SEO follows: ' . $text);
        $copy = $this->updateIds($id, 'post_content');
        $source = $this->db->get_var($this->db->prepare(
            "SELECT s.source FROM {$this->s->tables()->changes} c JOIN {$this->s->tables()->changesets} s ON s.id = c.changeset_id WHERE c.id = %d",
            end($copy)
        ));
        $this->assertSame('restore', $source, 'recorded as part of the restore, not as an editor save');
    }

    private function changeOf(int $postId, string $field): int
    {
        $ids = $this->updateIds($postId, $field);

        return (int) end($ids);
    }
}
