<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Value\FieldValue;

/**
 * An adapter knows how to read, validate, write and post-process one kind of data.
 * Whether an item may be restored is decided by the application layer
 * (PlanItemEvaluator), not by the adapter.
 */
interface AdapterInterface
{
    public function key(): string;

    /** Version of the value format. Any normalisation change requires a new version. */
    public function version(): int;

    public function capabilities(): AdapterCapabilities;

    public function supports(ObjectRef $object, string $fieldKey): bool;

    /** Human readable, translated field label. */
    public function fieldLabel(string $fieldKey): string;

    /**
     * Non-locking read for previews, directly from the database (no object cache).
     */
    public function read(ObjectRef $object): ?ObjectSnapshot;

    /**
     * Locking read (SELECT ... FOR UPDATE) inside a restore transaction.
     */
    public function lockAndRead(RestoreTransaction $tx, ObjectRef $object): ?ObjectSnapshot;

    /** @param 'view'|'restore' $action */
    public function authorize(int $userId, ObjectRef $object, string $action): bool;

    /**
     * Restrictions depending on the target value and permissions (kses and similar).
     * May call WordPress APIs and filters; NEVER called inside a transaction.
     */
    public function validateTarget(int $actorUserId, ObjectRef $object, string $fieldKey, FieldValue $target): ?string;

    /**
     * Restrictions depending only on the current state (trash, post type).
     * Pure; called while building a plan and inside the restore transaction.
     */
    public function validateState(ObjectSnapshot $current, string $fieldKey): ?string;

    /**
     * Non-fatal preview warnings (page_builder_detected, post_locked, ...).
     *
     * @param list<string> $fieldKeys
     *
     * @return list<string>
     */
    public function warnings(ObjectSnapshot $current, array $fieldKeys, int $viewerUserId): array;

    /**
     * Writes ONLY the given fields.
     *
     * @param array<string, FieldValue> $values
     */
    public function write(RestoreTransaction $tx, ObjectSnapshot $current, array $values): void;

    /**
     * Idempotent post-commit processing (cache invalidation). May run more than once.
     *
     * @param list<string> $fieldKeys
     */
    public function afterCommit(ObjectRef $object, array $fieldKeys): void;
}
