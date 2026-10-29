<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Support;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Infrastructure\WordPress\NavMenuAdapter;
use SelectiveUndo\Infrastructure\WordPress\SiteOptionsAdapter;

/**
 * Describes an object for the UI using its CURRENT state. Titles are returned as raw
 * text; the UI renders them as text nodes only.
 */
final class ObjectPresenter
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /**
     * @return array{type: string, id: int, subtype: string, subtype_label: string, title: string|null, edit_url: string|null, exists: bool, status: string|null}
     */
    public function describe(ObjectRef $object, int $viewerUserId): array
    {
        $key = $object->key();

        if (isset($this->cache[$key])) {
            /** @var array{type: string, id: int, subtype: string, subtype_label: string, title: string|null, edit_url: string|null, exists: bool, status: string|null} */
            return $this->cache[$key];
        }

        if ($object->type === 'site') {
            return $this->cache[$key] = [
                'type' => 'site',
                'id' => $object->id,
                'subtype' => SiteOptionsAdapter::SUBTYPE,
                'subtype_label' => __('Settings', 'selective-undo'),
                'title' => __('Site settings', 'selective-undo'),
                'edit_url' => user_can($viewerUserId, 'manage_options') ? admin_url('options-general.php') : null,
                'exists' => true,
                'status' => null,
            ];
        }

        if ($object->type === 'menu') {
            $menu = get_term($object->id, 'nav_menu');

            return $this->cache[$key] = [
                'type' => 'menu',
                'id' => $object->id,
                'subtype' => NavMenuAdapter::SUBTYPE,
                'subtype_label' => __('Menu', 'selective-undo'),
                'title' => $menu instanceof \WP_Term ? $menu->name : null,
                'edit_url' => $menu instanceof \WP_Term && user_can($viewerUserId, 'edit_theme_options') ? admin_url('nav-menus.php?action=edit&menu=' . $object->id) : null,
                'exists' => $menu instanceof \WP_Term,
                'status' => null,
            ];
        }

        $post = $object->type === 'post' ? get_post($object->id) : null;
        $subtype = $post instanceof \WP_Post ? $post->post_type : $object->subtype;
        $typeObject = get_post_type_object($subtype);

        return $this->cache[$key] = [
            'type' => $object->type,
            'id' => $object->id,
            'subtype' => $subtype,
            'subtype_label' => $typeObject !== null ? (string) $typeObject->labels->singular_name : $subtype,
            'title' => $post instanceof \WP_Post ? (string) $post->post_title : null,
            'edit_url' => $post instanceof \WP_Post && user_can($viewerUserId, 'edit_post', $post->ID)
                ? (string) get_edit_post_link($post->ID, 'raw')
                : null,
            'exists' => $post instanceof \WP_Post,
            'status' => $post instanceof \WP_Post ? $post->post_status : null,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    public function actor(mixed $userId): ?array
    {
        $id = (int) $userId;

        if ($id <= 0) {
            return null;
        }

        $user = get_userdata($id);

        return [
            'id' => $id,
            /* translators: %d: user ID of a deleted user. */
            'name' => $user instanceof \WP_User ? $user->display_name : sprintf(__('Deleted user #%d', 'selective-undo'), $id),
        ];
    }
}
