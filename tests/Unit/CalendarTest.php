<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Service\Calendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarTest extends TestCase
{
    /** @return iterable<array{int, string}> */
    public static function easters(): iterable
    {
        yield [2024, '2024-03-31'];
        yield [2025, '2025-04-20'];
        yield [2026, '2026-04-05'];
        yield [2027, '2027-03-28'];
        yield [2038, '2038-04-25'];
    }

    #[DataProvider('easters')]
    public function testEasterFallsWhereTheChurchSaysItDoes(int $year, string $sunday): void
    {
        self::assertSame($sunday, Calendar::easter($year)->format('Y-m-d'));
    }

    public function testTheMovingHolidaysFollowEaster(): void
    {
        $days = Calendar::hungarianHolidays(2026);

        self::assertSame('Nagypéntek', $days['2026-04-03']);
        self::assertSame('Húsvéthétfő', $days['2026-04-06']);
        self::assertSame('Pünkösdhétfő', $days['2026-05-25']);
        self::assertSame('Nemzeti ünnep', $days['2026-10-23']);
        self::assertCount(14, $days);
        self::assertSame(array_keys($days), array_values(array_unique(array_keys($days))));
    }

    public function testAWorkingWeekIsFiveDaysUnlessThePersonHasTheirOwn(): void
    {
        $usual = Calendar::week([]);
        self::assertSame(0, $usual[5]);
        self::assertSame(0, $usual[6]);
        self::assertGreaterThan(0, $usual[0]);

        self::assertSame([240, 240, 240, 240, 240, 0, 0], Calendar::week(['working_week' => '240,240,240,240,240,0,0']));
        // A malformed week is the usual one rather than a week of zeros.
        self::assertSame($usual, Calendar::week(['working_week' => '480,480']));
    }
}
