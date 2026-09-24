<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Service\CalendarExport;
use PHPUnit\Framework\TestCase;

final class CalendarExportTest extends TestCase
{
    public function testEventsAreWholeDaysWithTheirTextEscaped(): void
    {
        $ics = CalendarExport::ics([[
            'uid' => 'ticket-7-due',
            'day' => '2026-10-01',
            'summary' => 'CT-7 due: Commas, semicolons; and a \ backslash',
            'description' => "Two\nlines",
            'url' => 'https://example.test/tickets/7',
        ]], 'CantoTrack — Anna', 'example.test', '20260924T080000Z');

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringContainsString("DTSTART;VALUE=DATE:20261001\r\nDTEND;VALUE=DATE:20261002\r\n", $ics);
        self::assertStringContainsString('SUMMARY:CT-7 due: Commas\, semicolons\; and a \\\\ backslash', $ics);
        self::assertStringContainsString('DESCRIPTION:Two\nlines', $ics);
        self::assertStringContainsString('UID:ticket-7-due@example.test', $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
    }

    public function testLongLinesAreFoldedWithoutBreakingACharacter(): void
    {
        $ics = CalendarExport::ics([[
            'uid' => 'x', 'day' => '2026-10-01', 'summary' => str_repeat('árvíztűrő ', 20), 'description' => '', 'url' => 'https://example.test',
        ]], 'x', 'example.test', '20260924T080000Z');

        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, strlen($line));
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), 'a fold never cuts a character in two');
        }
        self::assertStringContainsString("\r\n ", $ics);
    }

    public function testASecretAndItsHashGoTogether(): void
    {
        [$secret, $hash] = CalendarExport::newSecret();

        self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $secret);
        self::assertSame(hash('sha256', $secret), $hash);
    }
}
