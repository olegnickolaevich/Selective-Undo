<?php

declare(strict_types=1);

namespace SelectiveUndo\Infrastructure\WordPress;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Contracts\RestoreTransaction;

/**
 * The first lock of every restore transaction: the object's own row. Adapters lock
 * their rows after it, so two restores of one object never wait on each other in
 * opposite orders.
 */
final class ObjectLock
{
    public function __construct(private readonly string $postsTable)
    {
    }

    public function lock(RestoreTransaction $tx, ObjectRef $object): void
    {
        if ($object->type === 'post') {
            $tx->select(sprintf('SELECT ID FROM `%s` WHERE ID = ? FOR UPDATE', $this->postsTable), 'i', [$object->id]);
        }
    }
}
