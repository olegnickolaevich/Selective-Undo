<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

use SelectiveUndo\Domain\Change\ObjectRef;

defined('ABSPATH') || exit;

/**
 * An adapter that lists its fields for an object (labels, types, merge strategy).
 *
 * @api
 */
interface DescribesFields
{
    /** @return list<FieldDefinition> */
    public function fields(ObjectRef $object): array;
}
