<?php

namespace justinholtweb\visorr\tests\unit;

use justinholtweb\visorr\helpers\TextDiff;
use PHPUnit\Framework\TestCase;

/**
 * The diff has one property that matters above all the others, and it is the one a
 * hand-written LCS is easiest to get subtly wrong: **the runs must reassemble into the two
 * inputs**. Everything else is presentation; that one is correctness.
 */
class TextDiffTest extends TestCase
{
    public function testIdenticalStringsProduceOneEqualRun(): void
    {
        self::assertSame([[TextDiff::EQUAL, 'unchanged text']], TextDiff::diff('unchanged text', 'unchanged text'));
    }

    public function testEmptyInputsProduceNothing(): void
    {
        self::assertSame([], TextDiff::diff('', ''));
    }

    public function testAnEmptySideIsWhollyAddedOrRemoved(): void
    {
        self::assertSame([[TextDiff::ADDED, 'hello']], TextDiff::diff('', 'hello'));
        self::assertSame([[TextDiff::REMOVED, 'hello']], TextDiff::diff('hello', ''));
    }

    public function testAReplacedWordIsOneDeletionAndOneInsertion(): void
    {
        $ops = array_column(TextDiff::diff('the quick brown fox', 'the quick red fox'), 0);

        self::assertSame([TextDiff::EQUAL, TextDiff::REMOVED, TextDiff::ADDED, TextDiff::EQUAL], $ops);
    }

    /**
     * @dataProvider roundTripProvider
     */
    public function testRunsReassembleIntoBothInputs(string $old, string $new): void
    {
        $left = '';
        $right = '';

        foreach (TextDiff::diff($old, $new) as [$op, $text]) {
            if ($op !== TextDiff::ADDED) {
                $left .= $text;
            }
            if ($op !== TextDiff::REMOVED) {
                $right .= $text;
            }
        }

        self::assertSame($old, $left, 'the equal and removed runs must reproduce the old value');
        self::assertSame($new, $right, 'the equal and added runs must reproduce the new value');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'prose edit' => [
                "First paragraph.\n\nSecond paragraph with more words.",
                "First paragraph.\n\nSecond paragraph with rather more words, actually.",
            ],
            'whitespace only' => ["a  b", "a b"],
            'punctuation' => ['Hello, world.', 'Hello world!'],
            'accented characters' => ['naïve café', 'naive cafe'],
            'leading insertion' => ['b c d', 'a b c d'],
            'trailing deletion' => ['a b c d', 'a b c'],
            'complete rewrite' => ['one two three', 'four five six'],
            'repeated words' => ['the the the', 'the the'],
            'newlines' => ["a\nb\nc", "a\nc\nb"],
            'emoji' => ['before 🚀 after', 'before 🛰 after'],
        ];
    }

    public function testTokenizationKeepsWhitespaceSoRunsCanBeReassembled(): void
    {
        self::assertSame('one  two', implode('', TextDiff::tokenize('one  two')));
    }

    public function testStatsCountWords(): void
    {
        self::assertSame(
            ['added' => 2, 'removed' => 0],
            TextDiff::stats('one two three', 'one two three four five')
        );

        self::assertSame(
            ['added' => 0, 'removed' => 1],
            TextDiff::stats('one two three', 'one two')
        );
    }

    public function testStatsIgnoreWhitespaceOnlyRuns(): void
    {
        $stats = TextDiff::stats('one two', "one  two");

        self::assertSame(0, $stats['added']);
        self::assertSame(0, $stats['removed']);
    }

    public function testHtmlOutputEscapesItsInput(): void
    {
        $html = TextDiff::toHtml('safe', '<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testHtmlOutputMarksInsertionsAndDeletions(): void
    {
        $html = TextDiff::toHtml('the old word', 'the new word');

        self::assertStringContainsString('<del class="visorr-del">old</del>', $html);
        self::assertStringContainsString('<ins class="visorr-ins">new</ins>', $html);
    }

    /**
     * Past the token ceiling the diff drops to lines rather than giving up or exhausting
     * memory. The guarantee that survives is the one that matters: it still reports a
     * difference, and the runs still reassemble.
     */
    public function testVeryLargeValuesStillDiff(): void
    {
        $old = implode(' ', array_map(fn(int $i) => "word$i", range(1, 8000)));
        $new = $old . ' and one more';

        $runs = TextDiff::diff($old, $new);

        self::assertNotSame([], $runs);
        self::assertContains(TextDiff::ADDED, array_column($runs, 0));

        $right = '';
        foreach ($runs as [$op, $text]) {
            if ($op !== TextDiff::REMOVED) {
                $right .= $text;
            }
        }

        self::assertSame($new, $right);
    }

    /**
     * Two values with nothing in common and both past the ceiling take the last-resort path:
     * one deletion, one insertion, no attempt at alignment.
     */
    public function testTwoHugeUnrelatedValuesFallBackToWholesaleReplacement(): void
    {
        $old = implode(' ', array_map(fn(int $i) => "alpha$i", range(1, 6000)));
        $new = implode(' ', array_map(fn(int $i) => "beta$i", range(1, 6000)));

        $ops = array_column(TextDiff::diff($old, $new), 0);

        self::assertSame([TextDiff::REMOVED, TextDiff::ADDED], $ops);
    }
}
