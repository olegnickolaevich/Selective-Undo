<?php

declare(strict_types=1);

namespace SelectiveUndo\Domain\Merge;

final class BlockSegmenter
{
    /** Same delimiter grammar as WP_Block_Parser::next_token(). */
    private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

    /**
     * @return list<string>|null Segments whose concatenation equals $markup, or null for broken nesting.
     */
    public function segments(string $markup): ?array
    {
        if (!preg_match_all(self::DELIMITER, $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            return $markup === '' ? [] : [$markup];
        }

        $segments = [];
        $depth = 0;
        $cursor = 0;
        $blockStart = 0;

        foreach ($matches as $m) {
            // The whole match always exists; unmatched optional groups are null.
            $token = (string) $m[0][0];
            $offset = $m[0][1];
            $isCloser = $m['closer'][0] === '/';
            $isVoid = $m['void'][0] === '/';

            if ($depth === 0) {
                if ($isCloser) {
                    return null;
                }

                if ($offset > $cursor) {
                    $segments[] = substr($markup, $cursor, $offset - $cursor);
                }

                if ($isVoid) {
                    $segments[] = $token;
                    $cursor = $offset + strlen($token);
                    continue;
                }

                $blockStart = $offset;
                $depth = 1;
                continue;
            }

            if ($isVoid) {
                continue;
            }

            $depth += $isCloser ? -1 : 1;

            if ($depth === 0) {
                $end = $offset + strlen($token);
                $segments[] = substr($markup, $blockStart, $end - $blockStart);
                $cursor = $end;
            }
        }

        if ($depth !== 0) {
            return null;
        }

        if ($cursor < strlen($markup)) {
            $segments[] = substr($markup, $cursor);
        }

        return $segments;
    }

    /**
     * Opening delimiter, inner markup and closing delimiter of one non-void block segment.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    public function unwrap(string $segment): ?array
    {
        if (!preg_match_all(self::DELIMITER, $segment, $m, PREG_OFFSET_CAPTURE) || count($m[0]) < 2) {
            return null;
        }

        $open = $m[0][0];
        $close = $m[0][count($m[0]) - 1];

        if ($open[1] !== 0 || $close[1] + strlen($close[0]) !== strlen($segment) || ($m['closer'][count($m[0]) - 1][0] ?? '') !== '/') {
            return null;
        }

        $innerStart = strlen($open[0]);

        return [$open[0], substr($segment, $innerStart, $close[1] - $innerStart), $close[0]];
    }
}
