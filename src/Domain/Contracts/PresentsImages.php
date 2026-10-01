<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Value\FieldValue;

/**
 * An adapter whose field values can be media library images (a featured image):
 * comparisons show the images next to the text.
 *
 * @api
 */
interface PresentsImages
{
    /** URL of a small preview of the image the value refers to, or null. */
    public function imageUrl(ObjectRef $object, string $fieldKey, FieldValue $value): ?string;
}
