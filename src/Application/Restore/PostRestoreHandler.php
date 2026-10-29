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
        $object = new ObjectRef((string) ($payload['object_type'] ?? ''), (int) ($payload['object_id'] ?? 0), (string) ($payload['object_subtype'] ?? ''));
        // Events written before 2.1 name one adapter.
        $byAdapter = isset($payload['adapters']) && is_array($payload['adapters'])
            ? $payload['adapters']
            : [(string) ($payload['adapter_key'] ?? '') => $payload['fields'] ?? []];

        // Run as the user who restored (in the background there is none): other plugins
        // that react to the restored values (Elementor syncing the site title into its kit)
        // check the permissions of the current user.
        $actor = (int) ($payload['actor_user_id'] ?? 0);
        $previous = get_current_user_id();

        if ($actor > 0 && $actor !== $previous) {
            wp_set_current_user($actor);
        }

        try {
            foreach ($byAdapter as $key => $fields) {
                $adapter = $this->adapters->find((string) $key);

                if ($adapter === null) {
                    throw new \RuntimeException('adapter_unavailable');
                }

                $adapter->afterCommit($object, array_values(array_map('strval', (array) $fields)));
            }

            if ($object->type === 'post' && $this->settings->createRevisionAfterRestore()) {
                $post = get_post($object->id);

                if ($post instanceof \WP_Post && wp_revisions_enabled($post)) {
                    wp_save_post_revision($object->id);
                }
            }
        } finally {
            if (get_current_user_id() !== $previous) {
                wp_set_current_user($previous);
            }
        }

        /**
         * Fires after an object was restored and caches were invalidated.
         * May fire more than once for the same event: deduplicate by event_id.
         *
         * @param array<string, mixed> $payload event_id, job_id, object_type, object_id, object_subtype,
         *                                    adapters (adapter key => fields), fields, actor_user_id.
         */
        do_action('selective_undo/object_restored', $payload);
    }
}
