<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

use SelectiveUndo\Domain\DomainError;

/**
 * Primitive capabilities live in roles (compatible with role editors).
 * Object-level meta capabilities are expanded through map_meta_cap.
 */
final class CapabilityManager
{
    public function register(): void
    {
        add_filter('map_meta_cap', [$this, 'mapMetaCap'], 10, 4);
    }

    /** Activation: capabilities are granted to administrators only. */
    public function install(): void
    {
        $role = get_role('administrator');

        if ($role === null) {
            return;
        }

        foreach (Capabilities::PRIMITIVE as $cap) {
            $role->add_cap($cap);
        }
    }

    public function uninstall(): void
    {
        foreach (wp_roles()->role_objects as $role) {
            foreach (Capabilities::PRIMITIVE as $cap) {
                $role->remove_cap($cap);
            }
        }
    }

    /**
     * @return array<string, array{name: string, caps: array<string, bool>}>
     */
    public function roleMatrix(): array
    {
        $matrix = [];

        foreach (wp_roles()->role_objects as $slug => $role) {
            $caps = [];

            foreach (Capabilities::PRIMITIVE as $cap) {
                $caps[$cap] = $role->has_cap($cap);
            }

            $matrix[(string) $slug] = [
                'name' => translate_user_role((string) (wp_roles()->role_names[$slug] ?? $slug)),
                'caps' => $caps,
            ];
        }

        return $matrix;
    }

    /**
     * Grants or revokes primitive capabilities. Administrators always keep
     * sundo_manage_settings so the plugin cannot be locked out.
     *
     * @param array<string, array<string, bool>> $matrix role => cap => granted
     */
    public function updateRoles(array $matrix): void
    {
        foreach ($matrix as $slug => $caps) {
            $role = get_role((string) $slug);

            if ($role === null) {
                throw new DomainError('su_invalid_settings', 400, '', ['field' => 'roles', 'value' => (string) $slug]);
            }

            foreach ($caps as $cap => $granted) {
                if (!in_array($cap, Capabilities::PRIMITIVE, true)) {
                    throw new DomainError('su_invalid_settings', 400, '', ['field' => 'roles', 'value' => (string) $cap]);
                }

                if ($slug === 'administrator' && $cap === Capabilities::MANAGE_SETTINGS && !$granted) {
                    continue;
                }

                $granted ? $role->add_cap($cap) : $role->remove_cap($cap);
            }
        }
    }

    /**
     * @param list<string>      $caps
     * @param array<int, mixed> $args
     *
     * @return list<string>
     */
    public function mapMetaCap(array $caps, string $cap, int $userId, array $args): array
    {
        if ($cap !== Capabilities::VIEW_OBJECT && $cap !== Capabilities::RESTORE_OBJECT) {
            return $caps;
        }

        $base = $cap === Capabilities::VIEW_OBJECT ? Capabilities::VIEW_HISTORY : Capabilities::RESTORE_CHANGES;
        $postId = (int) ($args[0] ?? 0);
        $post = $postId > 0 ? get_post($postId) : null;

        if ($post instanceof \WP_Post) {
            // edit_post accounts for authorship, status, private/published and site filters.
            return array_values(array_unique(array_merge([$base], map_meta_cap('edit_post', $userId, $postId))));
        }

        // Deleted object: cannot be restored; history visible by the recorded post type.
        if ($cap === Capabilities::RESTORE_OBJECT) {
            return ['do_not_allow'];
        }

        $type = get_post_type_object((string) ($args[1] ?? ''));

        return $type === null
            ? [$base, 'manage_options']
            : [$base, (string) $type->cap->edit_others_posts];
    }
}
