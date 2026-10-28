<?php

declare(strict_types=1);

namespace SelectiveUndo\Presentation\Frontend;

use SelectiveUndo\Application\History\DiffService;
use SelectiveUndo\Application\Restore\AdapterRegistry;
use SelectiveUndo\Domain\Merge\BlockSegmenter;
use SelectiveUndo\Domain\Merge\Lcs;
use SelectiveUndo\Infrastructure\Storage\JournalReader;
use SelectiveUndo\Infrastructure\WordPress\Capabilities;
use SelectiveUndo\Infrastructure\WordPress\TrackingPolicy;
use SelectiveUndo\Presentation\Admin\AdminPage;

/**
 * Front end, for users who may see an item's history: an admin bar entry with the
 * latest change, and ?su_changes=<change id> to outline the top-level blocks of the
 * post content that this change edited.
 *
 * Blocks are counted only while the post's own the_content runs, so blocks of the
 * theme template (header, footer) are never outlined.
 */
final class ChangeIndicator
{
    public const QUERY_ARG = 'su_changes';

    private int $postId = 0;
    private string $field = '';
    /** @var array<int, true> Indexes of top-level blocks (in rendering order) to outline. */
    private array $changed = [];
    private bool $hasBlocks = false;
    private bool $changedSince = false;
    private int $contentLevel = 0;
    private bool $active = false;
    private int $depth = 0;
    private int $index = -1;

    public function __construct(
        private readonly JournalReader $reader,
        private readonly DiffService $diffs,
        private readonly TrackingPolicy $policy,
        private readonly AdapterRegistry $adapters,
    ) {
    }

    public function register(): void
    {
        add_action('admin_bar_menu', [$this, 'adminBar'], 80);
        add_action('template_redirect', [$this, 'prepare']);
    }

    private function viewedPost(): ?\WP_Post
    {
        $post = get_queried_object();

        if (is_admin() || !is_singular() || !$post instanceof \WP_Post || !$this->policy->isTrackedPostType($post->post_type)
            || !current_user_can(Capabilities::VIEW_OBJECT, $post->ID)) {
            return null;
        }

        return $post;
    }

    public function adminBar(\WP_Admin_Bar $bar): void
    {
        $post = $this->viewedPost();
        $latest = $post !== null ? $this->reader->latestFieldChange('post', $post->ID) : null;

        if ($post === null || $latest === null) {
            return;
        }

        $show = add_query_arg(self::QUERY_ARG, $latest['id'], (string) get_permalink($post));
        $bar->add_node([
            'id' => 'selective-undo',
            'title' => esc_html(sprintf(
                /* translators: %s: time difference, e.g. "2 hours". */
                __('Changed %s ago', 'selective-undo'),
                human_time_diff((int) strtotime($latest['created_at'] . ' UTC'))
            )),
            'href' => $show,
        ]);
        $bar->add_node([
            'parent' => 'selective-undo',
            'id' => 'selective-undo-show',
            'title' => esc_html__('Show the latest change on this page', 'selective-undo'),
            'href' => $show,
        ]);
        $bar->add_node([
            'parent' => 'selective-undo',
            'id' => 'selective-undo-history',
            'title' => esc_html__('History of this item', 'selective-undo'),
            'href' => add_query_arg(['page' => AdminPage::SLUG, 'view' => 'object', 'id' => $post->ID], admin_url('admin.php')),
        ]);

        if (current_user_can(Capabilities::RESTORE_CHANGES)) {
            // The operation page checks the undo first; nothing is undone from here.
            $bar->add_node([
                'parent' => 'selective-undo',
                'id' => 'selective-undo-undo',
                'title' => esc_html__('Undo the latest change…', 'selective-undo'),
                'href' => add_query_arg(['page' => AdminPage::SLUG, 'view' => 'changeset', 'id' => $latest['changeset']], admin_url('admin.php')),
            ]);
        }
    }

    public function prepare(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view, shown only to users who may see this history.
        $changeId = isset($_GET[self::QUERY_ARG]) ? absint($_GET[self::QUERY_ARG]) : 0;
        $post = $changeId > 0 ? $this->viewedPost() : null;

        if ($post === null) {
            return; // anonymous visitors and other users get the normal page
        }

        $values = $this->diffs->changeValues($changeId, get_current_user_id());

        if ($values === null || $values['object_id'] !== $post->ID) {
            return;
        }

        // The outline is personal: keep it out of page caches.
        nocache_headers();
        $this->postId = $post->ID;
        $this->field = $values['field'];

        if ($values['field'] === 'post_content' && $values['before'] !== null && $values['after'] !== null) {
            $this->locate($values['before'], $values['after'], (string) $post->post_content);
        }

        add_filter('the_content', [$this, 'enterContent'], 8);
        add_filter('the_content', [$this, 'leaveContent'], 10);
        add_filter('pre_render_block', [$this, 'beforeBlock'], PHP_INT_MAX, 2);
        add_filter('render_block', [$this, 'afterBlock'], 10, 2);
        add_action('wp_footer', [$this, 'banner']);
    }

