<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Core\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    /** @return iterable<string, array{string, ?int}> */
    public static function durations(): iterable
    {
        yield 'hours and minutes' => ['1h 30m', 90];
        yield 'no space' => ['1h30m', 90];
        yield 'hours alone' => ['2h', 120];
        yield 'minutes alone' => ['45m', 45];
        yield 'decimal hours' => ['1.5h', 90];
        yield 'decimal comma, as typed in Hungary' => ['1,5h', 90];
        yield 'clock form' => ['1:30', 90];
        yield 'bare number is minutes' => ['90', 90];
        yield 'upper case' => ['2H 15M', 135];
        yield 'surrounding space' => ['  3h ', 180];
        yield 'minutes without their m' => ['1h30', 90];
        yield 'a working day' => ['2d', 960];
        yield 'half a day, with a comma' => ['0,5d', 240];
        yield 'a working week' => ['1w', 2400];
        yield 'weeks, days and hours together' => ['1w 2d 3h', 2400 + 960 + 180];
        yield 'nothing' => ['', null];
        yield 'words' => ['three apples', null];
        yield 'minutes past sixty on a clock' => ['1:75', null];
        yield 'a unit and nothing else' => ['h', null];
    }

    #[DataProvider('durations')]
    public function testParsesWhatPeopleType(string $typed, ?int $minutes): void
    {
        self::assertSame($minutes, Format::parseDuration($typed));
    }

    public function testWritesDurationsTheShortWay(): void
    {
        self::assertSame('0m', Format::duration(0));
        self::assertSame('45m', Format::duration(45));
        self::assertSame('2h', Format::duration(120));
        self::assertSame('7h 30m', Format::duration(450));
    }

    public function testWritesDecimalHoursWithoutTrailingZeros(): void
    {
        self::assertSame('7.5', Format::hours(450));
        self::assertSame('8', Format::hours(480));
        self::assertSame('0.25', Format::hours(15));
        self::assertSame('0', Format::hours(0));
    }

    public function testInitialsTakeTwoLettersAtMost(): void
    {
        self::assertSame('SL', Format::initials('Szabó Levente'));
        self::assertSame('ÁK', Format::initials('Ágnes Kiss Nagy'));
        self::assertSame('M', Format::initials('Madonna'));
        self::assertSame('?', Format::initials('   '));
    }
}
