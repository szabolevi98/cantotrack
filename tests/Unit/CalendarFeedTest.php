<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Service\CalendarFeed;
use PHPUnit\Framework\TestCase;

final class CalendarFeedTest extends TestCase
{
    private const ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
        // A one-off meeting in UTC: 08:00Z is 10:00 in Budapest in September.
        . "BEGIN:VEVENT\r\nUID:one\r\nDTSTART:20260922T080000Z\r\nDTEND:20260922T090000Z\r\nSUMMARY:Review with\r\n  the client\r\nEND:VEVENT\r\n"
        // A weekly stand-up, Monday to Friday, in a named zone, with its Wednesday taken out.
        . "BEGIN:VEVENT\r\nUID:standup\r\nDTSTART;TZID=Europe/Budapest:20260901T093000\r\nDURATION:PT15M\r\n"
        . "RRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR\r\nEXDATE;TZID=Europe/Budapest:20260923T093000\r\nSUMMARY:Stand-up\r\n"
        . "BEGIN:VALARM\r\nTRIGGER:-PT10M\r\nSUMMARY:Not this\r\nEND:VALARM\r\nEND:VEVENT\r\n"
        // Thursday's stand-up moved to eleven.
        . "BEGIN:VEVENT\r\nUID:standup\r\nRECURRENCE-ID;TZID=Europe/Budapest:20260924T093000\r\nDTSTART;TZID=Europe/Budapest:20260924T110000\r\nDTEND;TZID=Europe/Budapest:20260924T111500\r\nSUMMARY:Stand-up (moved)\r\nEND:VEVENT\r\n"
        // Outlook's name for the zone, a day-long event, and a cancelled one.
        . "BEGIN:VEVENT\r\nUID:planning\r\nDTSTART;TZID=Central Europe Standard Time:20260925T140000\r\nDTEND;TZID=Central Europe Standard Time:20260925T153000\r\nSUMMARY:Planning\\, next sprint\r\nEND:VEVENT\r\n"
        . "BEGIN:VEVENT\r\nUID:holiday\r\nDTSTART;VALUE=DATE:20260923\r\nSUMMARY:Day off\r\nEND:VEVENT\r\n"
        . "BEGIN:VEVENT\r\nUID:gone\r\nDTSTART:20260922T120000Z\r\nDTEND:20260922T130000Z\r\nSTATUS:CANCELLED\r\nSUMMARY:Cancelled\r\nEND:VEVENT\r\n"
        . "END:VCALENDAR\r\n";

    public function testAWeekOfMeetingsIsReadTheWayTheCalendarShowsIt(): void
    {
        $events = CalendarFeed::parse(self::ICS, '2026-09-21', '2026-09-27');
        $seen = array_map(static fn(array $e): string => $e['day'] . ' ' . sprintf('%02d:%02d', intdiv($e['start'], 60), $e['start'] % 60) . ' ' . $e['summary'], $events);

        self::assertSame([
            '2026-09-21 09:30 Stand-up',
            '2026-09-22 09:30 Stand-up',
            '2026-09-22 10:00 Review with the client',
            '2026-09-24 11:00 Stand-up (moved)',
            '2026-09-25 09:30 Stand-up',
            '2026-09-25 14:00 Planning, next sprint',
        ], $seen);

        self::assertSame(9 * 60 + 45, $events[0]['end']);
        self::assertSame(15 * 60 + 30, $events[5]['end']);
    }

    public function testNothingOutsideTheRange(): void
    {
        self::assertSame([], CalendarFeed::parse(self::ICS, '2026-08-01', '2026-08-02'));
    }

    public function testWebcalIsHttps(): void
    {
        self::assertSame('https://8.8.8.8/private.ics', CalendarFeed::address('webcal://8.8.8.8/private.ics'));
    }
}
