<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are returned as REST API JSON errors (ErrorMapper) or logged, never printed as HTML.

use SelectiveUndo\Application\Capture\RequestContext;
use SelectiveUndo\Domain\Change\CaptureQuality;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\AdapterCapabilities;
use SelectiveUndo\Domain\Contracts\AdapterInterface;
use SelectiveUndo\Domain\Contracts\CaptureSink;
use SelectiveUndo\Domain\Contracts\CapturingAdapter;
use SelectiveUndo\Domain\Contracts\DescribesFields;
use SelectiveUndo\Domain\Contracts\FieldDefinition;
use SelectiveUndo\Domain\Contracts\ObjectSnapshot;
use SelectiveUndo\Domain\Contracts\PresentsValues;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Infrastructure\Database\StorageError;

/**
 * Site settings ("option:<name>") of the object site:<blog ID>: the options of
 * Settings → General and Reading that change how the whole site looks, and those
 * added with the selective_undo/tracked_options filter. Values are the raw
 * option_value strings, missing when the row does not exist.
 *
 * Addresses, permalinks, registration, roles, plugins and anything that looks like a
 * key or a secret are never recorded: undoing them can lock the administrator out.
 */
final class SiteOptionsAdapter implements AdapterInterface, CapturingAdapter, DescribesFields, PresentsValues
{
    public const KEY = 'core/site-options';
    public const VERSION = 1;
    /** Object subtype of site settings; a colon is never part of a post type name. */
    public const SUBTYPE = 'site:options';

    private const NEVER = ['siteurl', 'home', 'admin_email', 'new_admin_email', 'users_can_register', 'default_role', 'permalink_structure', 'active_plugins', 'template', 'stylesheet', 'db_version', 'cron', 'rewrite_rules'];

    /** @var array<string, FieldValue> option => value read right before a write */
    private array $pending = [];
    /** @var array<string, string|null> option => raw value before a restore (for the update_option_* actions) */
    private array $replaced = [];

    public function __construct(
        private readonly \wpdb $db,
        private readonly RequestContext $request,
    ) {
    }

