<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Contracts;

use SelectiveUndo\Domain\Change\CaptureQuality;
use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Value\FieldValue;

/**
 * Where adapters report the changes they observe. The core takes care of the
 * change set, order, stored values, continuity, gaps, imports and object versions.
 *
 * @api
 */
interface CaptureSink
{
    /** False while recording is paused, disabled, or a restore is writing. */
    public function isActive(): bool;

    /**
     * @param array<string, array{0: FieldValue, 1: FieldValue}> $changes field key => [before, after]
     */
    public function record(AdapterInterface $adapter, ObjectRef $object, array $changes, CaptureQuality $quality): void;

    /**
     * @param array<string, scalar> $details Codes and numbers only, never content.
     */
    public function recordGap(string $reason, ObjectRef $object, array $details = []): void;
}
