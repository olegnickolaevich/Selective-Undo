<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Change;

enum ChangeEvent: string
{
    case Update = 'update';
    case Restore = 'restore';
    case Created = 'created';
    case Trashed = 'trashed';
    case Untrashed = 'untrashed';
    case Deleted = 'deleted';

    public function isFieldChange(): bool
    {
        return $this === self::Update || $this === self::Restore;
    }

    /** A change that can be undone: a field change, or a permanent deletion recorded with its snapshot. */
    public function isRestorable(string $fieldKey): bool
    {
        return $this->isFieldChange() || ($this === self::Deleted && $fieldKey !== '');
    }
}
