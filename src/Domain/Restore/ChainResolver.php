<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

/**
 * Folds the selected changes of one field of one object into a single
 * (target, expected) pair.
 *
 * X -> Y and Y -> Z selected together give target X and expected Z.
 * Folding is allowed only when the chain is continuous by value:
 * after of every change equals before of the next selected change.
 */
final class ChainResolver
{
    /**
     * @param list<ChangeLink> $selected          Selected changes of the field, any order.
     * @param list<int>        $journalIdsInRange IDs of all journal changes of this field
     *                                            within [min(selected), max(selected)].
     */
    public function resolve(array $selected, array $journalIdsInRange): ResolvedChain|ChainProblem
    {
        if ($selected === []) {
            return new ChainProblem('empty_selection', []);
        }

        usort($selected, static fn (ChangeLink $a, ChangeLink $b): int => $a->changeId <=> $b->changeId);
        $ids = array_map(static fn (ChangeLink $c): int => $c->changeId, $selected);

        if (count(array_unique($ids)) !== count($ids)) {
            return new ChainProblem('duplicate_change', $ids);
        }

        foreach ($selected as $change) {
            if (!$change->restorable) {
                return new ChainProblem($change->reasonCode ?? 'change_not_restorable', [$change->changeId]);
            }
        }

        for ($i = 1, $n = count($selected); $i < $n; $i++) {
            if ($selected[$i - 1]->afterHash !== $selected[$i]->beforeHash) {
                return new ChainProblem('chain_broken', [$selected[$i - 1]->changeId, $selected[$i]->changeId]);
            }
        }

        $first = $selected[0];
        $last = $selected[count($selected) - 1];

        return new ResolvedChain(
            changeIds: $ids,
            targetChangeId: $first->changeId,
            expectedChangeId: $last->changeId,
            targetHash: $first->beforeHash,
            expectedHash: $last->afterHash,
            hasInterveningChanges: count(array_unique($journalIdsInRange)) > count($ids),
        );
    }
}
