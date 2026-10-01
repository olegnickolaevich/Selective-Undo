<?php

declare(strict_types=1);

namespace SelectiveUndo\Presentation\Admin;

use SelectiveUndo\Application\Restore\AdapterRegistry;
use SelectiveUndo\Infrastructure\Storage\JournalReader;
use SelectiveUndo\Infrastructure\WordPress\Capabilities;

/**
 * "Last change" column on list screens of tracked post types. The data for all rows
 * of the page is read with one query on the first rendered cell.
 */
final class LastChangeColumn
{
    public const COLUMN = 'selective_undo_last_change';

    /** @var array<int, array{id: int, field_key: string, fields: list<string>, event: string, created_at: string, actor: int|null}>|null */
    private ?array $latest = null;

    public function __construct(
        private readonly string $postType,
        private readonly JournalReader $reader,
        private readonly AdapterRegistry $adapters,
    ) {
    }

    public function register(): void
    {
        add_filter("manage_{$this->postType}_posts_columns", [$this, 'columns']);
        // Fires for hierarchical and flat post types alike, after the generic hooks.
        add_action("manage_{$this->postType}_posts_custom_column", [$this, 'render'], 10, 2);
    }

    /**
     * @param array<string, string> $columns
     *
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $columns[self::COLUMN] = __('Last change', 'selective-undo');

        return $columns;
    }

    public function render(string $column, int $postId): void
    {
        if ($column !== self::COLUMN) {
            return;
        }

        $this->latest ??= $this->load();
        $row = $this->latest[$postId] ?? null;

        if ($row === null || !current_user_can(Capabilities::VIEW_OBJECT, $postId)) {
            echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('No recorded changes', 'selective-undo') . '</span>';

            return;
        }

        $user = $row['actor'] !== null ? get_userdata($row['actor']) : false;
        $parts = [
            $user instanceof \WP_User ? $user->display_name : __('System', 'selective-undo'),
            /* translators: %s: time difference, e.g. "2 hours". */
            sprintf(__('%s ago', 'selective-undo'), human_time_diff((int) strtotime($row['created_at'] . ' UTC'))),
        ];

        $labels = array_map(fn (string $field): string => $this->adapters->fieldLabel($field), $row['fields']);

        if (count($labels) > 2) {
            /* translators: 1: two field names, e.g. "Title, Author", 2: number of other changed fields. */
            $labels = [sprintf(__('%1$s and %2$d more', 'selective-undo'), implode(', ', array_slice($labels, 0, 2)), count($labels) - 2)];
        }

        if ($labels !== []) {
            $parts[] = implode(', ', $labels);
        }

        printf(
            '<a href="%s">%s</a>',
            esc_url(add_query_arg(['page' => AdminPage::SLUG, 'view' => 'object', 'id' => $postId], admin_url('admin.php'))),
            esc_html(implode(' · ', $parts))
        );
    }

    /**
     * @return array<int, array{id: int, field_key: string, fields: list<string>, event: string, created_at: string, actor: int|null}>
     */
    private function load(): array
    {
        global $wp_query;
        $ids = [];

        foreach ((array) ($wp_query->posts ?? []) as $post) {
            // Hierarchical types are listed with fields => id=>parent (plain objects).
            $ids[] = is_object($post) && isset($post->ID) ? (int) $post->ID : (int) $post;
        }

        return $this->reader->latestChangeOfPosts($ids);
    }
}
