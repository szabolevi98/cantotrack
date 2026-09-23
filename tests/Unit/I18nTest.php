<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Core\I18n;
use PHPUnit\Framework\TestCase;

final class I18nTest extends TestCase
{
    protected function tearDown(): void
    {
        I18n::setLocale('en');
    }

    public function testEnglishIsTheKeyItself(): void
    {
        I18n::setLocale('en');

        self::assertSame('Log time', I18n::translate('Log time'));
    }

    public function testPlaceholdersAreFilledAfterTranslation(): void
    {
        I18n::setLocale('en');

        self::assertSame('Hello, Levente', I18n::translate('Hello, {name}', ['name' => 'Levente']));
    }

    public function testPluralPicksTheSentenceAndFillsTheCount(): void
    {
        I18n::setLocale('en');

        self::assertSame('1 ticket', I18n::plural('{count} ticket', '{count} tickets', 1));
        self::assertSame('3 tickets', I18n::plural('{count} ticket', '{count} tickets', 3));
    }

    public function testHungarianIsReadFromTheCatalogue(): void
    {
        I18n::setLocale('hu');

        self::assertSame('Mentés', I18n::translate('Save'));
        // After a number, the singular — for both halves of the pair.
        self::assertSame('1 nap módosult.', I18n::plural('{count} day changed.', '{count} days changed.', 1));
        self::assertSame('3 nap módosult.', I18n::plural('{count} day changed.', '{count} days changed.', 3));
    }

    public function testDatesAreWrittenTheHungarianWay(): void
    {
        I18n::setLocale('hu');
        self::assertSame('2026. szept. 22.', \CantoTrack\Core\Format::day('2026-09-22'));

        I18n::setLocale('en');
        self::assertSame('22 Sep 2026', \CantoTrack\Core\Format::day('2026-09-22'));
    }

    public function testAnUnknownLocaleFallsBackToEnglish(): void
    {
        I18n::setLocale('xx');

        self::assertSame('en', I18n::locale());
    }

    public function testAMissingTranslationFallsBackToTheEnglish(): void
    {
        I18n::setLocale('hu');

        self::assertSame('A sentence nobody translated', I18n::translate('A sentence nobody translated'));
    }
}
