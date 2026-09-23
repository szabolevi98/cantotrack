<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Service\Roadmap;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RoadmapTest extends TestCase
{
    public function testTheWindowIsSixWholeMonthsFromTwoMonthsBack(): void
    {
        $window = Roadmap::window('', new DateTimeImmutable('2026-09-24'));

        self::assertSame('2026-07-01', $window['from']->format('Y-m-d'));
        self::assertSame('2026-12-31', $window['to']->format('Y-m-d'));
        self::assertSame('2027-01-01', Roadmap::window('2027-01', new DateTimeImmutable('2026-09-24'))['from']->format('Y-m-d'));
    }

    public function testAStretchIsPlacedByItsDaysBothEndsCounted(): void
    {
        // January 2026: 31 days; the 1st to the 31st is the whole of it.
        $from = new DateTimeImmutable('2026-01-01');
        $to = new DateTimeImmutable('2026-01-31');

        self::assertSame(['left' => 0.0, 'width' => 100.0, 'before' => false, 'after' => false], Roadmap::place('2026-01-01', '2026-01-31', $from, $to));

        $half = Roadmap::place('2026-01-17', '2026-01-31', $from, $to);
        self::assertSame(51.613, $half['left']);
        self::assertSame(48.387, $half['width']);
    }

    public function testAStretchRunningPastTheWindowSaysSo(): void
    {
        $from = new DateTimeImmutable('2026-01-01');
        $to = new DateTimeImmutable('2026-01-31');

        $placed = Roadmap::place('2025-12-01', '2026-02-10', $from, $to);

        self::assertSame(0.0, $placed['left']);
        self::assertSame(100.0, $placed['width']);
        self::assertTrue($placed['before']);
        self::assertTrue($placed['after']);
        self::assertNull(Roadmap::place('2026-03-01', '2026-03-05', $from, $to));
    }

    public function testTheMonthsFillTheWindow(): void
    {
        $months = Roadmap::months(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-06-30'));

        self::assertCount(6, $months);
        self::assertSame('2026-02-01', $months[1]['month']);
        self::assertEqualsWithDelta(100.0, array_sum(array_column($months, 'width')), 0.01);
    }
}