    /**
     * Top-level blocks of the current content that hold what this change wrote.
     */
    private function locate(string $before, string $after, string $current): void
    {
        $segmenter = new BlockSegmenter();
        $beforeSegments = $segmenter->segments($before);
        $afterSegments = $segmenter->segments($after);
        $currentSegments = $segmenter->segments($current);

        if ($beforeSegments === null || $afterSegments === null || $currentSegments === null) {
            return;
        }

        $blockOf = [];
        $block = -1;

        foreach ($currentSegments as $i => $segment) {
            if (preg_match('/^<!--\s+wp:/', $segment) === 1) {
                $blockOf[$i] = ++$block;
            }
        }

        $this->hasBlocks = $blockOf !== [];
        $this->changedSince = $afterSegments !== $currentSegments;
        // Segments of "after" without a counterpart in "before" were written by this change...
        $unchanged = Lcs::matches($afterSegments, $beforeSegments) ?? [];
        // ...and are outlined where they still exist unchanged in the current content.
        $toCurrent = Lcs::matches($afterSegments, $currentSegments) ?? [];

        foreach (array_keys($afterSegments) as $a) {
            if (!isset($unchanged[$a]) && isset($toCurrent[$a], $blockOf[$toCurrent[$a]])) {
                $this->changed[$blockOf[$toCurrent[$a]]] = true;
            }
        }
    }

    public function enterContent(mixed $content): mixed
    {
        // Only the queried post's content, and not a nested the_content (query loops).
        if (++$this->contentLevel === 1 && in_the_loop() && get_the_ID() === $this->postId) {
            $this->active = true;
            $this->depth = 0;
            $this->index = -1;
        }

        return $content;
    }

    public function leaveContent(mixed $content): mixed
    {
        if (--$this->contentLevel === 0) {
            $this->active = false;
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $block
     */
    public function beforeBlock(?string $pre, array $block): ?string
    {
        // Runs last: a short-circuited block is not rendered, so render_block will not follow.
        if ($this->active && $pre === null && $this->depth++ === 0 && ($block['blockName'] ?? null) !== null) {
            $this->index++;
        }

        return $pre;
    }

    /**
     * @param array<string, mixed> $block
     */
    public function afterBlock(string $html, array $block): string
    {
        if (!$this->active || $this->depth === 0 || --$this->depth > 0 || !isset($this->changed[$this->index])) {
            return $html;
        }

        $tags = new \WP_HTML_Tag_Processor($html);

        if ($tags->next_tag()) {
            $tags->add_class('su-changed');
            $html = $tags->get_updated_html();
        }

        return $html;
    }

    public function banner(): void
    {
        $count = count($this->changed);
        $message = match (true) {
            $this->field !== 'post_content' => sprintf(
                /* translators: %s: field name, e.g. "Title". */
                __('This change edited the %s, which is not outlined on the page.', 'selective-undo'),
                $this->adapters->fieldLabel($this->field)
            ),
            !$this->hasBlocks => __('This content is not made of blocks, so the changed parts cannot be outlined.', 'selective-undo'),
            $count === 0 => __('The text written by this change is no longer on the page.', 'selective-undo'),
            default => sprintf(
                /* translators: %d: number of outlined blocks. */
                _n('%d block changed by this edit is outlined.', '%d blocks changed by this edit are outlined.', $count, 'selective-undo'),
                $count
            ),
        };

        if ($this->changedSince && $this->field === 'post_content') {
            $message .= ' ' . __('The content was changed again after this edit.', 'selective-undo');
        }

        echo '<style>.su-changed{outline:2px dashed #d63638;outline-offset:4px}'
            . '.su-changes-banner{position:fixed;inset-inline:16px;bottom:16px;z-index:99999;max-width:40em;margin-inline:auto;'
            . 'background:#1e1e1e;color:#fff;padding:12px 16px;border-radius:4px;font:14px/1.5 system-ui,sans-serif}'
            . '.su-changes-banner a{color:#9ec2e6}</style>';
        printf(
            '<div class="su-changes-banner" role="status">%s <a href="%s">%s</a></div>',
            esc_html($message),
            esc_url(remove_query_arg(self::QUERY_ARG)),
            esc_html__('Hide', 'selective-undo')
        );
    }
}
