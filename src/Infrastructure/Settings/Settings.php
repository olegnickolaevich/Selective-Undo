<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\Settings;

use SelectiveUndo\Domain\DomainError;

/**
 * Plugin settings stored in a single autoloaded option.
 */
final class Settings
{
    public const OPTION = 'selective_undo_settings';

    /** Post types that are never tracked: attachments, internals, FSE entities, orders. */
    public const ALWAYS_EXCLUDED_POST_TYPES = [
        'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache',
        'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        'wp_font_family', 'wp_font_face', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_subscription',
        'shop_order_placehold',
    ];

    public const FREE_MAX_RETENTION_DAYS = 7;
    public const MIN_QUOTA_MB = 16;
    public const MAX_QUOTA_MB = 102400;

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'capture_enabled' => true,
            'tracked_post_types' => ['post', 'page'],
            'retention_days' => self::FREE_MAX_RETENTION_DAYS,
            'quota_mb' => 256,
            'evict_oldest_on_quota' => true,
            'max_value_kb' => 2048,
            'create_revision_after_restore' => true,
            'coalesce_autosaves' => true,
            'delete_data_on_uninstall' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return $this->cache = $this->sanitize(array_merge($this->defaults(), $stored), false);
    }

    /**
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     *
     * @throws DomainError
     */
    public function update(array $patch): array
    {
        $unknown = array_diff(array_keys($patch), array_keys($this->defaults()));

        if ($unknown !== []) {
            throw new DomainError('su_invalid_settings', 400, '', ['unknown' => array_values($unknown)]);
        }

        $next = $this->sanitize(array_merge($this->all(), $patch), true);
        update_option(self::OPTION, $next, true);
        $this->cache = $next;

        return $next;
    }

    public function captureEnabled(): bool
    {
        return (bool) $this->all()['capture_enabled'];
    }

    /**
     * @return list<string>
     */
    public function trackedPostTypes(): array
    {
        $types = (array) apply_filters('selective_undo/tracked_post_types', $this->all()['tracked_post_types']);
        $excluded = $this->excludedPostTypes();

        return array_values(array_filter(
            array_map('strval', $types),
            static fn (string $t): bool => $t !== '' && !in_array($t, $excluded, true)
        ));
    }

    /**
     * @return list<string>
     */
    public function excludedPostTypes(): array
    {
        return array_values(array_unique(array_map(
            'strval',
            (array) apply_filters('selective_undo/excluded_post_types', self::ALWAYS_EXCLUDED_POST_TYPES)
        )));
    }

    public function maxRetentionDays(): int
    {
        return max(1, (int) apply_filters('selective_undo/retention_max_days', self::FREE_MAX_RETENTION_DAYS));
    }

    public function retentionDays(): int
    {
        return min($this->maxRetentionDays(), max(1, (int) $this->all()['retention_days']));
    }

    public function quotaBytes(): int
    {
        return (int) $this->all()['quota_mb'] * 1024 * 1024;
    }

    public function evictOldest(): bool
    {
        return (bool) $this->all()['evict_oldest_on_quota'];
    }

    /**
     * Configured limit, but never more than 25% of max_allowed_packet.
     */
    public function maxValueBytes(int $maxAllowedPacket): int
    {
        $configured = (int) $this->all()['max_value_kb'] * 1024;
        $limit = $maxAllowedPacket > 0 ? min($configured, intdiv($maxAllowedPacket, 4)) : $configured;

        return (int) apply_filters('selective_undo/max_payload_bytes', max(1024, $limit));
    }

    public function createRevisionAfterRestore(): bool
    {
        return (bool) $this->all()['create_revision_after_restore'];
    }

    public function coalesceAutosaves(): bool
    {
        return (bool) $this->all()['coalesce_autosaves'];
    }

    public function deleteDataOnUninstall(): bool
    {
        return (bool) $this->all()['delete_data_on_uninstall'];
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function sanitize(array $s, bool $strict): array
    {
        $out = $this->defaults();

        foreach (['capture_enabled', 'evict_oldest_on_quota', 'create_revision_after_restore', 'coalesce_autosaves', 'delete_data_on_uninstall'] as $key) {
            $out[$key] = filter_var($s[$key] ?? $out[$key], FILTER_VALIDATE_BOOLEAN);
        }

        $retention = (int) ($s['retention_days'] ?? $out['retention_days']);

        if ($strict && ($retention < 1 || $retention > $this->maxRetentionDays())) {
            throw new DomainError('su_invalid_settings', 400, '', ['field' => 'retention_days', 'max' => $this->maxRetentionDays()]);
        }

        $out['retention_days'] = min($this->maxRetentionDays(), max(1, $retention));

        $quota = (int) ($s['quota_mb'] ?? $out['quota_mb']);

        if ($strict && ($quota < self::MIN_QUOTA_MB || $quota > self::MAX_QUOTA_MB)) {
            throw new DomainError('su_invalid_settings', 400, '', ['field' => 'quota_mb', 'min' => self::MIN_QUOTA_MB, 'max' => self::MAX_QUOTA_MB]);
        }

        $out['quota_mb'] = min(self::MAX_QUOTA_MB, max(self::MIN_QUOTA_MB, $quota));

        $maxValue = (int) ($s['max_value_kb'] ?? $out['max_value_kb']);

        if ($strict && ($maxValue < 16 || $maxValue > 65536)) {
            throw new DomainError('su_invalid_settings', 400, '', ['field' => 'max_value_kb', 'min' => 16, 'max' => 65536]);
        }

        $out['max_value_kb'] = min(65536, max(16, $maxValue));

        $types = $s['tracked_post_types'] ?? $out['tracked_post_types'];
        $types = is_array($types) ? array_values(array_unique(array_map('sanitize_key', array_map('strval', $types)))) : [];
        $excluded = $this->excludedPostTypes();

        if ($strict) {
            foreach ($types as $type) {
                $object = get_post_type_object($type);

                if ($object === null || !$object->show_ui || in_array($type, $excluded, true)) {
                    throw new DomainError('su_invalid_settings', 400, '', ['field' => 'tracked_post_types', 'value' => $type]);
                }
            }
        }

        $out['tracked_post_types'] = array_values(array_diff($types, $excluded));

        return $out;
    }
}
