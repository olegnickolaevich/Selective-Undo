<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

use SelectiveUndo\Domain\Value\FieldValue;

/**
 * Optional, for adapters whose values can be merged key by key: puts a merged value
 * into the form the adapter reads back after writing it (the order of keys, values
 * derived from others). Without it, a merged value written and read again would
 * have another hash and the history of the field would look broken.
 *
 * @api
 */
interface NormalizesValues
{
    public function normalize(string $fieldKey, FieldValue $value): FieldValue;
}
