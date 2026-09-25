<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Unit;

use SelectiveUndo\Domain\Restore\ChainProblem;
use SelectiveUndo\Domain\Restore\ChainResolver;
use SelectiveUndo\Domain\Restore\ChangeLink;
use SelectiveUndo\Domain\Restore\Comparison;
use SelectiveUndo\Domain\Restore\ConflictDetector;
use SelectiveUndo\Domain\Restore\ItemFacts;
use SelectiveUndo\Domain\Restore\JobCounters;
use SelectiveUndo\Domain\Restore\JobOutcome;
use SelectiveUndo\Domain\Restore\JobStatus;
use SelectiveUndo\Domain\Restore\PlanItemEvaluator;
use SelectiveUndo\Domain\Restore\PlanItemStatus;
use SelectiveUndo\Domain\Restore\ResolvedChain;
use SelectiveUndo\Domain\Value\FieldValue;
use SelectiveUndo\Tests\TestCase;

final class RestoreRulesTest extends TestCase
{
    private function h(string $s): string
    {
        return FieldValue::of($s)->hash();
    }

    public function testThreeWayComparison(): void
    {
        $d = new ConflictDetector();
        [$b, $a, $x] = [$this->h('Контакты'), $this->h('Контакты OLD'), $this->h('Связаться')];
        $this->assertSame(Comparison::Ready, $d->compare($a, $a, $b));
        $this->assertSame(Comparison::AlreadyRestored, $d->compare($b, $a, $b));
        $this->assertSame(Comparison::Conflict, $d->compare($x, $a, $b));
        $this->assertSame(Comparison::AlreadyRestored, $d->compare($a, $a, $a), 'net zero chain');
    }

    public function testChainFolding(): void
    {
        $r = new ChainResolver();
        $c1 = new ChangeLink(10, $this->h('X'), $this->h('Y'), true);
        $c2 = new ChangeLink(20, $this->h('Y'), $this->h('Z'), true);
        $res = $r->resolve([$c2, $c1], [10, 20]);
        $this->assertInstanceOf(ResolvedChain::class, $res);
        $this->assertSame($this->h('X'), $res->targetHash);
        $this->assertSame($this->h('Z'), $res->expectedHash);
        $this->assertSame([10, 20], $res->changeIds);
        $this->assertSame(10, $res->targetChangeId);
        $this->assertSame(20, $res->expectedChangeId);
        $this->assertFalse($res->hasInterveningChanges);
    }

    public function testChainProblems(): void
    {
        $r = new ChainResolver();
        $c1 = new ChangeLink(10, $this->h('X'), $this->h('Y'), true);
        $c3 = new ChangeLink(30, $this->h('W'), $this->h('V'), true);
        $broken = $r->resolve([$c1, $c3], [10, 20, 30]);
        $this->assertInstanceOf(ChainProblem::class, $broken);
        $this->assertSame('chain_broken', $broken->reasonCode);
        $this->assertSame('duplicate_change', $r->resolve([$c1, $c1], [10])->reasonCode);
        $this->assertSame('payload_not_stored', $r->resolve([new ChangeLink(5, 'a', 'b', false, 'payload_not_stored')], [5])->reasonCode);
        $this->assertSame('empty_selection', $r->resolve([], [])->reasonCode);

        $withGap = $r->resolve([new ChangeLink(10, $this->h('X'), $this->h('Y'), true), new ChangeLink(20, $this->h('Y'), $this->h('W'), true)], [10, 15, 17, 20]);
        $this->assertInstanceOf(ResolvedChain::class, $withGap);
        $this->assertTrue($withGap->hasInterveningChanges);
    }

    public function testPlanItemEvaluationOrder(): void
    {
        $ev = new PlanItemEvaluator(new ConflictDetector());
        $chain = (new ChainResolver())->resolve([new ChangeLink(10, $this->h('X'), $this->h('Y'), true)], [10]);
        $facts = fn (array $o): ItemFacts => new ItemFacts(...array_merge([
            'supported' => true, 'objectExists' => true, 'authorized' => true, 'chain' => $chain,
            'targetPayloadProblem' => null, 'currentHash' => $this->h('Y'),
        ], $o));

        $this->assertSame(PlanItemStatus::Ready, $ev->evaluate($facts([]))->status);
        $this->assertSame(PlanItemStatus::AlreadyRestored, $ev->evaluate($facts(['currentHash' => $this->h('X')]))->status);
        $this->assertSame('current_value_changed', $ev->evaluate($facts(['currentHash' => $this->h('Q')]))->reasonCode);
        $this->assertSame(PlanItemStatus::Unsupported, $ev->evaluate($facts(['supported' => false, 'objectExists' => false]))->status);
        $this->assertSame(PlanItemStatus::MissingObject, $ev->evaluate($facts(['objectExists' => false, 'authorized' => false]))->status);
        $this->assertSame(PlanItemStatus::Forbidden, $ev->evaluate($facts(['authorized' => false]))->status);
        $blocked = $ev->evaluate($facts(['blockingReasons' => ['requires_unfiltered_html']]));
        $this->assertSame(PlanItemStatus::Blocked, $blocked->status);
        $this->assertSame('requires_unfiltered_html', $blocked->reasonCode);
        $this->assertSame(PlanItemStatus::AlreadyRestored, $ev->evaluate($facts(['currentHash' => $this->h('X'), 'blockingReasons' => ['object_trashed']]))->status);
        $this->assertSame('payload_hash_mismatch', $ev->evaluate($facts(['targetPayloadProblem' => 'payload_hash_mismatch']))->reasonCode);
    }

    public function testJobOutcome(): void
    {
        $o = new JobOutcome();
        $this->assertNull($o->resolve(new JobCounters(pending: 1, restored: 3), false));
        $this->assertSame(JobStatus::Completed, $o->resolve(new JobCounters(restored: 3), false));
        $this->assertSame(JobStatus::CompletedWithConflicts, $o->resolve(new JobCounters(restored: 3, conflict: 1), false));
        $this->assertSame(JobStatus::CompletedWithConflicts, $o->resolve(new JobCounters(skipped: 1), false));
        $this->assertSame(JobStatus::PartiallyFailed, $o->resolve(new JobCounters(restored: 3, failed: 1), false));
        $this->assertSame(JobStatus::Failed, $o->resolve(new JobCounters(failed: 2, conflict: 1), false));
        $this->assertSame(JobStatus::Cancelled, $o->resolve(new JobCounters(restored: 1, cancelled: 5), true));
        $this->assertSame(JobStatus::Completed, $o->resolve(new JobCounters(restored: 6), true));
        $this->assertTrue(JobStatus::Running->canTransitionTo(JobStatus::Completed));
        $this->assertFalse(JobStatus::Completed->canTransitionTo(JobStatus::Running));
        $this->assertSame(7, (new JobCounters(1, 1, 1, 1, 1, 1, 1))->total());
    }
}
