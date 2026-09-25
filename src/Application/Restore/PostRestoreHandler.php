<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Infrastructure\Settings\Settings;

/**
 * Outbox handler for "object_restored". Every step is idempotent: cache purges can
 * repeat, and WordPress does not create a revision when nothing changed.
 */
final class PostRestoreHandler
{
    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function __invoke(array $payload): void
    {
        $object = new ObjectRef((string) ($payload['object_type'] ?? ''), (int) ($payload['object_id'] ?? 0));
        $fields = array_map('strval', (array) ($payload['fields'] ?? []));
        $adapter = $this->adapters->find((string) ($payload['adapter_key'] ?? ''));

        if ($adapter === null) {
            throw new \RuntimeException('adapter_unavailable');
        }

        $adapter->afterCommit($object, $fields);

        if ($object->type === 'post' && $this->settings->createRevisionAfterRestore()) {
            $post = get_post($object->id);

            if ($post instanceof \WP_Post && wp_revisions_enabled($post)) {
                wp_save_post_revision($object->id);
            }
        }

        /**
         * Fires after an object was restored and caches were invalidated.
         * May fire more than once for the same event: deduplicate by event_id.
         *
         * @param array<string, mixed> $payload event_id, job_id, object_type, object_id, adapter_key, fields, actor_user_id.
         */
        do_action('selective_undo/object_restored', $payload);
    }
}
