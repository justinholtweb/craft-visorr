<?php

namespace justinholtweb\visorr\tests\unit;

use justinholtweb\visorr\helpers\ValueFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Only the Craft-free half of the formatter is unit-tested: turning markup into diffable text.
 * Everything that reads a field value needs a real field and a real element, and is covered by
 * the integration checks instead.
 */
class ValueFormatterTest extends TestCase
{
    public function testPlainTextIsLeftAlone(): void
    {
        self::assertSame('no markup here', ValueFormatter::htmlToText('no markup here'));
    }

    public function testParagraphsBecomeBlankLines(): void
    {
        self::assertSame("First\n\nSecond", ValueFormatter::htmlToText('<p>First</p><p>Second</p>'));
    }

    public function testListItemsBecomeBullets(): void
    {
        self::assertSame("• One\n• Two", ValueFormatter::htmlToText('<ul><li>One</li><li>Two</li></ul>'));
    }

    public function testLineBreaksSurvive(): void
    {
        self::assertSame("One\nTwo", ValueFormatter::htmlToText('One<br>Two'));
    }

    public function testScriptAndStyleContentsAreDropped(): void
    {
        self::assertSame('Hi', ValueFormatter::htmlToText('<p>Hi</p><script>alert(1)</script>'));
        self::assertSame('Hi', ValueFormatter::htmlToText('<style>p { color: red }</style><p>Hi</p>'));
    }

    public function testEntitiesAreDecoded(): void
    {
        self::assertSame('Fish & chips “quoted”', ValueFormatter::htmlToText('<p>Fish &amp; chips &ldquo;quoted&rdquo;</p>'));
    }

    /**
     * A wrapper class change should not read as a rewritten paragraph — that is the whole
     * reason rich text is flattened before it is diffed.
     */
    public function testAttributeChangesDoNotChangeTheText(): void
    {
        self::assertSame(
            ValueFormatter::htmlToText('<p class="lead">Words</p>'),
            ValueFormatter::htmlToText('<p class="intro" id="x">Words</p>')
        );
    }

    public function testTrailingWhitespaceIsNormalised(): void
    {
        self::assertSame("One\n\nTwo", ValueFormatter::htmlToText('<p>One   </p>   <p>   Two</p>'));
    }
}
