<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Merge;

/**
 * How a field can be undone when it changed again after the change being undone.
 */
enum MergeStrategy: string
{
    /** The whole field: a later change is a conflict (as in 1.0). */
    case Replace = 'replace';
    /** Blocks, or paragraphs of classic text: later edits of other parts are kept. */
    case Segments = 'segments';
    /** Members of a set (terms): later edits of other members are kept. */
    case Set = 'set';
    /** Keys of a JSON object: later edits of other keys are kept. */
    case Keys = 'keys';
}
