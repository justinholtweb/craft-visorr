<?php

namespace justinholtweb\visorr\helpers;

/**
 * Word-level text diff, in pure PHP with no dependencies.
 *
 * This is deliberately not a composer package. Visorr needs one thing — "show me which words
 * changed between these two field values" — and the honest implementation of that is a couple
 * of hundred lines. The family has a standing preference for zero runtime dependencies, and a
 * diff library is a lot of surface area to inherit for one function.
 *
 * The algorithm is a longest-common-subsequence diff over *tokens* rather than characters,
 * because a character diff of prose is unreadable — it shreds words into fragments. Tokens are
 * words, runs of whitespace, and individual punctuation marks, so "colour" → "color" shows as
 * one word replaced rather than three characters shuffled.
 *
 * LCS is O(n·m) in both time and memory, which is fine for a headline and ruinous for a
 * 300KB rich-text field. {@see diff()} therefore does three things before it reaches the DP
 * table: trims the common prefix and suffix (which for a real edit is nearly everything),
 * checks what is left against {@see MAX_TOKENS}, and drops to a line-level comparison if the
 * remainder is still too big. A line diff of a huge value is less precise but it renders, and
 * something that renders beats something that exhausts the memory limit.
 */
abstract class TextDiff
{
    /** Unchanged run. */
    public const EQUAL = '=';

    /** Present in the old value only. */
    public const REMOVED = '-';

    /** Present in the new value only. */
    public const ADDED = '+';

    /**
     * Most tokens either side will go through the word-level DP table. 2,500 × 2,500 is a
     * 6.25M-cell table, which PHP handles in well under a second; an order of magnitude more
     * does not.
     */
    public const MAX_TOKENS = 2500;

    /**
     * Compare two strings and return the runs that make up the difference.
     *
     * @return array<int, array{0: string, 1: string}> [op, text] pairs, in order. Concatenating
     *   the EQUAL and REMOVED runs reproduces the old string; EQUAL and ADDED reproduce the new.
     */
    public static function diff(string $old, string $new): array
    {
        if ($old === $new) {
            return $old === '' ? [] : [[self::EQUAL, $old]];
        }

        if ($old === '') {
            return [[self::ADDED, $new]];
        }

        if ($new === '') {
            return [[self::REMOVED, $old]];
        }

        $oldTokens = self::tokenize($old);
        $newTokens = self::tokenize($new);

        [$prefix, $oldTokens, $newTokens, $suffix] = self::trimCommonEnds($oldTokens, $newTokens);

        if (count($oldTokens) > self::MAX_TOKENS || count($newTokens) > self::MAX_TOKENS) {
            // Too big to diff word by word. Fall back to lines, which for a long value is
            // usually the more readable answer anyway.
            $oldTokens = self::splitLines(implode('', $oldTokens));
            $newTokens = self::splitLines(implode('', $newTokens));

            if (count($oldTokens) > self::MAX_TOKENS || count($newTokens) > self::MAX_TOKENS) {
                $runs = [];
                if ($prefix !== []) {
                    $runs[] = [self::EQUAL, implode('', $prefix)];
                }
                $runs[] = [self::REMOVED, implode('', $oldTokens)];
                $runs[] = [self::ADDED, implode('', $newTokens)];
                if ($suffix !== []) {
                    $runs[] = [self::EQUAL, implode('', $suffix)];
                }
                return $runs;
            }
        }

        $ops = self::lcsOps($oldTokens, $newTokens);

        if ($prefix !== []) {
            array_unshift($ops, [self::EQUAL, $prefix]);
        }
        if ($suffix !== []) {
            $ops[] = [self::EQUAL, $suffix];
        }

        return self::coalesce($ops);
    }

    /**
     * The diff as HTML, with changed runs wrapped in `<del>` and `<ins>`.
     *
     * Every token is escaped here. Callers pass plain text — a rich-text field's markup is
     * flattened to text by {@see ValueFormatter} before it ever reaches this class — so tags
     * arriving in the input are content, not markup, and must not be rendered as markup.
     */
    public static function toHtml(string $old, string $new): string
    {
        $html = '';

        foreach (self::diff($old, $new) as [$op, $text]) {
            $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $html .= match ($op) {
                self::REMOVED => '<del class="visorr-del">' . $escaped . '</del>',
                self::ADDED => '<ins class="visorr-ins">' . $escaped . '</ins>',
                default => '<span class="visorr-same">' . $escaped . '</span>',
            };
        }

        return $html;
    }

