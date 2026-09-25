<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

use SelectiveUndo\Infrastructure\Settings\Settings;

/**
 * Decides which objects are captured and restorable.
 */
final class TrackingPolicy
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function isTrackedPostType(string $postType): bool
    {
        return $postType !== '' && in_array($postType, $this->settings->trackedPostTypes(), true);
    }

    public function shouldCapture(int $postId, string $postType): bool
    {
        if (!$this->settings->captureEnabled() || !$this->isTrackedPostType($postType)) {
            return false;
        }

        $excludedIds = array_map('intval', (array) apply_filters('selective_undo/excluded_object_ids', [], $postType));

        return !in_array($postId, $excludedIds, true);
    }
}
