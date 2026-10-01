<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Unit;

use SelectiveUndo\Domain\Merge\Blame;
use SelectiveUndo\Domain\Merge\BlockSegmenter;
use SelectiveUndo\Domain\Merge\ContentMerger;
use SelectiveUndo\Domain\Merge\KeyedMerger;
use SelectiveUndo\Domain\Merge\SetMerger;
use SelectiveUndo\Domain\Merge\TwoWayDiff;
use SelectiveUndo\Tests\TestCase;

final class MergeEngineTest extends TestCase
{
    private static function p(string $text): string
    {
        return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
    }

    /**
     * @param list<string> $blocks
     */
    private static function doc(array $blocks): string
    {
        return implode("\n\n", $blocks);
    }

    public function testUndoKeepsLaterEditsOfAnyOtherBlockIncludingNeighbours(): void
    {
        mt_srand(7);
        $merger = new ContentMerger();

        for ($run = 0; $run < 300; $run++) {
            $n = mt_rand(3, 10);
            $base = array_map(static fn (int $i): string => self::p("p{$i}"), range(0, $n - 1));
            $positions = range(0, $n - 1);
            shuffle($positions);
            [$undone, $later] = [$positions[0], $positions[1]];
            [$before, $current, $expected] = [$base, $base, $base];
            $before[$undone] = $expected[$undone] = self::p("old{$undone}");
            $current[$later] = $expected[$later] = self::p("new{$later}");

            $result = $merger->undo(self::doc($base), self::doc($current), self::doc($before));
            $this->assertTrue($result !== null && $result->isClean(), "run {$run}");
            $this->assertSame(self::doc($expected), $result?->value, "run {$run}");
        }
    }

    public function testSameBlockChangedTwiceIsAConflictResolvedPerSide(): void
    {
        $merger = new ContentMerger();
        $after = self::doc([self::p('a'), self::p('b2'), self::p('c')]);
        $current = self::doc([self::p('a'), self::p('b3'), self::p('c')]);
        $before = self::doc([self::p('a'), self::p('b1'), self::p('c')]);

        $result = $merger->undo($after, $current, $before);
        $this->assertSame(1, count($result?->conflicts ?? []));
        $this->assertSame($current, $result?->value, 'unresolved conflicts keep the current text');
        $this->assertSame($before, $merger->undo($after, $current, $before, [0 => 'restored'])?->value);
    }

    public function testInsideContainersWithTheSameWrapperAndInClassicText(): void
    {
        $merger = new ContentMerger();
        $group = static fn (string $x, string $y): string => "<!-- wp:group -->\n<div class=\"wp-block-group\">"
            . self::doc([self::p($x), self::p($y)]) . "</div>\n<!-- /wp:group -->";

        $result = $merger->undo($group('x2', 'y'), $group('x2', 'y3'), $group('x1', 'y'));
        $this->assertSame($group('x1', 'y3'), $result?->value, 'nested blocks merge like top-level ones');

        $classic = $merger->undo("One.\n\nTwo changed.\n\nThree.", "One.\n\nTwo changed.\n\nThree, later.", "One.\n\nTwo.\n\nThree.");
        $this->assertSame("One.\n\nTwo.\n\nThree, later.", $classic?->value);
    }

    public function testSegmentsKeepEveryByte(): void
    {
        $markup = "intro\n<!-- wp:paragraph {\"align\":\"center\"} -->\n<p>A</p>\n<!-- /wp:paragraph -->\n\n"
            . "<!-- wp:group --><div><!-- wp:image /--></div><!-- /wp:group --><!-- wp:spacer /-->tail";
        $segments = (new BlockSegmenter())->segments($markup);
        $this->assertSame($markup, implode('', $segments ?? []));
        $this->assertNull((new BlockSegmenter())->segments('<!-- wp:paragraph --><p>unclosed</p>'));
    }

    public function testTermsAndKeyedValues(): void
    {
        // Undo added 7 and removed 3; a later edit added 9: later edits win per member.
        $this->assertSame([1, 2, 3, 9], SetMerger::undo([1, 2, 7], [1, 2, 7, 9], [1, 2, 3]));

        $conflicts = [];
        $merged = KeyedMerger::undo(
            ['color' => ['text' => '#111', 'bg' => '#fff'], 'spacing' => 8],
            ['color' => ['text' => '#111', 'bg' => '#eee'], 'spacing' => 8],
            ['color' => ['text' => '#000', 'bg' => '#fff'], 'spacing' => 12],
            $conflicts
        );
        $this->assertSame(['color' => ['text' => '#000', 'bg' => '#eee'], 'spacing' => 12], $merged);
        $this->assertSame([], $conflicts);
    }

    public function testPartialRevertOfOneChange(): void
    {
        $after = [self::p('a2'), "\n\n", self::p('b'), "\n\n", self::p('c2')];
        $before = [self::p('a1'), "\n\n", self::p('b'), "\n\n", self::p('c1')];
        $changes = array_values(array_filter(TwoWayDiff::hunks($after, $before) ?? [], static fn (array $h): bool => $h['kind'] === 'change'));

        $this->assertSame(2, count($changes));
        $this->assertSame(self::doc([self::p('a2'), self::p('b'), self::p('c1')]), TwoWayDiff::partialRevert($after, $before, [1]));
    }

    public function testBlameAttributesEachBlockToTheChangeThatLastModifiedIt(): void
    {
        $v0 = self::doc([self::p('a'), self::p('b'), self::p('c')]);
        $v1 = self::doc([self::p('a'), self::p('B!'), self::p('c')]);             // change 1 edits b
        $v2 = self::doc([self::p('a'), self::p('B!'), self::p('c'), self::p('d')]); // change 2 adds d
        $v3 = self::doc([self::p('A!'), self::p('B!'), self::p('c'), self::p('d')]); // change 3 edits a

        $result = (new Blame())->attribute($v3, [
            ['id' => 3, 'before' => $v2, 'after' => $v3],
            ['id' => 2, 'before' => $v1, 'after' => $v2],
            ['id' => 1, 'before' => $v0, 'after' => $v1],
        ]);
        $owners = [];

        foreach ($result['segments'] as $i => $segment) {
            if (trim($segment) !== '') {
                $owners[] = $result['owners'][$i]['change'] ?? null;
            }
        }

        $this->assertSame([3, 1, null, 2], $owners);
        $this->assertTrue($result['complete']);

        // A gap: the newest change does not start from the current text.
        $gap = (new Blame())->attribute($v3, [['id' => 2, 'before' => $v1, 'after' => $v2]]);
        $this->assertFalse($gap['complete']);
    }
}
