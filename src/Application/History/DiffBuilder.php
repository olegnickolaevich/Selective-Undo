<?php

declare(strict_types=1);

namespace SelectiveUndo\Application\History;

use SelectiveUndo\Domain\Value\FieldValue;

/**
 * Structured line diff for the UI. Output is plain text lines; the UI renders
 * them as text nodes only. Unchanged runs are collapsed around changes.
 */
final class DiffBuilder
{
    public const CONTEXT = 3;
    public const PAGE_LINES = 400;
    public const MAX_INPUT_LINES = 20000;

    /**
     * @return array{lines: list<array{op: string, text?: string, count?: int}>, truncated: bool, next_offset: int|null, binary: bool, total_lines: int}
     */
    public function build(FieldValue $left, FieldValue $right, int $offset = 0): array
    {
        if ($left->isBinary() || $right->isBinary()) {
            return ['lines' => [], 'truncated' => false, 'next_offset' => null, 'binary' => true, 'total_lines' => 0];
        }

        $from = $this->lines($left->toDisplayString());
        $to = $this->lines($right->toDisplayString());
        $inputTruncated = false;

        if (count($from) > self::MAX_INPUT_LINES || count($to) > self::MAX_INPUT_LINES) {
            $from = array_slice($from, 0, self::MAX_INPUT_LINES);
            $to = array_slice($to, 0, self::MAX_INPUT_LINES);
            $inputTruncated = true;
        }

        $ops = $this->collapse($this->diff($from, $to));
        $total = count($ops);
        $page = array_slice($ops, max(0, $offset), self::PAGE_LINES);
        $next = $offset + self::PAGE_LINES < $total ? $offset + self::PAGE_LINES : null;

        return [
            'lines' => $page,
            'truncated' => $next !== null || $inputTruncated,
            'next_offset' => $next,
            'binary' => false,
            'total_lines' => $total,
        ];
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return preg_split('/\r\n|\n|\r/', $text) ?: [];
    }

    /**
     * @param list<string> $from
     * @param list<string> $to
     *
     * @return list<array{op: string, text: string}>
     */
    private function diff(array $from, array $to): array
    {
        if (!class_exists('Text_Diff', false)) {
            require_once ABSPATH . WPINC . '/Text/Diff.php';
        }

        $out = [];
        $diff = new \Text_Diff('auto', [$from, $to]);

        foreach ($diff->getDiff() as $edit) {
            $class = get_class($edit);

            if ($class === 'Text_Diff_Op_copy') {
                foreach ((array) $edit->orig as $line) {
                    $out[] = ['op' => 'equal', 'text' => (string) $line];
                }

                continue;
            }

            foreach ((array) ($edit->orig ?? []) as $line) {
                $out[] = ['op' => 'delete', 'text' => (string) $line];
            }

            foreach ((array) ($edit->final ?? []) as $line) {
                $out[] = ['op' => 'insert', 'text' => (string) $line];
            }
        }

        return $out;
    }

    /**
     * @param list<array{op: string, text: string}> $ops
     *
     * @return list<array{op: string, text?: string, count?: int}>
     */
    private function collapse(array $ops): array
    {
        $n = count($ops);
        $keep = array_fill(0, $n, false);

        foreach ($ops as $i => $op) {
            if ($op['op'] !== 'equal') {
                for ($j = max(0, $i - self::CONTEXT); $j <= min($n - 1, $i + self::CONTEXT); $j++) {
                    $keep[$j] = true;
                }
            }
        }

        if (!in_array(true, $keep, true)) {
            // No differences at all: show a short head.
            for ($j = 0; $j < min($n, self::CONTEXT * 2); $j++) {
                $keep[$j] = true;
            }
        }

        $out = [];
        $hidden = 0;

        foreach ($ops as $i => $op) {
            if ($keep[$i]) {
                if ($hidden > 0) {
                    $out[] = ['op' => 'collapsed', 'count' => $hidden];
                    $hidden = 0;
                }

                $out[] = $op;
            } else {
                $hidden++;
            }
        }

        if ($hidden > 0) {
            $out[] = ['op' => 'collapsed', 'count' => $hidden];
        }

        return $out;
    }
}
