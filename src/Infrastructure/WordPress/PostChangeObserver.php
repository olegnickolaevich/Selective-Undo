<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

use SelectiveUndo\Application\Capture\CaptureService;
use SelectiveUndo\Application\Capture\RequestContext;
use SelectiveUndo\Domain\Change\CaptureQuality;
use SelectiveUndo\Domain\Change\ChangeEvent;
use SelectiveUndo\Domain\Change\ObjectRef;

/**
 * Captures standard post fields with "frames" around wp_insert_post() updates.
 *
 * Event order inside wp_insert_post() on update (verified on WordPress 7.1.2):
 *
 *   pre_post_update     right before $wpdb->update()        -> read B from the database
 *   [UPDATE wp_posts]
 *   (post_name fix-up, set_object_terms, meta_input: may trigger nested updates)
 *   clean_post_cache    after the write                      -> read A from the database
 *   transition_post_status, edit_post: often trigger nested wp_update_post()
 *   post_updated        frame completes                      -> journal write
 *
 * Frames are stacked per post and the sequence number is reserved when a frame
 * opens, so a nested update that completes first still gets a higher sequence
 * and the before/after chain in the journal stays continuous.
 */
final class PostChangeObserver
{
    private const MAX_FRAMES_PER_POST = 8;

    /** @var array<int, list<CaptureFrame>> */
    private array $frames = [];

    public function __construct(
        private readonly CaptureService $capture,
        private readonly TrackingPolicy $policy,
        private readonly PostRowReader $reader,
        private readonly RequestContext $request,
    ) {
    }

    public function register(): void
    {
        add_action('pre_post_update', [$this, 'onPreUpdate'], PHP_INT_MAX, 2);
        add_action('clean_post_cache', [$this, 'onCleanPostCache'], PHP_INT_MIN, 1);
        add_action('post_updated', [$this, 'onPostUpdated'], PHP_INT_MAX, 3);
        add_action('wp_insert_post', [$this, 'onInsertPost'], PHP_INT_MAX, 3);
        add_action('trashed_post', [$this, 'onTrashed'], PHP_INT_MAX, 1);
        add_action('untrashed_post', [$this, 'onUntrashed'], PHP_INT_MAX, 1);
        add_action('deleted_post', [$this, 'onDeleted'], PHP_INT_MAX, 2);
        add_action('shutdown', [$this, 'onShutdown'], 0);
        add_action('shutdown', [$this->capture, 'sealRequestChangesets'], 5);
    }

    /**
     * @param array<string, mixed> $data Data WordPress is about to write (already unslashed).
     */
    public function onPreUpdate(int|string $postId, array $data): void
    {
        $postId = (int) $postId;

        $this->guarded($postId, function () use ($postId, $data): void {
            if (!$this->policy->shouldCapture($postId, (string) ($data['post_type'] ?? '')) || !$this->capture->isActive()) {
                return;
            }

            if (count($this->frames[$postId] ?? []) >= self::MAX_FRAMES_PER_POST) {
                $this->capture->recordGap('nesting_too_deep', $postId);

                return;
            }

            // Read bypassing the object cache: $post_before in post_updated may be stale.
            $before = $this->reader->read($postId);

            if ($before === null) {
                return;
            }

            // A nested update before the outer clean_post_cache (from meta_input or
            // set_object_terms hooks). This handler runs last on pre_post_update, so
            // the outer UPDATE has already happened and the current row is exactly
            // the outer frame's "after".
            $parent = $this->top($postId);

            if ($parent !== null && $parent->after === null) {
                $parent->after = $before;
            }

            $this->frames[$postId][] = new CaptureFrame($postId, $this->request->reserveSequence(), $before);
        });
    }

    public function onCleanPostCache(int|string $postId): void
    {
        $postId = (int) $postId;
        $frame = $this->top($postId);

        if ($frame === null || $frame->after !== null) {
            return;
        }

        $this->guarded($postId, function () use ($frame, $postId): void {
            $frame->after = $this->reader->read($postId);
        });
    }

    public function onPostUpdated(int|string $postId, \WP_Post $postAfter, \WP_Post $postBefore): void
    {
        $frame = $this->pop((int) $postId);

        if ($frame === null) {
            return;
        }

        $this->guarded($frame->postId, function () use ($frame, $postAfter, $postBefore): void {
            $quality = CaptureQuality::Verified;
            $after = $frame->after;

            if ($after === null) {
                // clean_post_cache was not observed: fall back to hook data.
                $after = PostRow::fromPost($postAfter);
                $quality = CaptureQuality::Observed;
            }

            if (!PostRow::fromPost($postBefore)->sameTrackedValues($frame->before)) {
                // WordPress built the save on a stale object cache. B was read from the
                // database and is correct; record a diagnostic signal.
                $this->capture->recordGap('stale_object_cache', $frame->postId);
            }

            $this->capture->recordPostUpdate($frame, $after, $quality);
        });
    }

    public function onInsertPost(int|string $postId, \WP_Post $post, bool $update): void
    {
        if ($update || $post->post_status === 'auto-draft') {
            return;
        }

        $this->event(ChangeEvent::Created, $post);
    }

    public function onTrashed(int|string $postId): void
    {
        $post = get_post((int) $postId);

        if ($post instanceof \WP_Post) {
            $this->event(ChangeEvent::Trashed, $post);
        }
    }

    public function onUntrashed(int|string $postId): void
    {
        $post = get_post((int) $postId);

        if ($post instanceof \WP_Post) {
            $this->event(ChangeEvent::Untrashed, $post);
        }
    }

    public function onDeleted(int|string $postId, mixed $post = null): void
    {
        if ($post instanceof \WP_Post) {
            $this->event(ChangeEvent::Deleted, $post);
        }
    }

    /**
     * Frames without post_updated: either the UPDATE failed (after === null, nothing
     * changed) or the request died after the write (after !== null, a change happened).
     */
    public function onShutdown(): void
    {
        foreach ($this->frames as $postId => $stack) {
            foreach ($stack as $frame) {
                if ($frame->after !== null) {
                    $this->guarded($postId, fn () => $this->capture->recordPostUpdate(
                        $frame,
                        $frame->after,
                        CaptureQuality::Observed
                    ));
                }
            }
        }

        $this->frames = [];
    }

    private function event(ChangeEvent $event, \WP_Post $post): void
    {
        if ($post->post_type === 'revision' || !$this->policy->shouldCapture($post->ID, $post->post_type)) {
            return;
        }

        $this->guarded($post->ID, fn () => $this->capture->recordEvent(
            $event,
            new ObjectRef('post', $post->ID, $post->post_type)
        ));
    }

    private function top(int $postId): ?CaptureFrame
    {
        $stack = $this->frames[$postId] ?? [];

        return $stack === [] ? null : $stack[count($stack) - 1];
    }

    private function pop(int $postId): ?CaptureFrame
    {
        if (($this->frames[$postId] ?? []) === []) {
            return null;
        }

        $frame = array_pop($this->frames[$postId]);

        if ($this->frames[$postId] === []) {
            unset($this->frames[$postId]);
        }

        return $frame;
    }

    /**
     * Journal errors never break the post save.
     */
    private function guarded(int $postId, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->capture->reportFailure($postId, $e);
        }
    }
}
