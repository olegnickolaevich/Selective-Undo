<?php

declare(strict_types=1);

namespace SelectiveUndo\Presentation\Rest;

use SelectiveUndo\Application\Capture\RequestContext;
use SelectiveUndo\Application\Capture\Source;
use SelectiveUndo\Infrastructure\WordPress\TrackingPolicy;

/**
 * "selective_undo_intent" in a post save request marks the saved values as the undo
 * of earlier changes (the editor's "Undo in the editor"). It is read in
 * rest_pre_insert_{type}, before wp_update_post() records the change.
 */
final class RevertIntentField
{
    public const FIELD = 'selective_undo_intent';

    public function __construct(
        private readonly TrackingPolicy $policy,
        private readonly RequestContext $request,
    ) {
    }

    public function register(): void
    {
        foreach (get_post_types(['show_in_rest' => true]) as $type) {
            if (!$this->policy->isTrackedPostType($type)) {
                continue;
            }

            register_rest_field($type, self::FIELD, [
                'schema' => [
                    'type' => 'object',
                    'context' => ['edit'],
                    'properties' => [
                        'reverts' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'integer', 'minimum' => 1]],
                    ],
                    'additionalProperties' => false,
                ],
                'get_callback' => static fn (): array => ['reverts' => []],
                // Already read in rest_pre_insert, before the save.
                'update_callback' => static fn (): bool => true,
            ]);

            add_filter("rest_pre_insert_{$type}", [$this, 'capture'], 10, 2);
        }
    }

    public function capture(mixed $prepared, \WP_REST_Request $request): mixed
    {
        $intent = $request->get_param(self::FIELD);

        if ($prepared instanceof \stdClass && isset($prepared->ID) && is_array($intent) && !empty($intent['reverts'])) {
            $this->request->setRevertIntent((int) $prepared->ID, array_values(array_map('intval', (array) $intent['reverts'])));
            $this->request->setSource(Source::EditorUndo);
        }

        return $prepared;
    }
}
