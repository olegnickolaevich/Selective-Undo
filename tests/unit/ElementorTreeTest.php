<?php

declare(strict_types=1);

namespace SelectiveUndo\Tests\Unit;

use SelectiveUndo\Domain\Merge\KeyedMerger;
use SelectiveUndo\Infrastructure\Integrations\ElementorTree;
use SelectiveUndo\Tests\TestCase;

final class ElementorTreeTest extends TestCase
{
    public function testTreeSurvivesTheRoundTrip(): void
    {
        $tree = self::page(['h1' => 'Welcome', 'p1' => 'Text']);
        $this->assertSame($tree, ElementorTree::build((array) ElementorTree::flatten($tree)));
        $this->assertNull(ElementorTree::flatten([['elType' => 'widget']]), 'no element ID');
        $this->assertNull(ElementorTree::flatten([self::widget('a', 'x'), self::widget('a', 'y')]), 'duplicate IDs');
    }

    public function testUndoOfOneWidgetEditKeepsLaterEditsOfOthers(): void
    {
        $before = self::page(['h1' => 'Welcome', 'p1' => 'Text']);
        $after = self::page(['h1' => 'Welcome!', 'p1' => 'Text']);
        // Later: the other widget changed, and a new one was added at the top.
        $now = self::page(['new' => 'Sale', 'h1' => 'Welcome!', 'p1' => 'Text, edited']);

        $merged = self::undo($after, $now, $before, $conflicts);

        $this->assertSame([], $conflicts);
        $this->assertSame(self::page(['new' => 'Sale', 'h1' => 'Welcome', 'p1' => 'Text, edited']), $merged);
    }

    public function testUndoOfAnInsertionRemovesOnlyThatElement(): void
    {
        $before = self::page(['h1' => 'Welcome', 'p1' => 'Text']);
        $after = self::page(['h1' => 'Welcome', 'banner' => 'Sale', 'p1' => 'Text']);
        $now = self::page(['h1' => 'Welcome', 'banner' => 'Sale', 'p1' => 'Text', 'p2' => 'More']);

        $this->assertSame(self::page(['h1' => 'Welcome', 'p1' => 'Text', 'p2' => 'More']), self::undo($after, $now, $before, $conflicts));
        $this->assertSame([], $conflicts);
    }

    public function testTheSameSettingChangedAgainIsAConflict(): void
    {
        $before = self::page(['h1' => 'Welcome']);
        $after = self::page(['h1' => 'Welcome!']);
        $now = self::page(['h1' => 'Hello']);

        self::undo($after, $now, $before, $conflicts);
        $this->assertSame([['h1', 'element', 'settings', 'title']], $conflicts);
    }

    /**
     * @param list<array<string, mixed>> $base
     * @param list<array<string, mixed>> $current
     * @param list<array<string, mixed>> $restored
     * @param list<list<array-key>>|null $conflicts
     *
     * @return list<array<array-key, mixed>>
     */
    private static function undo(array $base, array $current, array $restored, ?array &$conflicts): array
    {
        $conflicts = [];

        return ElementorTree::build(KeyedMerger::undo(
            (array) ElementorTree::flatten($base),
            (array) ElementorTree::flatten($current),
            (array) ElementorTree::flatten($restored),
            $conflicts
        ));
    }

    /**
     * One section with one column holding text widgets, in this order.
     *
     * @param array<string, string> $widgets id => title
     *
     * @return list<array<string, mixed>>
     */
    private static function page(array $widgets): array
    {
        $inner = [];

        foreach ($widgets as $id => $title) {
            $inner[] = self::widget($id, $title);
        }

        return [[
            'id' => 'sec1',
            'elType' => 'section',
            'settings' => ['layout' => 'boxed'],
            'elements' => [['id' => 'col1', 'elType' => 'column', 'settings' => ['_column_size' => 100], 'elements' => $inner]],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function widget(string $id, string $title): array
    {
        return ['id' => $id, 'elType' => 'widget', 'settings' => ['title' => $title, 'align' => 'left'], 'widgetType' => 'heading', 'elements' => []];
    }
}
