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
    private const HEIGHT = 240;
    private const PAD = ['top' => 16, 'right' => 16, 'bottom' => 30, 'left' => 36];

    /**
     * @param array{unit: string, days: list<array{day: string, remaining: ?int, ideal: float}>, total: int} $burndown
     */
    public static function burndown(array $burndown): string
    {
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

        $inner = self::WIDTH - self::PAD['left'] - self::PAD['right'];
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

    /** @return array{0: \Closure(int|float): string, 1: \Closure(int|float): float} */
    private static function scales(int $count, int $top): array
    {
        $inner = self::WIDTH - self::PAD['left'] - self::PAD['right'];
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
                self::WIDTH - self::PAD['right'],
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
            self::WIDTH,
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