    public static function site(): ObjectRef
    {
        return new ObjectRef('site', get_current_blog_id(), self::SUBTYPE);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function acceptsVersion(int $version): bool
    {
        return $version === self::VERSION;
    }

    public function capabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            fields: array_map(static fn (string $o): string => 'option:' . $o, $this->tracked()),
            requiresTransactions: true,
            supportsBackground: true,
            hasLinkedData: false,
            knownSideEffects: ['object_cache', 'update_option_actions'],
            idempotent: true,
            maxValueBytes: null,
        );
    }

    public function supports(ObjectRef $object, string $fieldKey): bool
    {
        return $object->type === 'site' && str_starts_with($fieldKey, 'option:') && $this->isTracked(substr($fieldKey, 7));
    }

    public function fieldLabel(string $fieldKey): string
    {
        if (!str_starts_with($fieldKey, 'option:')) {
            return $fieldKey; // a field of another adapter
        }

        $name = substr($fieldKey, 7);

        return self::labels()[$name] ?? $name;
    }

    public function fields(ObjectRef $object): array
    {
        $labels = self::labels();

        return array_map(
            static fn (string $o): FieldDefinition => new FieldDefinition('option:' . $o, $labels[$o] ?? $o, 'text'),
            $this->tracked()
        );
    }

    public function registerCapture(CaptureSink $sink): void
    {
        $before = function (string $option) use ($sink): void {
            if ($sink->isActive() && !$this->request->isRestoring() && $this->isTracked($option)) {
                $this->pending[$option] ??= $this->readValue($option);
            }
        };
        $after = function (string $option) use ($sink): void {
            if (!isset($this->pending[$option])) {
                return;
            }

            $before = $this->pending[$option];
            unset($this->pending[$option]);
            $now = $this->readValue($option);

            if (!$before->equals($now)) {
                $sink->record($this, self::site(), ['option:' . $option => [$before, $now]], CaptureQuality::Verified);
            }
        };

        // Each pair runs right before and right after the database write.
        add_action('update_option', $before, PHP_INT_MAX, 1);
        add_action('updated_option', $after, PHP_INT_MAX, 1);
        add_action('add_option', $before, PHP_INT_MAX, 1);
        add_action('added_option', $after, PHP_INT_MAX, 1);
        add_action('delete_option', $before, PHP_INT_MAX, 1);
        add_action('deleted_option', $after, PHP_INT_MAX, 1);
    }

    public function read(ObjectRef $object): ObjectSnapshot
    {
        return new FieldsSnapshot($object->id, self::SUBTYPE, fn (string $field): FieldValue => $this->readValue(substr($field, 7)), 'exists');
    }

    public function lockAndRead(RestoreTransaction $tx, ObjectRef $object): ObjectSnapshot
    {
        $names = $this->tracked();
        $rows = $tx->select(
            "SELECT option_name, option_value FROM `{$this->db->options}` WHERE option_name IN (" . implode(', ', array_fill(0, count($names), '?')) . ') FOR UPDATE',
            str_repeat('s', count($names)),
            $names
        );
        $values = [];

        foreach ($rows as $row) {
            $values[(string) $row['option_name']] = FieldValue::of((string) $row['option_value']);
        }

        return new FieldsSnapshot($object->id, self::SUBTYPE, static fn (string $field): FieldValue => $values[substr($field, 7)] ?? FieldValue::missing(), 'exists');
    }

    public function authorize(int $userId, ObjectRef $object, string $action): bool
    {
        return user_can($userId, $action === 'view' ? Capabilities::VIEW_OBJECT : Capabilities::RESTORE_OBJECT, $object->id, self::SUBTYPE);
    }

    public function validateState(ObjectSnapshot $current, string $fieldKey): ?string
    {
        return $this->isTracked(substr($fieldKey, 7)) ? null : 'field_not_supported';
    }

    public function validateTarget(int $actorUserId, ObjectRef $object, string $fieldKey, FieldValue $target): ?string
    {
        return !$target->exists || is_string($target->value) ? null : 'target_type_mismatch';
    }

    public function warnings(ObjectSnapshot $current, array $fieldKeys, int $viewerUserId): array
    {
        return [];
    }

    public function write(RestoreTransaction $tx, ObjectSnapshot $current, array $values): void
    {
        foreach ($values as $field => $value) {
            $name = substr($field, 7);

            if (!$this->isTracked($name) || ($value->exists && !is_string($value->value))) {
                throw new \LogicException('Option is not restorable.');
            }

            $old = $current->field($field);
            $this->replaced[$name] = $old->exists && is_string($old->value) ? $old->value : null;

            if (!$value->exists) {
                $tx->write("DELETE FROM `{$this->db->options}` WHERE option_name = ?", 's', [$name]);
            } elseif ($old->exists) {
                $tx->write("UPDATE `{$this->db->options}` SET option_value = ? WHERE option_name = ?", 'ss', [(string) $value->value, $name]);
            } elseif ($tx->write("INSERT INTO `{$this->db->options}` (option_name, option_value, autoload) VALUES (?, ?, 'on')", 'ss', [$name, (string) $value->value]) !== 1) {
                throw new StorageError('option_insert_failed');
            }
        }
    }

    public function afterCommit(ObjectRef $object, array $fieldKeys): void
    {
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');

        foreach ($fieldKeys as $field) {
            $name = substr($field, 7);
            wp_cache_delete($name, 'options');

            if (!array_key_exists($name, $this->replaced)) {
                continue; // run again later: the value it replaced is no longer known
            }

            $old = $this->replaced[$name];
            unset($this->replaced[$name]);
            $this->request->whileRestoring(static function () use ($name, $old): void {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Core action, fired as update_option() would.
                do_action("update_option_{$name}", maybe_unserialize((string) $old), get_option($name), $name);
            });
        }
    }

    public function present(ObjectRef $object, string $fieldKey, FieldValue $value): string
    {
        if (!$value->exists || !is_string($value->value)) {
            return '';
        }

        $name = substr($fieldKey, 7);

        if (in_array($name, ['page_on_front', 'page_for_posts', 'site_icon'], true) && ctype_digit($value->value) && (int) $value->value > 0) {
            $title = get_the_title((int) $value->value);

            return ($title !== '' ? $title : __('(no title)', 'selective-undo')) . ' (#' . $value->value . ')';
        }

        if ($name === 'default_category' && ctype_digit($value->value)) {
            $term = get_term((int) $value->value, 'category');

            return ($term instanceof \WP_Term ? $term->name : '') . ' (#' . $value->value . ')';
        }

        if (!is_serialized($value->value)) {
            return $value->value;
        }

        // Shown only: never instantiate objects from stored data.
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- allowed_classes=false.
        $data = @unserialize($value->value, ['allowed_classes' => false]);

        return (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function readValue(string $name): FieldValue
    {
        $raw = $this->db->get_var($this->db->prepare("SELECT option_value FROM {$this->db->options} WHERE option_name = %s", $name));

        return $raw === null ? FieldValue::missing() : FieldValue::of((string) $raw);
    }

    private function isTracked(string $name): bool
    {
        return in_array($name, $this->tracked(), true);
    }

    /**
     * @return list<string>
     */
    private function tracked(): array
    {
        /**
         * Options recorded as site settings. Addresses, permalinks, registration, roles,
         * active plugins and names that look like keys or secrets are always left out.
         *
         * @param list<string> $options
         */
        $options = (array) apply_filters('selective_undo/tracked_options', array_keys(self::labels()));

        return array_values(array_filter(array_unique(array_map('strval', $options)), static fn (string $o): bool => $o !== ''
            && !in_array($o, self::NEVER, true)
            && !preg_match('/(_key|_salt|_token|_secret|password)$/i', $o)
            && !str_starts_with($o, '_transient') && !str_starts_with($o, '_site_transient')));
    }

    /**
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            'blogname' => __('Site title', 'selective-undo'),
            'blogdescription' => __('Tagline', 'selective-undo'),
            'show_on_front' => __('Your homepage displays', 'selective-undo'),
            'page_on_front' => __('Homepage', 'selective-undo'),
            'page_for_posts' => __('Posts page', 'selective-undo'),
            'posts_per_page' => __('Blog pages show at most', 'selective-undo'),
            'default_category' => __('Default post category', 'selective-undo'),
            'date_format' => __('Date format', 'selective-undo'),
            'time_format' => __('Time format', 'selective-undo'),
            'site_icon' => __('Site icon', 'selective-undo'),
            'WPLANG' => __('Site language', 'selective-undo'),
        ];
    }
}
