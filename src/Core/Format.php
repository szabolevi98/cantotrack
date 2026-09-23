<?php

namespace CantoTrack\Core;

/**
 * How durations, dates and names are written out.
 *
 * Time is kept in minutes everywhere — in the database, in the models and in the
 * forms — and turned into something readable here and nowhere else. Storing
 * hours as a decimal looks friendlier until a day of six-minute entries adds up
 * to 7.999999 hours.
 */
class Format
{
    /** "7h 30m", "45m", "2h" — the short form a worklog row is written in. */
    public static function duration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0m';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return $rest . 'm';
        }

        return $rest === 0 ? $hours . 'h' : $hours . 'h ' . $rest . 'm';
    }

    /**
     * "7.5" — the decimal form, for the totals a timesheet column shows and for
     * anything that gets exported. One decimal place, because the smallest slice
     * anyone logs is a quarter of an hour.
     */
    public static function hours(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Parses what somebody typed into a duration field: "1h 30m", "1.5h", "90m",
     * "90", "1:30", "1h30", and — for estimates — days and weeks: "2d", "1w 2d".
     * Anything it cannot read is nothing, which the caller reports rather than
     * guessing at.
     *
     * A day is a working day (`work.hours_per_day`), and a week five of them:
     * "3d" of work is three days at a desk, not seventy-two hours.
     */
    public static function parseDuration(string $input): ?int
    {
        $text = strtolower(trim($input));
        if ($text === '') {
            return null;
        }

        // "1:30" — hours and minutes, the way a clock is written.
        if (preg_match('/^(\d+):([0-5]?\d)$/', $text, $m) === 1) {
            return ((int) $m[1] * 60) + (int) $m[2];
        }

        // "1h30" — minutes after hours without their m, as people type fast.
        if (preg_match('/^(\d+)\s*h\s*([0-5]?\d)$/', $text, $m) === 1) {
            return ((int) $m[1] * 60) + (int) $m[2];
        }

        // "1w 2d 3h 30m" in any combination, each part optional but in that
        // order, and the decimal "1.5h" or "0,5d" people type out of habit.
        $number = '(\d+(?:[.,]\d+)?)';
        if (preg_match('/^(?:' . $number . '\s*w)?\s*(?:' . $number . '\s*d)?\s*(?:' . $number . '\s*h)?\s*(?:(\d+)\s*m)?$/', $text, $m) === 1
            && implode('', array_slice($m, 1)) !== '') {
            $day = self::minutesPerDay();
            $value = static fn(int $i): float => (float) str_replace(',', '.', $m[$i] ?? '0');

            return (int) round($value(1) * 5 * $day + $value(2) * $day + $value(3) * 60) + (int) ($m[4] ?? 0);
        }

        // A bare number is minutes. It is the one reading that surprises people
        // both ways, so the field says so next to it.
        if (preg_match('/^\d+$/', $text) === 1) {
            return (int) $text;
        }

        return null;
    }

    /** A working day in minutes, from the configuration — eight hours when there is none. */
    public static function minutesPerDay(): int
    {
        try {
            return max(1, Config::int('work.hours_per_day', 8)) * 60;
        } catch (\RuntimeException) {
            return 480;
        }
    }

    /** "1.4 MB", "820 KB" — the size of a file, as a person reads it. */
    public static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' MB',
            $bytes >= 1024 => (int) round($bytes / 1024) . ' KB',
            default => $bytes . ' B',
        };
    }

    /** The months as Hungarian writes them short, with the full stop that marks the cut. */
    private const HU_MONTHS = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];

    /**
     * "22 Sep 2026", which is unambiguous in a way that any all-numeric date
     * is not — and "2026. szept. 22." to somebody reading in Hungarian, where
     * the year comes first.
     */
    public static function day(string $date): string
    {
        $time = strtotime($date);

        if ($time === false) {
            return $date;
        }

        if (I18n::locale() === 'hu') {
            return date('Y', $time) . '. ' . self::HU_MONTHS[(int) date('n', $time) - 1] . ' ' . date('j', $time) . '.';
        }

        return date('j M Y', $time);
    }

    /** "SL" — the two letters an avatar circle carries when there is no picture. */
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $letters .= mb_strtoupper(mb_substr($part, 0, 1));

            if (mb_strlen($letters) === 2) {
                break;
            }
        }

        return $letters === '' ? '?' : $letters;
    }
}
