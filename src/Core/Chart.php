<?php

namespace CantoTrack\Core;

/**
 * The two charts the application draws: a sprint's burndown and a project's
 * velocity.
 *
 * Drawn here as SVG, on the server, rather than by a charting library in the
 * browser: two charts do not earn a hundred kilobytes of script, the page
 * shows them without any script at all, and the colours come from the same
 * custom properties as the rest of the page. Each carries a title and a
 * description for screen readers, and the page beside it has the numbers as a
 * table — a chart is a picture of the data, not a replacement for it.
 */
class Chart
{
    private const WIDTH = 640;

    /**
     * How wide the chart being drawn is, in its own units. A chart across a
     * whole page is drawn wider, and one in half of it narrower, so that its
     * labels come out the same size on the screen as every other chart's.
     */
    private static int $width = self::WIDTH;
    private const HEIGHT = 240;
    private const PAD = ['top' => 16, 'right' => 16, 'bottom' => 30, 'left' => 36];

    /**
     * @param array{unit: string, days: list<array{day: string, remaining: ?int, ideal: float}>, total: int} $burndown
     */
    public static function burndown(array $burndown): string
    {
        self::$width = self::WIDTH;
        $days = $burndown['days'];
        $count = max(1, count($days) - 1);
        $top = max(1, $burndown['total']);

        [$x, $y] = self::scales($count, $top);

        $ideal = [];
        $actual = [];
        foreach ($days as $i => $day) {
            $ideal[] = self::point($x($i), $y($day['ideal']));

            if ($day['remaining'] !== null) {
                $actual[] = self::point($x($i), $y($day['remaining']));
            }
        }

        $labels = '';
        foreach ($days as $i => $day) {
            // A label on the first and last day and every few in between, so
            // two weeks of dates do not overlap.
            if ($i === 0 || $i === count($days) - 1 || $i % max(1, (int) ceil(count($days) / 6)) === 0) {
                $labels .= sprintf(
                    '<text x="%s" y="%d" class="chart__axis" text-anchor="middle">%s</text>',
                    $x($i),
                    self::HEIGHT - 10,
                    self::e(date('M j', (int) strtotime($day['day'])))
                );
            }
        }

        $unit = $burndown['unit'] === 'points' ? __('points') : __('tickets');
        $last = end($days);
        $left = $last === false ? 0 : (int) ($last['remaining'] ?? 0);

        return self::frame(
            __('Burndown'),
            __('{remaining} of {total} {unit} left.', ['remaining' => $left, 'total' => $burndown['total'], 'unit' => $unit]),
            self::grid($top, $y)
            . '<polyline class="chart__ideal" fill="none" points="' . implode(' ', $ideal) . '"/>'
            . ($actual === [] ? '' : '<polyline class="chart__line" fill="none" points="' . implode(' ', $actual) . '"/>')
            . $labels
        );
    }

    /**
     * Committed against completed, per closed sprint, oldest first.
     *
     * @param list<array> $sprints
     */
    public static function velocity(array $sprints, bool $byPoints): string
    {
        self::$width = self::WIDTH;
        $values = [];
        foreach ($sprints as $sprint) {
            $values[] = [
                'name' => (string) $sprint['name'],
                'committed' => (int) ($byPoints ? $sprint['committed_points'] : $sprint['committed_count']),
                'completed' => (int) ($byPoints ? $sprint['completed_points'] : $sprint['completed_count']),
            ];
        }

        $top = max(1, ...array_map(static fn(array $v): int => max($v['committed'], $v['completed']), $values ?: [['committed' => 1, 'completed' => 1]]));
        [, $y] = self::scales(1, $top);

        $inner = self::$width - self::PAD['left'] - self::PAD['right'];
        $slot = $inner / max(1, count($values));
        $bar = min(28, $slot / 3);
        $bars = '';

        foreach ($values as $i => $value) {
            $centre = self::PAD['left'] + $slot * ($i + 0.5);

            foreach (['committed' => -1, 'completed' => 1] as $kind => $side) {
                $height = self::HEIGHT - self::PAD['bottom'] - $y($value[$kind]);
                $bars .= sprintf(
                    '<rect class="chart__bar chart__bar--%s" x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="3"><title>%s</title></rect>',
                    $kind,
                    $centre + ($side < 0 ? -$bar - 2 : 2),
                    $y($value[$kind]),
                    $bar,
                    max(0, $height),
                    self::e($value['name'] . ': ' . $value[$kind])
                );
            }

            $bars .= sprintf(
                '<text x="%.1f" y="%d" class="chart__axis" text-anchor="middle">%s</text>',
                $centre,
                self::HEIGHT - 10,
                self::e(mb_strimwidth($value['name'], 0, 16, '…'))
            );
        }

        return self::frame(__('Velocity'), __('Committed and completed, sprint by sprint.'), self::grid($top, $y) . $bars);
    }

