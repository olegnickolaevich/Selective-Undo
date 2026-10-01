<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Merge;

/**
 * Which change last modified each top-level block (or paragraph) of a text. Walks
 * the changes from the newest to the oldest and stops when every part is
 * attributed or the history has a gap.
 */
final class Blame
{
    public function __construct(private readonly BlockSegmenter $segmenter = new BlockSegmenter())
    {
    }

    /**
     * @param iterable<array{id: int, before: string, after: string}> $changes Newest first; loaded lazily.
     *
     * @return array{segments: list<string>, owners: list<array{change: int, hunk: int|null}|null>, complete: bool}
     *         owners[i]: the change that last modified segment i and the number of its TwoWayDiff hunk
     *         holding that segment; null when the segment is older than the history.
     */
    public function attribute(string $current, iterable $changes): array
    {
        $segments = $this->tokens($current) ?? [$current];
        $owners = array_fill(0, count($segments), null);
        // Current index => index in the state being walked; null once attributed.
        $position = array_keys($segments);
        $state = $current;
        $open = count($segments);

        foreach ($changes as $change) {
            if ($open === 0) {
                break;
            }

            $after = $change['after'] === $state ? $this->tokens($change['after']) : null;
            $before = $after !== null ? $this->tokens($change['before']) : null;
            $diff = $after !== null && $before !== null ? TwoWayDiff::hunks($after, $before) : null;

            if ($diff === null) {
                // A gap in the history, or texts too different: older changes cannot be mapped.
                return ['segments' => $segments, 'owners' => $owners, 'complete' => false];
            }

            [$toBefore, $hunkOf] = self::walk($diff);

            foreach ($position as $i => $p) {
                if ($p === null || $owners[$i] !== null) {
                    continue;
                }

                if (isset($toBefore[$p])) {
                    $position[$i] = $toBefore[$p];
                } else {
                    $owners[$i] = ['change' => $change['id'], 'hunk' => $hunkOf[$p] ?? null];
                    $position[$i] = null;
                    $open--;
                }
            }

            $state = $change['before'];
        }

        return ['segments' => $segments, 'owners' => $owners, 'complete' => true];
    }

    /**
     * Index maps of one change: unchanged "after" tokens to their "before" index, and
     * changed "after" tokens to the number of their hunk.
     *
     * @param list<array{kind: 'same', tokens: list<string>}|array{kind: 'change', index: int, after: list<string>, before: list<string>}> $diff
     *
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private static function walk(array $diff): array
    {
        $toBefore = [];
        $hunkOf = [];
        $a = 0;
        $b = 0;

        foreach ($diff as $hunk) {
            if ($hunk['kind'] === 'same') {
                foreach ($hunk['tokens'] as $_) {
                    $toBefore[$a++] = $b++;
                }

                continue;
            }

            foreach ($hunk['after'] as $_) {
                $hunkOf[$a++] = $hunk['index'];
            }

            $b += count($hunk['before']);
        }

        return [$toBefore, $hunkOf];
    }

    /**
     * @return list<string>|null
     */
    private function tokens(string $value): ?array
    {
        return str_contains($value, '<!-- wp:') ? $this->segmenter->segments($value) : ContentMerger::paragraphs($value);
    }
}
