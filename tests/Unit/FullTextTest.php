<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Core\FullText;
use PHPUnit\Framework\TestCase;

final class FullTextTest extends TestCase
{
    public function testEveryWordTheIndexKeepsIsRequiredAndMayGoOn(): void
    {
        self::assertSame('+refund* +twice*', FullText::booleanQuery('Refund, twice!'));
    }

    public function testWordsTheIndexDoesNotKeepAreLeftOut(): void
    {
        self::assertSame('+refund*', FullText::booleanQuery('the refund is on'));
        self::assertNull(FullText::booleanQuery('UI to do'), 'nothing left to look up: read the text instead');
    }

    public function testTheIndexsOwnOperatorsCannotBeTyped(): void
    {
        self::assertSame('+drop* +table*', FullText::booleanQuery('-drop +table* "(")'));
    }

    public function testAccentsAreWords(): void
    {
        self::assertSame('+kosár* +fizetés*', FullText::booleanQuery('Kosár fizetés'));
    }

    public function testAnExcerptMarksTheWordsAndEscapesTheRest(): void
    {
        $excerpt = FullText::excerpt('Two agents <b>pressed</b> the refund button & it paid twice.', 'refund gt');

        self::assertStringContainsString('<mark>refund</mark>', $excerpt);
        self::assertStringNotContainsString('<b>', $excerpt, 'tags are taken out, not run');
        self::assertStringNotContainsString('<mark>gt</mark>', $excerpt);
        self::assertStringContainsString('&amp;', $excerpt);
    }

    public function testALongTextIsCutAroundTheFirstWord(): void
    {
        $text = str_repeat('filler ', 60) . 'the idempotent refund' . str_repeat(' more', 60);
        $excerpt = FullText::excerpt($text, 'idempotent', 80);

        self::assertStringStartsWith('…', $excerpt);
        self::assertStringEndsWith('…', $excerpt);
        self::assertStringContainsString('<mark>idempotent</mark>', $excerpt);
    }
}
