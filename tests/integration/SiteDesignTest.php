<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Integration;

use SelectiveUndo\Tests\IntegrationTestCase;

/**
 * The Site Editor of block themes: templates, template parts and global styles.
 */
final class SiteDesignTest extends IntegrationTestCase
{
    public function testTemplatePartBlockAndGlobalStylesAreUndoneKeepingLaterEdits(): void
    {
        $part = $this->createPost([
            'post_type' => 'wp_template_part',
            'post_name' => 'header-' . wp_generate_password(6, false, false),
            'post_title' => 'Header',
            'post_content' => "<!-- wp:paragraph -->\n<p>Call us</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Open 9-18</p>\n<!-- /wp:paragraph -->",
        ]);
        wp_update_post(['ID' => $part, 'post_content' => "<!-- wp:paragraph -->\n<p>Call us now</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Open 9-18</p>\n<!-- /wp:paragraph -->"]);
        $this->endRequest();
        $first = $this->updateIds($part, 'post_content');
        wp_update_post(['ID' => $part, 'post_content' => "<!-- wp:paragraph -->\n<p>Call us now</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Open 9-20</p>\n<!-- /wp:paragraph -->"]);
        $this->endRequest();

        $this->assertSame('completed', $this->start($this->plan(['type' => 'changes', 'change_ids' => [end($first)]]))['status']);
        $this->assertTrue(str_contains(get_post($part)->post_content, '<p>Call us</p>') && str_contains(get_post($part)->post_content, 'Open 9-20'), 'one block back, the other edit kept');

        $styles = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
        $save = static fn (array $config) => wp_update_post(['ID' => $styles, 'post_content' => wp_json_encode($config)]);
        $base = ['version' => 3, 'isGlobalStylesUserThemeJSON' => true];
        $save($base + ['styles' => ['color' => ['background' => '#ffffff']]]);
        $this->endRequest();
        $save($base + ['styles' => ['color' => ['background' => '#003366']]]);
        $this->endRequest();
        $color = $this->updateIds($styles, 'post_content');
        $save($base + ['styles' => ['color' => ['background' => '#003366'], 'typography' => ['fontSize' => '18px']]]);
        $this->endRequest();

        $this->assertSame('completed', $this->start($this->plan(['type' => 'changes', 'change_ids' => [end($color)]]))['status']);
        $data = \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();
        $this->assertSame('#ffffff', $data['styles']['color']['background'], 'the colour goes back and the site sees it at once');
        $this->assertSame('18px', $data['styles']['typography']['fontSize'], 'the later typography edit stays');
    }
}
