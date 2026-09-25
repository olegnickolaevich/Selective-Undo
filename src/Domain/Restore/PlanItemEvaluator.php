<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Restore;

/**
 * Decides the status of a plan item. First matching rule wins.
 */
final class PlanItemEvaluator
{
    public function __construct(private readonly ConflictDetector $detector)
    {
    }

    public function evaluate(ItemFacts $facts): PlanItemDecision
    {
        $warnings = $facts->warnings;

        if (!$facts->supported) {
            return new PlanItemDecision(PlanItemStatus::Unsupported, 'field_not_supported', $warnings);
        }

        if (!$facts->objectExists) {
            return new PlanItemDecision(PlanItemStatus::MissingObject, 'object_missing', $warnings);
        }

        if (!$facts->authorized) {
            return new PlanItemDecision(PlanItemStatus::Forbidden, 'not_allowed', $warnings);
        }

        $chain = $facts->chain;

        if ($chain instanceof ChainProblem) {
            return new PlanItemDecision(PlanItemStatus::Blocked, $chain->reasonCode, $warnings);
        }

        if ($chain->hasInterveningChanges) {
            $warnings[] = 'intervening_changes';
        }

        if ($facts->targetPayloadProblem !== null) {
            return new PlanItemDecision(PlanItemStatus::Blocked, $facts->targetPayloadProblem, $warnings);
        }

        if ($facts->currentHash === null) {
            return new PlanItemDecision(PlanItemStatus::Blocked, 'current_value_unreadable', $warnings);
        }

        $comparison = $this->detector->compare($facts->currentHash, $chain->expectedHash, $chain->targetHash);

        return match ($comparison) {
            Comparison::AlreadyRestored => new PlanItemDecision(PlanItemStatus::AlreadyRestored, null, $warnings),
            Comparison::Conflict => new PlanItemDecision(PlanItemStatus::Conflict, 'current_value_changed', $warnings),
            // Write restrictions only matter when a write is actually needed.
            Comparison::Ready => $facts->blockingReasons === []
                ? new PlanItemDecision(PlanItemStatus::Ready, null, $warnings)
                : new PlanItemDecision(PlanItemStatus::Blocked, $facts->blockingReasons[0], $warnings),
        };
    }
}