    /**
     * How much was waiting, under way and done on each day, stacked: the
     * done band at the bottom growing, the under-way band a steady width
     * when the work flows and a widening one when it piles up.
     *
     * @param list<array{day: string, todo: int, in_progress: int, done: int}> $days
     */
    public static function flow(array $days): string
    {
        self::$width = 1000;
        $count = max(1, count($days) - 1);
        $top = max(1, ...array_map(static fn(array $d): int => $d['todo'] + $d['in_progress'] + $d['done'], $days ?: [['todo' => 1, 'in_progress' => 0, 'done' => 0]]));
        [$x, $y] = self::scales($count, $top);

        $bands = '';
        $below = array_fill(0, count($days), 0);

        foreach (['done', 'in_progress', 'todo'] as $band) {
            $upper = [];
            $lower = [];
            foreach ($days as $i => $day) {
                $upper[] = self::point($x($i), $y($below[$i] + $day[$band]));
                $lower[] = self::point($x($i), $y($below[$i]));
                $below[$i] += $day[$band];
            }
            $bands .= '<polygon class="chart__band chart__band--' . $band . '" points="' . implode(' ', array_merge($upper, array_reverse($lower))) . '"/>';
        }

        $last = end($days);

        return self::frame(
            __('Cumulative flow'),
            $last === false ? '' : __('{todo} waiting, {doing} under way, {done} done.', ['todo' => $last['todo'], 'doing' => $last['in_progress'], 'done' => $last['done']]),
            self::grid($top, $y) . $bands . self::dayLabels(array_column($days, 'day'), $x)
        );
    }

