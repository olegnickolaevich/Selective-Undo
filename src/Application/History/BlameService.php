<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\History;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper), never printed as HTML.

use SelectiveUndo\Application\Restore\AdapterRegistry;
use SelectiveUndo\Application\Support\ObjectPresenter;
use SelectiveUndo\Application\Support\Time;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\DomainError;
use SelectiveUndo\Domain\Merge\Blame;
use SelectiveUndo\Domain\Value\InvalidPayload;
use SelectiveUndo\Infrastructure\Storage\BlobStore;
use SelectiveUndo\Infrastructure\Storage\JournalReader;
use SelectiveUndo\Infrastructure\WordPress\PostFieldsAdapter;
use SelectiveUndo\Infrastructure\WordPress\BlockTitles;

/**
 * Who last changed each top-level block of a post's content. The result is cached
 * until the content changes; values are loaded one change at a time.
 */
final class BlameService
{
    private const FIELD = 'post_content';
    private const MAX_CHANGES = 500;

    public function __construct(
        private readonly JournalReader $journal,
        private readonly BlobStore $blobs,
        private readonly AdapterRegistry $adapters,
        private readonly ObjectPresenter $presenter,
        private readonly Blame $blame = new Blame(),
    ) {
    }

    /**
     * @return array{complete: bool, blocks: list<array{index: int, name: string,
     *               change: array{id: int, hunk: int|null, created_at: string|null, actor: array{id: int, name: string}|null}|null}>}
     *
     * @throws DomainError
     */
    public function forPost(int $postId, int $userId): array
    {
        $post = get_post($postId);

        if (!$post instanceof \WP_Post || !user_can($userId, 'sundo_view_object_history', $postId, $post->post_type)) {
            throw new DomainError('su_not_found', 404, __('This item does not exist or you cannot see its history.', 'selective-undo'));
        }

        $object = new ObjectRef('post', $postId, $post->post_type);
        $current = $this->adapters->find(PostFieldsAdapter::KEY)?->read($object)?->field(self::FIELD)->value;

        if (!is_string($current)) {
            return ['complete' => false, 'blocks' => []];
        }

        $rows = $this->journal->fieldChangesNewestFirst($object, self::FIELD, self::MAX_CHANGES);
        $cacheKey = 'su_blame_' . $postId . '_' . ($rows[0]['id'] ?? 0) . '_' . substr(hash('sha256', $current), 0, 16);
        $cached = get_transient($cacheKey);

        if (is_array($cached) && isset($cached['complete'], $cached['owners'])) {
            /** @var array{complete: bool, segments: list<string>, owners: list<array{change: int, hunk: int|null}|null>} $result */
            $result = $cached;
        } else {
            $result = $this->blame->attribute($current, $this->values($rows));
            set_transient($cacheKey, $result, DAY_IN_SECONDS);
        }

        return ['complete' => $result['complete'], 'blocks' => $this->describe($result)];
    }

    /**
     * Before and after of each change, newest first, loaded only when the walk needs them.
     *
     * @param list<array{id: int, before_blob_id: int|null, after_blob_id: int|null, before_hash: string|null, after_hash: string|null}> $rows
     *
     * @return \Generator<int, array{id: int, before: string, after: string}>
     */
    private function values(array $rows): \Generator
    {
        foreach ($rows as $row) {
            try {
                $before = $this->blobs->loadVerified((int) $row['before_blob_id'], (string) $row['before_hash'])->value;
                $after = $this->blobs->loadVerified((int) $row['after_blob_id'], (string) $row['after_hash'])->value;
            } catch (InvalidPayload) {
                return; // a value not stored: the walk stops here
            }

            if (!is_string($before) || !is_string($after)) {
                return;
            }

            yield ['id' => $row['id'], 'before' => $before, 'after' => $after];
        }
    }

    /**
     * Blocks in editor order (blank text between blocks is skipped).
     *
     * @param array{segments: list<string>, owners: list<array{change: int, hunk: int|null}|null>} $result
     *
     * @return list<array{index: int, name: string,
     *               change: array{id: int, hunk: int|null, created_at: string|null, actor: array{id: int, name: string}|null}|null}>
     */
    private function describe(array $result): array
    {
        $ids = [];

        foreach ($result['owners'] as $owner) {
            if ($owner !== null) {
                $ids[$owner['change']] = $owner['change'];
            }
        }

        $changes = $this->journal->changesByIds(array_values($ids));
        $actors = [];
        $blocks = [];
        $index = 0;

        foreach ($result['segments'] as $i => $segment) {
            if (trim($segment) === '') {
                continue;
            }

            $owner = $result['owners'][$i];
            $row = $owner !== null ? ($changes[$owner['change']] ?? null) : null;
            $change = null;

            if ($row !== null) {
                $changeset = (int) $row['changeset_id'];
                $actors[$changeset] ??= $this->presenter->actor($this->journal->changesetById($changeset)['actor_user_id'] ?? null);
                $change = [
                    'id' => (int) $row['id'],
                    'hunk' => $owner['hunk'] ?? null,
                    'created_at' => Time::iso($row['created_at']),
                    'actor' => $actors[$changeset],
                ];
            }

            $blocks[] = [
                'index' => $index++,
                'name' => BlockTitles::nameOf($segment),
                'change' => $change,
            ];
        }

        return $blocks;
    }
}
