<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Controller\TimesheetController;
use PHPUnit\Framework\TestCase;

final class TimesheetCalendarTest extends TestCase
{
    /** @param list<array{0: ?string, 1: int}> $entries start, minutes */
    private static function day(array $entries): array
    {
        return ['2026-09-21' => ['entries' => array_map(
            static fn(array $e): array => ['started_at' => $e[0], 'minutes' => $e[1]],
            $entries
        )]];
    }

    public function testEntriesThatOverlapGoSideBySide(): void
    {
        $layout = TimesheetController::calendar(self::day([['09:00:00', 120], ['10:00:00', 60], ['13:00:00', 30]]));
        $placed = $layout['days']['2026-09-21']['placed'];

        self::assertSame([0, 1, 0], array_column($placed, 'lane'));
        self::assertSame([2, 2, 1], array_column($placed, 'lanes'));
    }

    public function testEntriesWithoutAStartAreListedApart(): void
    {
        $layout = TimesheetController::calendar(self::day([[null, 90], ['09:00:00', 60]]));

        self::assertCount(1, $layout['days']['2026-09-21']['loose']);
        self::assertCount(1, $layout['days']['2026-09-21']['placed']);
    }

    public function testTheHoursStretchToTakeInAnEarlyStartOrALateEnd(): void
    {
        self::assertSame(['from' => 8 * 60, 'to' => 18 * 60], array_slice(TimesheetController::calendar(self::day([['09:00:00', 60]])), 0, 2));

        $early = TimesheetController::calendar(self::day([['06:30:00', 60], ['19:15:00', 90]]));
        self::assertSame(6 * 60, $early['from']);
        self::assertSame(21 * 60, $early['to']);
    }
}
