<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

use SelectiveUndo\Domain\Merge\MergeStrategy;

/**
 * Optional: an adapter whose fields can be undone part by part. Adapters without
 * it restore whole fields only.
 */
interface MergeableAdapter
{
    public function mergeStrategy(string $fieldKey): MergeStrategy;
}