    /**
     * How much changed, in words. Used for the "12 words added, 3 removed" summary and for
     * ordering fields on the comparison screen by how much they actually moved.
     *
     * @return array{added: int, removed: int}
     */
    public static function stats(string $old, string $new): array
    {
        $added = 0;
        $removed = 0;

        foreach (self::diff($old, $new) as [$op, $text]) {
            if ($op === self::EQUAL) {
                continue;
            }

            $words = count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            if ($op === self::ADDED) {
                $added += $words;
            } else {
                $removed += $words;
            }
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Split a string into diffable tokens: words, whitespace runs, and single punctuation
     * marks. Whitespace is kept as its own token so the runs can be reassembled byte for byte.
     *
     * @return string[]
     */
    public static function tokenize(string $string): array
    {
        $tokens = preg_split(
            '/(\s+|[^\p{L}\p{N}_\s])/u',
            $string,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        // preg_split returns false on malformed UTF-8; a broken value should still diff, so
        // fall back to bytes rather than throwing in the middle of a comparison screen.
        return $tokens !== false ? $tokens : str_split($string);
    }

    /**
     * @return string[]
     */
    private static function splitLines(string $string): array
    {
        $lines = preg_split('/(\R)/u', $string, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        return $lines !== false ? $lines : [$string];
    }

    /**
     * Strip the identical head and tail off both token lists.
     *
     * For a realistic edit this removes almost everything — someone changed one paragraph of
     * forty — and it is the single reason the DP table stays small enough to build.
     *
     * @param string[] $old
     * @param string[] $new
     * @return array{0: string[], 1: string[], 2: string[], 3: string[]} prefix, old rest, new rest, suffix
     */
    private static function trimCommonEnds(array $old, array $new): array
    {
        $prefix = [];
        while ($old !== [] && $new !== [] && $old[0] === $new[0]) {
            $prefix[] = array_shift($old);
            array_shift($new);
        }

        $suffix = [];
        while ($old !== [] && $new !== [] && end($old) === end($new)) {
            array_unshift($suffix, array_pop($old));
            array_pop($new);
        }

        return [$prefix, array_values($old), array_values($new), $suffix];
    }

    /**
     * Longest-common-subsequence diff over two token lists.
     *
     * The table holds match lengths; the walk back down it emits runs. Rows are plain arrays
     * rather than a single flat one because PHP's packed arrays make the row-at-a-time access
     * pattern here noticeably cheaper than index arithmetic.
     *
     * @param string[] $old
     * @param string[] $new
     * @return array<int, array{0: string, 1: string[]}>
     */
    private static function lcsOps(array $old, array $new): array
    {
        $n = count($old);
        $m = count($new);

        if ($n === 0 && $m === 0) {
            return [];
        }
        if ($n === 0) {
            return [[self::ADDED, $new]];
        }
        if ($m === 0) {
            return [[self::REMOVED, $old]];
        }

        // table[$i][$j] = length of the LCS of old[$i..] and new[$j..]
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            $rowBelow = $table[$i + 1];
            $row = $table[$i];
            for ($j = $m - 1; $j >= 0; $j--) {
                $row[$j] = $old[$i] === $new[$j]
                    ? $rowBelow[$j + 1] + 1
                    : max($rowBelow[$j], $row[$j + 1]);
            }
            $table[$i] = $row;
        }

        $ops = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($old[$i] === $new[$j]) {
                self::push($ops, self::EQUAL, $old[$i]);
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                self::push($ops, self::REMOVED, $old[$i]);
                $i++;
            } else {
                self::push($ops, self::ADDED, $new[$j]);
                $j++;
            }
        }

        while ($i < $n) {
            self::push($ops, self::REMOVED, $old[$i++]);
        }

        while ($j < $m) {
            self::push($ops, self::ADDED, $new[$j++]);
        }

        return $ops;
    }

    /**
     * @param array<int, array{0: string, 1: string[]}> $ops
     */
    private static function push(array &$ops, string $op, string $token): void
    {
        $last = count($ops) - 1;

        if ($last >= 0 && $ops[$last][0] === $op) {
            $ops[$last][1][] = $token;
            return;
        }

        $ops[] = [$op, [$token]];
    }

    /**
     * Merge adjacent runs of the same kind and join their tokens into strings.
     *
     * @param array<int, array{0: string, 1: string[]}> $ops
     * @return array<int, array{0: string, 1: string}>
     */
    private static function coalesce(array $ops): array
    {
        $runs = [];

        foreach ($ops as [$op, $tokens]) {
            $text = implode('', $tokens);

            if ($text === '') {
                continue;
            }

            $last = count($runs) - 1;

            if ($last >= 0 && $runs[$last][0] === $op) {
                $runs[$last][1] .= $text;
                continue;
            }

            $runs[] = [$op, $text];
        }

        return $runs;
    }
}
