<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Change;

enum ChangesetKind: string
{
    case Edit = 'edit';
    case Autosave = 'autosave';
    case Operation = 'operation';
    case Restore = 'restore';
}