    /**
     * Each finished ticket as a dot — when it was finished, and how many days
     * it took — with the line 85 of every 100 were done under.
     *
     * @param list<array{key: string, title: string, closed: string, cycle: float}> $times
     */
    public static function cycleTimes(array $times, string $from, string $to, float $p85): string
    {
        self::$width = 480;
        $span = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400));
        $top = max(1, (int) ceil(max([$p85, ...array_column($times, 'cycle')])));
        [$x, $y] = self::scales($span, $top);

        $dots = '';
        foreach ($times as $time) {
            $dots .= sprintf(
                '<circle class="chart__dot" cx="%s" cy="%.1f" r="4"><title>%s</title></circle>',
                $x((strtotime($time['closed']) - strtotime($from)) / 86400),
                $y($time['cycle']),
                self::e($time['key'] . ' ' . $time['title'] . ' — ' . __('{days} days', ['days' => $time['cycle']]))
            );
        }

        $line = sprintf(
            '<line class="chart__ideal" x1="%d" x2="%d" y1="%.1f" y2="%.1f"/><text class="chart__axis" x="%d" y="%.1f" text-anchor="end">85%%</text>',
            self::PAD['left'],
            self::$width - self::PAD['right'],
            $y($p85),
            $y($p85),
            self::$width - self::PAD['right'],
            $y($p85) - 4
        );

        $labels = [];
        for ($i = 0; $i <= $span; $i++) {
            $labels[] = date('Y-m-d', (int) strtotime($from . ' +' . $i . ' days'));
        }

        return self::frame(
            __('Cycle time'),
            __('85 of every 100 tickets were done within {days} days.', ['days' => $p85]),
            self::grid($top, $y) . $line . $dots . self::dayLabels($labels, $x)
        );
    }

    /**
     * Written down against finished, week by week: more coming in than going
     * out, week after week, is a backlog growing.
     *
     * @param list<array{week: string, created: int, resolved: int}> $weeks
     */
    public static function createdResolved(array $weeks): string
    {
        self::$width = 480;
        $top = max(1, ...array_map(static fn(array $w): int => max($w['created'], $w['resolved']), $weeks ?: [['created' => 1, 'resolved' => 1]]));
        [, $y] = self::scales(1, $top);

        $inner = self::$width - self::PAD['left'] - self::PAD['right'];
        $slot = $inner / max(1, count($weeks));
        $bar = min(18, $slot / 3);
        $bars = '';

        foreach ($weeks as $i => $week) {
            $centre = self::PAD['left'] + $slot * ($i + 0.5);

            foreach (['created' => -1, 'resolved' => 1] as $kind => $side) {
                $bars .= sprintf(
                    '<rect class="chart__bar chart__bar--%s" x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="2"><title>%s</title></rect>',
                    $kind,
                    $centre + ($side < 0 ? -$bar - 1 : 1),
                    $y($week[$kind]),
                    $bar,
                    max(0, self::HEIGHT - self::PAD['bottom'] - $y($week[$kind])),
                    self::e(date('M j', (int) strtotime($week['week'])) . ': ' . $week[$kind])
                );
            }

            if ($i % max(1, (int) ceil(count($weeks) / 8)) === 0) {
                $bars .= sprintf('<text x="%.1f" y="%d" class="chart__axis" text-anchor="middle">%s</text>', $centre, self::HEIGHT - 10, self::e(date('M j', (int) strtotime($week['week']))));
            }
        }

        return self::frame(__('Created and resolved'), __('Tickets written down and finished, week by week.'), self::grid($top, $y) . $bars);
    }

    /**
     * A release's scope and what of it is done, day by day.
     *
     * @param list<array{day: string, scope: int, done: ?int}> $days
     */
    public static function burnup(array $days): string
    {
        self::$width = self::WIDTH;
        $count = max(1, count($days) - 1);
        $top = max(1, ...array_map(static fn(array $d): int => $d['scope'], $days ?: [['scope' => 1]]));
        [$x, $y] = self::scales($count, $top);

        $scope = [];
        $done = [];
        foreach ($days as $i => $day) {
            $scope[] = self::point($x($i), $y($day['scope']));
            if ($day['done'] !== null) {
                $done[] = self::point($x($i), $y($day['done']));
            }
        }

        $last = array_values(array_filter($days, static fn(array $d): bool => $d['done'] !== null));
        $now = end($last);

        return self::frame(
            __('Burnup'),
            $now === false ? '' : __('{done} of {total} tickets done.', ['done' => $now['done'], 'total' => $now['scope']]),
            self::grid($top, $y)
            . '<polyline class="chart__ideal" fill="none" points="' . implode(' ', $scope) . '"/>'
            . ($done === [] ? '' : '<polyline class="chart__line" fill="none" points="' . implode(' ', $done) . '"/>')
            . self::dayLabels(array_column($days, 'day'), $x)
        );
    }

    /**
     * Dates under a chart: the first, the last, and a few between.
     *
     * @param list<string> $days
     * @param \Closure(int|float): string $x
     */
    private static function dayLabels(array $days, \Closure $x): string
    {
        $labels = '';
        $every = max(1, (int) ceil(count($days) / 6));

        foreach ($days as $i => $day) {
            if ($i === 0 || $i === count($days) - 1 || ($i % $every === 0 && count($days) - 1 - $i >= $every * 0.75)) {
                $labels .= sprintf(
                    '<text x="%s" y="%d" class="chart__axis" text-anchor="%s">%s</text>',
                    $x($i),
                    self::HEIGHT - 10,
                    $i === 0 ? 'start' : ($i === count($days) - 1 ? 'end' : 'middle'),
                    self::e(date('M j', (int) strtotime($day)))
                );
            }
        }

        return $labels;
    }

    /** @return array{0: \Closure(int|float): string, 1: \Closure(int|float): float} */
    private static function scales(int $count, int $top): array
    {
        $inner = self::$width - self::PAD['left'] - self::PAD['right'];
        $tall = self::HEIGHT - self::PAD['top'] - self::PAD['bottom'];

        return [
            static fn(int|float $i): string => number_format(self::PAD['left'] + $inner * $i / $count, 1, '.', ''),
            static fn(int|float $value): float => round(self::PAD['top'] + $tall * (1 - $value / $top), 1),
        ];
    }

    /** @param \Closure(int|float): float $y */
    private static function grid(int $top, \Closure $y): string
    {
        $lines = '';
        $step = max(1, (int) ceil($top / 4));
        $values = range(0, $top, $step);

        // The top of the scale is labelled too: it is the number the chart
        // starts from, and the one people look for.
        if (end($values) !== $top) {
            $values[] = $top;
        }

        foreach ($values as $value) {
            $lines .= sprintf(
                '<line class="chart__grid" x1="%d" x2="%d" y1="%.1f" y2="%.1f"/><text class="chart__axis" x="%d" y="%.1f" text-anchor="end">%d</text>',
                self::PAD['left'],
                self::$width - self::PAD['right'],
                $y($value),
                $y($value),
                self::PAD['left'] - 6,
                $y($value) + 4,
                $value
            );
        }

        return $lines;
    }

    private static function frame(string $title, string $description, string $content): string
    {
        $id = 'chart-' . bin2hex(random_bytes(4));

        return sprintf(
            '<svg class="chart" viewBox="0 0 %d %d" role="img" aria-labelledby="%s-t %s-d" preserveAspectRatio="xMidYMid meet">'
            . '<title id="%s-t">%s</title><desc id="%s-d">%s</desc>%s</svg>',
            self::$width,
            self::HEIGHT,
            $id,
            $id,
            $id,
            self::e($title),
            $id,
            self::e($description),
            $content
        );
    }

    private static function point(string $x, float $y): string
    {
        return $x . ',' . number_format($y, 1, '.', '');
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
