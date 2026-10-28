<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

use SelectiveUndo\Domain\Contracts\FieldDefinition;

/**
 * Post meta keys that are recorded, per post type: the featured image and the
 * image alt text always; SEO fields of Yoast SEO, Rank Math and SEOPress and the
 * fields of ACF field groups when those plugins are active; then the
 * selective_undo/meta_fields filter. Nothing else is recorded.
 */
final class MetaFieldRegistry
{
    /** Keys WordPress manages itself: never recorded or restored. */
    public const NEVER = [
        '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme',
        '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_desired_post_slug', '_wp_trash_meta_comments_status',
        '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes',
    ];

    /**
     * ACF field types whose value is stored under the field name itself. Password
     * fields are left out: history would keep and show the passwords.
     */
    private const ACF_SIMPLE_TYPES = [
        'text', 'textarea', 'number', 'range', 'email', 'url', 'wysiwyg', 'oembed', 'image', 'file',
        'gallery', 'select', 'checkbox', 'radio', 'button_group', 'true_false', 'link', 'post_object', 'page_link',
        'relationship', 'taxonomy', 'user', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker',
    ];

    /** @var array<string, array<string, FieldDefinition>> post type => meta key => definition */
    private array $cache = [];

    /**
     * @return list<FieldDefinition>
     */
    public function definitions(string $postType): array
    {
        return array_values($this->forType($postType));
    }

    public function definition(string $postType, string $metaKey): ?FieldDefinition
    {
        return $this->forType($postType)[$metaKey] ?? null;
    }

    public function isTracked(string $postType, string $metaKey): bool
    {
        return isset($this->forType($postType)[$metaKey]);
    }

    /** Forgets the collected fields (long-running processes, tests). */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @return array<string, FieldDefinition>
     */
    private function forType(string $postType): array
    {
        if (isset($this->cache[$postType])) {
            return $this->cache[$postType];
        }

        $definitions = [];

        foreach ($this->collect($postType) as $definition) {
            if (!$definition instanceof FieldDefinition || !str_starts_with($definition->key, 'meta:')) {
                continue;
            }

            $key = substr($definition->key, 5);

            if ($key !== '' && !in_array($key, self::NEVER, true) && !str_starts_with($key, '_wp_trash_')) {
                $definitions[$key] = $definition;
            }
        }

        // ACF field groups and post type support are known only after init.
        if (did_action('init') > 0) {
            $this->cache[$postType] = $definitions;
        }

        return $definitions;
    }

    /**
     * @return array<mixed>
     */
    private function collect(string $postType): array
    {
        $fields = [];

        if ($postType === 'attachment') {
            $fields[] = new FieldDefinition('meta:_wp_attachment_image_alt', __('Alternative text', 'selective-undo'), 'text');
        } elseif (post_type_supports($postType, 'thumbnail')) {
            $fields[] = new FieldDefinition('meta:_thumbnail_id', __('Featured image', 'selective-undo'), 'attachment');
        }

        // Several SEO plugins may be active at once: the group names the plugin.
        if (defined('WPSEO_VERSION')) {
            $fields[] = new FieldDefinition('meta:_yoast_wpseo_title', __('SEO title', 'selective-undo'), 'text', group: 'Yoast SEO');
            $fields[] = new FieldDefinition('meta:_yoast_wpseo_metadesc', __('Meta description', 'selective-undo'), 'text', group: 'Yoast SEO');
            $fields[] = new FieldDefinition('meta:_yoast_wpseo_focuskw', __('Focus keyphrase', 'selective-undo'), 'text', group: 'Yoast SEO');
            $fields[] = new FieldDefinition('meta:_yoast_wpseo_canonical', __('Canonical URL', 'selective-undo'), 'text', group: 'Yoast SEO');
            $fields[] = new FieldDefinition('meta:_yoast_wpseo_meta-robots-noindex', __('Search engine indexing', 'selective-undo'), 'text', group: 'Yoast SEO');
        }

        if (class_exists('RankMath')) {
            $fields[] = new FieldDefinition('meta:rank_math_title', __('SEO title', 'selective-undo'), 'text', group: 'Rank Math');
            $fields[] = new FieldDefinition('meta:rank_math_description', __('Meta description', 'selective-undo'), 'text', group: 'Rank Math');
            $fields[] = new FieldDefinition('meta:rank_math_focus_keyword', __('Focus keyphrase', 'selective-undo'), 'text', group: 'Rank Math');
            $fields[] = new FieldDefinition('meta:rank_math_robots', __('Search engine indexing', 'selective-undo'), 'raw', group: 'Rank Math');
            $fields[] = new FieldDefinition('meta:rank_math_canonical_url', __('Canonical URL', 'selective-undo'), 'text', group: 'Rank Math');
        }

        if (defined('SEOPRESS_VERSION')) {
            $fields[] = new FieldDefinition('meta:_seopress_titles_title', __('SEO title', 'selective-undo'), 'text', group: 'SEOPress');
            $fields[] = new FieldDefinition('meta:_seopress_titles_desc', __('Meta description', 'selective-undo'), 'text', group: 'SEOPress');
            $fields[] = new FieldDefinition('meta:_seopress_robots_index', __('Search engine indexing', 'selective-undo'), 'text', group: 'SEOPress');
        }

        if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
            array_push($fields, ...$this->acfFields($postType));
        }

        /**
         * Meta keys recorded for a post type, as FieldDefinition objects with keys "meta:<meta key>".
         * Values are stored and restored as the exact meta_value strings.
         *
         * @param list<FieldDefinition> $fields
         * @param string                $postType
         */
        return (array) apply_filters('selective_undo/meta_fields', $fields, $postType);
    }

    /**
     * @return list<FieldDefinition>
     */
    private function acfFields(string $postType): array
    {
        $group = __('Custom fields', 'selective-undo');
        $fields = [];

        foreach ((array) acf_get_field_groups(['post_type' => $postType]) as $fieldGroup) {
            foreach ((array) acf_get_fields($fieldGroup) as $field) {
                if (!is_array($field) || !in_array($field['type'] ?? '', self::ACF_SIMPLE_TYPES, true) || ($field['name'] ?? '') === '') {
                    continue;
                }

                $label = (string) ($field['label'] ?? '') !== '' ? (string) $field['label'] : (string) $field['name'];
                $type = match ($field['type']) {
                    'wysiwyg', 'textarea' => 'html',
                    'image' => 'attachment',
                    default => 'text',
                };
                $fields[] = new FieldDefinition('meta:' . $field['name'], $label, $type, group: $group);
            }
        }

        return $fields;
    }
}
