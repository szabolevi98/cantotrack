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
     * "90", "1:30". Anything it cannot read is nothing, which the caller reports
     * rather than guessing at.
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

        // "1h 30m", "1h", "30m", and the decimal "1.5h" people type out of habit.
        if (preg_match('/^(?:(\d+(?:[.,]\d+)?)\s*h)?\s*(?:(\d+)\s*m)?$/', $text, $m) === 1
            && ($m[1] ?? '') . ($m[2] ?? '') !== '') {
            $hours = (float) str_replace(',', '.', $m[1] ?? '0');
            $minutes = (int) ($m[2] ?? 0);

            return (int) round($hours * 60) + $minutes;
        }

        // A bare number is minutes. It is the one reading that surprises people
        // both ways, so the field says so next to it.
        if (preg_match('/^\d+$/', $text) === 1) {
            return (int) $text;
        }

        return null;
    }

    /** "22 Sep 2026", which is unambiguous in a way that any all-numeric date is not. */
    public static function day(string $date): string
    {
        $time = strtotime($date);

        return $time === false ? $date : date('j M Y', $time);
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
