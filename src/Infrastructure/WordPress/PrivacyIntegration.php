<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

use SelectiveUndo\Infrastructure\Database\Tables;

/**
 * Integration with the WordPress personal data tools. The history records who
 * changed what (activity data); content itself cannot be matched to an email
 * address and is removed with the history purge tools instead.
 */
final class PrivacyIntegration
{
    private const PAGE = 200;

    public function __construct(
        private readonly \wpdb $db,
        private readonly Tables $t,
    ) {
    }

    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
        add_action('admin_init', [$this, 'addPolicyText']);
    }

    /**
     * @param array<string, array<string, mixed>> $exporters
     *
     * @return array<string, array<string, mixed>>
     */
    public function registerExporter(array $exporters): array
    {
        $exporters['selective-undo'] = [
            'exporter_friendly_name' => __('Selective Undo change history', 'selective-undo'),
            'callback' => [$this, 'export'],
        ];

        return $exporters;
    }

    /**
     * @param array<string, array<string, mixed>> $erasers
     *
     * @return array<string, array<string, mixed>>
     */
    public function registerEraser(array $erasers): array
    {
        $erasers['selective-undo'] = [
            'eraser_friendly_name' => __('Selective Undo change history', 'selective-undo'),
            'callback' => [$this, 'erase'],
        ];

        return $erasers;
    }

    /**
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);

        if (!$user instanceof \WP_User) {
            return ['data' => [], 'done' => true];
        }

        $rows = (array) $this->db->get_results($this->db->prepare(
            "SELECT cs.uuid, cs.created_at, cs.kind, cs.source,
                    GROUP_CONCAT(DISTINCT CONCAT(c.object_type, ':', c.object_id)) AS objects,
                    GROUP_CONCAT(DISTINCT NULLIF(c.field_key, '')) AS fields
             FROM {$this->t->changesets} cs JOIN {$this->t->changes} c ON c.changeset_id = cs.id
             WHERE cs.actor_user_id = %d GROUP BY cs.id ORDER BY cs.id LIMIT %d OFFSET %d",
            $user->ID,
            self::PAGE,
            ($page - 1) * self::PAGE
        ), ARRAY_A);
        $data = [];

        foreach ($rows as $row) {
            $data[] = [
                'group_id' => 'selective-undo',
                'group_label' => __('Content change history', 'selective-undo'),
                'item_id' => 'sundo-' . $row['uuid'],
                'data' => [
                    ['name' => __('Date (UTC)', 'selective-undo'), 'value' => (string) $row['created_at']],
                    ['name' => __('Type', 'selective-undo'), 'value' => (string) $row['kind']],
                    ['name' => __('Source', 'selective-undo'), 'value' => (string) $row['source']],
                    ['name' => __('Items', 'selective-undo'), 'value' => (string) $row['objects']],
                    ['name' => __('Fields', 'selective-undo'), 'value' => (string) $row['fields']],
                ],
            ];
        }

        return ['data' => $data, 'done' => count($rows) < self::PAGE];
    }

    /**
     * Anonymises the author of recorded changes. Restore records keep a neutral owner.
     *
     * @return array{items_removed: int, items_retained: int, messages: list<string>, done: bool}
     */
    public function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);

        if (!$user instanceof \WP_User) {
            return ['items_removed' => 0, 'items_retained' => 0, 'messages' => [], 'done' => true];
        }

        $changed = (int) $this->db->query($this->db->prepare(
            "UPDATE {$this->t->changesets} SET actor_user_id = NULL WHERE actor_user_id = %d",
            $user->ID
        ));
        $changed += (int) $this->db->query($this->db->prepare(
            "UPDATE {$this->t->operations} SET actor_user_id = NULL WHERE actor_user_id = %d",
            $user->ID
        ));

        return [
            'items_removed' => $changed,
            'items_retained' => 0,
            'messages' => [__('Recorded content changes remain until they expire; delete them in Selective Undo → Settings if needed.', 'selective-undo')],
            'done' => true,
        ];
    }

    public function addPolicyText(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        wp_add_privacy_policy_content(
            'Selective Undo',
            wp_kses_post(wpautop(__(
                'This site records changes to selected content (titles, text, excerpts and order of posts and pages) so that administrators can undo specific changes. The history stores who made a change, when, and the previous and new values. Removed text can remain in the history until it expires (up to the retention period set by the administrator). The history is stored in this site\'s database and is not sent to third parties.',
                'selective-undo'
            )))
        );
    }
}
