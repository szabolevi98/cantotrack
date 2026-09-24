<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Service\Flow;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FlowTest extends TestCase
{
    /** @return list<array{key: string, title: string, created_at: string, started_at: ?string, closed_at: ?string, resolution: ?string}> */
    private function tickets(): array
    {
        return [
            ['key' => 'CT-1', 'title' => 'a', 'created_at' => '2026-01-01 09:00:00', 'started_at' => '2026-01-02 09:00:00', 'closed_at' => '2026-01-04 09:00:00', 'resolution' => 'done'],
            ['key' => 'CT-2', 'title' => 'b', 'created_at' => '2026-01-02 09:00:00', 'started_at' => '2026-01-03 09:00:00', 'closed_at' => null, 'resolution' => null],
            ['key' => 'CT-3', 'title' => 'c', 'created_at' => '2026-01-03 09:00:00', 'started_at' => null, 'closed_at' => null, 'resolution' => null],
            ['key' => 'CT-4', 'title' => 'd', 'created_at' => '2026-01-01 09:00:00', 'started_at' => null, 'closed_at' => '2026-01-03 12:00:00', 'resolution' => 'wont_do'],
        ];
    }

    public function testEachDayCountsWhatWasWaitingUnderWayAndDone(): void
    {
        $days = Flow::cumulative($this->tickets(), new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-04'));

        self::assertSame(['day' => '2026-01-01', 'todo' => 2, 'in_progress' => 0, 'done' => 0], $days[0]);
        self::assertSame(['day' => '2026-01-03', 'todo' => 1, 'in_progress' => 2, 'done' => 1], $days[2]);
        self::assertSame(['day' => '2026-01-04', 'todo' => 1, 'in_progress' => 1, 'done' => 2], $days[3]);
    }

    public function testTimesLeaveOutWhatWasDecidedAgainst(): void
    {
        $times = Flow::times($this->tickets(), new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

        self::assertCount(1, $times);
        self::assertSame(2.0, $times[0]['cycle']);
        self::assertSame(3.0, $times[0]['lead']);
    }

    public function testTheSummarySaysWhat85OfEvery100WereDoneWithin(): void
    {
        $summary = Flow::summary([1.0, 2.0, 3.0, 4.0, 10.0]);

        self::assertSame(5, $summary['count']);
        self::assertSame(4.0, $summary['average']);
        self::assertSame(3.0, $summary['median']);
        self::assertSame(6.4, $summary['p85']);
        self::assertSame(0, Flow::summary([])['count']);
    }

    public function testWeeksCountWhatCameInAndWentOut(): void
    {
        $weeks = Flow::weekly($this->tickets(), new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-11'));

        self::assertSame('2025-12-29', $weeks[0]['week']);
        self::assertSame(4, $weeks[0]['created']);
        self::assertSame(2, $weeks[0]['resolved']);
        self::assertSame(0, $weeks[1]['created']);
    }

    public function testABurnupHasNoDoneLineInTheFuture(): void
    {
        $days = Flow::burnup($this->tickets(), new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-06'), new DateTimeImmutable('2026-01-04'));

        self::assertSame(2, $days[0]['scope']);
        self::assertSame(2, $days[3]['done']);
        self::assertNull($days[4]['done']);
        self::assertSame(4, $days[4]['scope']);
    }
}
