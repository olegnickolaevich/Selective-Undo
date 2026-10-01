<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Value\FieldValue;

/**
 * An adapter whose stored values are not readable as they are (term IDs, attachment
 * IDs, serialized meta): comparisons show this text instead. Never used to compare.
 *
 * @api
 */
interface PresentsValues
{
    public function present(ObjectRef $object, string $fieldKey, FieldValue $value): string;
}
