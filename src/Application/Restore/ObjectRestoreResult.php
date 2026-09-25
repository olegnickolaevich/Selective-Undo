<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\Restore;

use SelectiveUndo\Domain\Change\ObjectRef;
use SelectiveUndo\Domain\Restore\JobItemStatus;

final readonly class ObjectRestoreResult
{
    /**
     * @param list<string>              $restoredFields
     * @param array<string, int>        $statusCounts
     */
    public function __construct(
        public ObjectRef $object,
        public array $restoredFields,
        public array $statusCounts,
    ) {
    }

    public static function nothingToDo(ObjectRef $object): self
    {
        return new self($object, [], []);
    }

    /**
     * @param list<ItemOutcome> $outcomes
     */
    public static function fromOutcomes(ObjectRef $object, array $outcomes): self
    {
        $restored = [];
        $counts = [];

        foreach ($outcomes as $outcome) {
            $counts[$outcome->status->value] = ($counts[$outcome->status->value] ?? 0) + 1;

            if ($outcome->status === JobItemStatus::Restored) {
                $restored[] = (string) $outcome->item['field_key'];
            }
        }

        return new self($object, $restored, $counts);
    }
}
