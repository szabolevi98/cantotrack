<?php

namespace CantoTrack\Service;

use DateTimeImmutable;

/**
 * How work moves through a project: how much was waiting, under way and
 * done on each day; how long a ticket took from start to finish; and how
 * many came in and went out each week.
 *
 * Worked out from three moments of each ticket — when it was made, when it
 * first left the to-do columns, and when it was finished — rather than from
 * a replay of every move. A ticket reopened and finished again counts from
 * its last finish; the picture is of the flow, not an audit of it.
 *
 * Decided against or found to be a duplicate, a ticket is taken out of
 * the times: it was not work that took that long.
 */
final class Flow
{
    /**
     * @param list<array{created_at: string, started_at: ?string, closed_at: ?string}> $tickets
     * @return list<array{day: string, todo: int, in_progress: int, done: int}>
     */
    public static function cumulative(array $tickets, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $days = [];

        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            $end = $day->format('Y-m-d') . ' 23:59:59';
            $counts = ['todo' => 0, 'in_progress' => 0, 'done' => 0];

            foreach ($tickets as $ticket) {
                if ($ticket['created_at'] > $end) {
                    continue;
                }

                if ($ticket['closed_at'] !== null && $ticket['closed_at'] <= $end) {
                    $counts['done']++;
                } elseif ($ticket['started_at'] !== null && $ticket['started_at'] <= $end) {
                    $counts['in_progress']++;
                } else {
                    $counts['todo']++;
                }
            }

            $days[] = ['day' => $day->format('Y-m-d')] + $counts;
        }

        return $days;
    }

    /**
     * From start to finish, and from being written down to finish, for the
     * tickets finished in a span — in days, with a fraction.
     *
     * @param list<array{id?: int, key?: string, title?: string, created_at: string, started_at: ?string, closed_at: ?string, resolution?: ?string}> $tickets
     * @return list<array{key: string, title: string, closed: string, cycle: float, lead: float}>
     */
    public static function times(array $tickets, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $times = [];
        $first = $from->format('Y-m-d 00:00:00');
        $last = $to->format('Y-m-d 23:59:59');

        foreach ($tickets as $ticket) {
            $closed = $ticket['closed_at'];

            if ($closed === null || $closed < $first || $closed > $last
                || in_array($ticket['resolution'] ?? 'done', ['wont_do', 'duplicate'], true)) {
                continue;
            }

            // Straight from to-do to done: it started when it was finished,
            // as far as anybody can tell — no longer than the lead time.
            $started = $ticket['started_at'] ?? $closed;

            $times[] = [
                'key' => (string) ($ticket['key'] ?? ''),
                'title' => (string) ($ticket['title'] ?? ''),
                'closed' => substr($closed, 0, 10),
                'cycle' => self::days($started, $closed),
                'lead' => self::days($ticket['created_at'], $closed),
            ];
        }

        usort($times, static fn(array $a, array $b): int => $a['closed'] <=> $b['closed']);

        return $times;
    }

    /**
     * The average, the middle, and the time 85 of every 100 were done in —
     * the number to promise from, since an average hides the long tail.
     *
     * @param list<float> $values
     * @return array{count: int, average: float, median: float, p85: float}
     */
    public static function summary(array $values): array
    {
        if ($values === []) {
            return ['count' => 0, 'average' => 0.0, 'median' => 0.0, 'p85' => 0.0];
        }

        sort($values);

        return [
            'count' => count($values),
            'average' => round(array_sum($values) / count($values), 1),
            'median' => round(self::percentile($values, 50), 1),
            'p85' => round(self::percentile($values, 85), 1),
        ];
    }

    /**
     * How many were written down and how many finished, week by week.
     *
     * @param list<array{created_at: string, closed_at: ?string}> $tickets
     * @return list<array{week: string, created: int, resolved: int}>
     */
    public static function weekly(array $tickets, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $weeks = [];
        $monday = $from->modify('monday this week')->setTime(0, 0);

        for (; $monday <= $to; $monday = $monday->modify('+7 days')) {
            $weeks[$monday->format('Y-m-d')] = ['week' => $monday->format('Y-m-d'), 'created' => 0, 'resolved' => 0];
        }

        $weekOf = static fn(string $at): string => (new DateTimeImmutable(substr($at, 0, 10)))->modify('monday this week')->format('Y-m-d');

        foreach ($tickets as $ticket) {
            $created = $weekOf($ticket['created_at']);
            if (isset($weeks[$created])) {
                $weeks[$created]['created']++;
            }

            if ($ticket['closed_at'] !== null) {
                $closed = $weekOf($ticket['closed_at']);
                if (isset($weeks[$closed])) {
                    $weeks[$closed]['resolved']++;
                }
            }
        }

        return array_values($weeks);
    }

    /**
     * A release's scope and what of it was done, day by day: the line of
     * what is in it, and the line of what is finished climbing to meet it.
     *
     * @param list<array{created_at: string, closed_at: ?string}> $tickets
     * @return list<array{day: string, scope: int, done: ?int}>
     */
    public static function burnup(array $tickets, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $today): array
    {
        $days = [];

        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            $end = $day->format('Y-m-d') . ' 23:59:59';
            $scope = 0;
            $done = 0;

            foreach ($tickets as $ticket) {
                if ($ticket['created_at'] <= $end) {
                    $scope++;
                    if ($ticket['closed_at'] !== null && $ticket['closed_at'] <= $end) {
                        $done++;
                    }
                }
            }

            // The days still to come have a scope (what is planned) but no
            // "done" yet.
            $days[] = ['day' => $day->format('Y-m-d'), 'scope' => $scope, 'done' => $day > $today ? null : $done];
        }

        return $days;
    }

    private static function days(string $from, string $to): float
    {
        return round(max(0, strtotime($to) - strtotime($from)) / 86400, 1);
    }

    /** @param non-empty-list<float> $sorted */
    private static function percentile(array $sorted, int $percent): float
    {
        $position = ($percent / 100) * (count($sorted) - 1);
        $below = (int) floor($position);
        $above = (int) ceil($position);

        return $sorted[$below] + ($sorted[$above] - $sorted[$below]) * ($position - $below);
    }
}
