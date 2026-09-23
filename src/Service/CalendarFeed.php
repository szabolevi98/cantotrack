<?php

namespace CantoTrack\Service;

use CantoTrack\Core\Config;
use CantoTrack\Core\Logger;
use CantoTrack\Core\OutboundUrl;
use CantoTrack\Core\ValidationError;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Somebody's own calendar, read from the private iCal address Google
 * Calendar, Outlook and the rest give out — so the week's meetings can be
 * offered as entries on the timesheet's calendar, one click from logged.
 *
 * Read only, and never stored beyond a ten-minute copy under var/cache: the
 * address is as good as a password to the calendar, and the events are the
 * person's own business. Timed events only; a day-long one is not hours.
 *
 * The reading is the part of iCalendar (RFC 5545) that calendars actually
 * write: folded lines, UTC and named time zones, events repeating daily or
 * weekly (with an interval, a count or an end, and the days of the week),
 * the dates taken out of a series and the ones moved, and cancelled ones.
 */
class CalendarFeed
{
    private const CACHE_SECONDS = 600;
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** Outlook writes Windows' names for the zones; the ones people here use. */
    private const WINDOWS_ZONES = [
        'Central Europe Standard Time' => 'Europe/Budapest',
        'Central European Standard Time' => 'Europe/Warsaw',
        'W. Europe Standard Time' => 'Europe/Berlin',
        'Romance Standard Time' => 'Europe/Paris',
        'GMT Standard Time' => 'Europe/London',
        'UTC' => 'UTC',
        'Eastern Standard Time' => 'America/New_York',
        'Pacific Standard Time' => 'America/Los_Angeles',
    ];

    /**
     * The address as it will be fetched: webcal:// is https:// under
     * another name, and it has to be somewhere public.
     *
     * @throws ValidationError
     */
    public static function address(string $given): string
    {
        $given = trim($given);

        if (str_starts_with(strtolower($given), 'webcal://')) {
            $given = 'https://' . substr($given, 9);
        }

        OutboundUrl::check($given);

        return $given;
    }

    /**
     * The timed events of a span of days, in the application's time zone:
     * a day, a start and an end in minutes from midnight, and the summary.
     *
     * @return list<array{day: string, start: int, end: int, summary: string}>
     */
    public function events(int $userId, string $address, string $from, string $to): array
    {
        $text = $this->fetch($userId, $address);

        return $text === null ? [] : self::parse($text, $from, $to);
    }

    /** The calendar's text: a fresh copy, or the one from the last ten minutes. */
    private function fetch(int $userId, string $address): ?string
    {
        $dir = dirname(__DIR__, 2) . '/var/cache/calendars';
        $file = $dir . '/' . $userId . '-' . substr(hash('sha256', $address), 0, 16) . '.ics';

        if (is_file($file) && filemtime($file) > time() - self::CACHE_SECONDS) {
            return (string) file_get_contents($file);
        }

        try {
            $target = OutboundUrl::check($address);
        } catch (ValidationError $e) {
            Logger::error('A calendar address was refused: ' . $e->getMessage());

            return is_file($file) ? (string) file_get_contents($file) : null;
        }

        $handle = curl_init($address);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_MAXFILESIZE => self::MAX_BYTES,
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . (str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'])],
            CURLOPT_HTTPHEADER => ['Accept: text/calendar', 'User-Agent: CantoTrack-Calendar/1'],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if (!is_string($body) || $status !== 200 || !str_contains($body, 'BEGIN:VCALENDAR')) {
            // The last good copy, however old, rather than nothing.
            return is_file($file) ? (string) file_get_contents($file) : null;
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        @file_put_contents($file, $body);

        return $body;
    }

    /**
     * @return list<array{day: string, start: int, end: int, summary: string}>
     */
    public static function parse(string $text, string $from, string $to): array
    {
        $zone = new DateTimeZone((string) Config::get('app.timezone', 'Europe/Budapest'));
        $rangeStart = new DateTimeImmutable($from . ' 00:00', $zone);
        $rangeEnd = new DateTimeImmutable($to . ' 23:59:59', $zone);

        $events = self::blocks(self::unfold($text));
        $moved = [];

        // A moved or changed occurrence is an event of its own with the
        // series' UID and the original start; that occurrence of the series
        // is left out, and the event itself takes its place.
        foreach ($events as $event) {
            if (isset($event['RECURRENCE-ID'])) {
                $original = self::time($event['RECURRENCE-ID'], $zone);
                if ($original !== null) {
                    $moved[($event['UID'][0]['value'] ?? '') . '@' . $original->getTimestamp()] = true;
                }
            }
        }

        $out = [];

        foreach ($events as $event) {
            if (strtoupper($event['STATUS'][0]['value'] ?? '') === 'CANCELLED' || !isset($event['DTSTART'])) {
                continue;
            }

            // A day-long event (a date, no time) is not hours.
            if (strtoupper($event['DTSTART'][0]['params']['VALUE'] ?? '') === 'DATE' || strlen($event['DTSTART'][0]['value']) === 8) {
                continue;
            }

            $start = self::time($event['DTSTART'], $zone);
            if ($start === null) {
                continue;
            }

            $end = isset($event['DTEND']) ? self::time($event['DTEND'], $zone) : null;
            $length = $end !== null ? $end->getTimestamp() - $start->getTimestamp() : self::duration($event['DURATION'][0]['value'] ?? 'PT1H');
            $length = max(0, $length);

            $excluded = [];
            foreach ($event['EXDATE'] ?? [] as $exdate) {
                foreach (explode(',', $exdate['value']) as $value) {
                    $when = self::time([['value' => $value, 'params' => $exdate['params']]], $zone);
                    if ($when !== null) {
                        $excluded[$when->getTimestamp()] = true;
                    }
                }
            }

            $uid = $event['UID'][0]['value'] ?? '';
            $starts = isset($event['RRULE']) && !isset($event['RECURRENCE-ID'])
                ? self::occurrences($start, $event['RRULE'][0]['value'], $rangeEnd)
                : [$start];

            foreach ($starts as $occurrence) {
                $stamp = $occurrence->getTimestamp();

                if (isset($excluded[$stamp]) || (!isset($event['RECURRENCE-ID']) && isset($moved[$uid . '@' . $stamp]) && $starts !== [$start])) {
                    continue;
                }

                $occurrenceEnd = $occurrence->modify('+' . $length . ' seconds');
                if ($occurrenceEnd <= $rangeStart || $occurrence > $rangeEnd) {
                    continue;
                }

                $local = $occurrence->setTimezone($zone);
                $localEnd = $occurrenceEnd->setTimezone($zone);
                $startMinute = (int) $local->format('G') * 60 + (int) $local->format('i');
                // Ends on another day: kept to the day it starts on.
                $endMinute = $localEnd->format('Y-m-d') === $local->format('Y-m-d')
                    ? (int) $localEnd->format('G') * 60 + (int) $localEnd->format('i')
                    : 24 * 60;

                $out[] = [
                    'day' => $local->format('Y-m-d'),
                    'start' => $startMinute,
                    'end' => max($startMinute + 15, $endMinute),
                    'summary' => mb_substr(self::text($event['SUMMARY'][0]['value'] ?? ''), 0, 200),
                ];
            }
        }

        usort($out, static fn(array $a, array $b): int => [$a['day'], $a['start']] <=> [$b['day'], $b['start']]);

        return $out;
    }

    /** @return list<string> the content lines, folded ones joined */
    private static function unfold(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace("/\n[ \t]/", '', $text);

        return array_values(array_filter(explode("\n", $text), static fn(string $line): bool => $line !== ''));
    }

    /**
     * The VEVENTs, each a map of property name => its values with their
     * parameters.
     *
     * @param list<string> $lines
     * @return list<array<string, list<array{value: string, params: array<string, string>}>>>
     */
    private static function blocks(array $lines): array
    {
        $events = [];
        $current = null;
        $depth = 0;

        foreach ($lines as $line) {
            $upper = strtoupper($line);

            if ($upper === 'BEGIN:VEVENT') {
                $current = [];
                $depth = 0;
                continue;
            }

            if ($current === null) {
                continue;
            }

            // An alarm inside an event has properties of its own.
            if (str_starts_with($upper, 'BEGIN:')) {
                $depth++;
                continue;
            }

            if (str_starts_with($upper, 'END:')) {
                if ($upper === 'END:VEVENT' && $depth === 0) {
                    $events[] = $current;
                    $current = null;
                } else {
                    $depth = max(0, $depth - 1);
                }
                continue;
            }

            if ($depth > 0 || !str_contains($line, ':')) {
                continue;
            }

            [$head, $value] = explode(':', $line, 2);
            $parts = explode(';', $head);
            $name = strtoupper(array_shift($parts));
            $params = [];

            foreach ($parts as $part) {
                if (str_contains($part, '=')) {
                    [$key, $param] = explode('=', $part, 2);
                    $params[strtoupper($key)] = trim($param, '"');
                }
            }

            $current[$name][] = ['value' => $value, 'params' => $params];
        }

        return $events;
    }

    /** @param list<array{value: string, params: array<string, string>}> $property */
    private static function time(array $property, DateTimeZone $default): ?DateTimeImmutable
    {
        $value = trim($property[0]['value'] ?? '');
        $tzid = $property[0]['params']['TZID'] ?? null;

        if (preg_match('/^(\d{8})T(\d{6})(Z?)$/', $value, $m) !== 1) {
            return null;
        }

        try {
            $zone = $m[3] === 'Z' ? new DateTimeZone('UTC') : ($tzid !== null ? self::zone($tzid, $default) : $default);
            $time = DateTimeImmutable::createFromFormat('!Ymd His', $m[1] . ' ' . $m[2], $zone);
        } catch (\Exception) {
            return null;
        }

        return $time === false ? null : $time;
    }

    private static function zone(string $tzid, DateTimeZone $default): DateTimeZone
    {
        $tzid = self::WINDOWS_ZONES[$tzid] ?? $tzid;

        try {
            return new DateTimeZone($tzid);
        } catch (\Exception) {
            return $default;
        }
    }

    /** "PT1H30M", "P1D" → seconds */
    private static function duration(string $value): int
    {
        if (preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', trim($value), $m) !== 1) {
            return 3600;
        }

        return (int) ($m[1] ?? 0) * 604800 + (int) ($m[2] ?? 0) * 86400 + (int) ($m[3] ?? 0) * 3600 + (int) ($m[4] ?? 0) * 60 + (int) ($m[5] ?? 0);
    }

    /**
     * The starts of a repeating event up to the end of the range: daily or
     * weekly, with INTERVAL, COUNT, UNTIL and (weekly) BYDAY. Anything else
     * is the first occurrence only — better one meeting offered than a
     * wrong week of them.
     *
     * @return list<DateTimeImmutable>
     */
    private static function occurrences(DateTimeImmutable $start, string $rule, DateTimeImmutable $until): array
    {
        $parts = [];
        foreach (explode(';', $rule) as $pair) {
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
                $parts[strtoupper($key)] = strtoupper($value);
            }
        }

        $freq = $parts['FREQ'] ?? '';
        $interval = max(1, (int) ($parts['INTERVAL'] ?? 1));
        $count = isset($parts['COUNT']) ? (int) $parts['COUNT'] : null;
        $last = $until;

        if (isset($parts['UNTIL'])) {
            $end = self::time([['value' => strlen($parts['UNTIL']) === 8 ? $parts['UNTIL'] . 'T235959Z' : $parts['UNTIL'], 'params' => []]], new DateTimeZone('UTC'));
            if ($end !== null && $end < $last) {
                $last = $end;
            }
        }

        if (!in_array($freq, ['DAILY', 'WEEKLY'], true)) {
            return [$start];
        }

        $days = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
        $byDay = [];
        foreach (explode(',', $parts['BYDAY'] ?? '') as $day) {
            $day = substr($day, -2);
            if (isset($days[$day])) {
                $byDay[] = $days[$day];
            }
        }

        $out = [];
        $made = 0;

        if ($freq === 'DAILY') {
            for ($at = $start, $i = 0; $at <= $last && $i < 2000; $at = $at->modify('+' . $interval . ' days'), $i++) {
                if ($count !== null && $made >= $count) {
                    break;
                }
                $out[] = $at;
                $made++;
            }

            return $out;
        }

        // Weekly: each week of the series, the days it names (or the first
        // day's weekday), in order.
        $byDay = $byDay ?: [(int) $start->format('N')];
        sort($byDay);
        $weekStart = $start->modify('-' . ((int) $start->format('N') - 1) . ' days');

        for ($week = 0; $week < 1000; $week += $interval) {
            $monday = $weekStart->modify('+' . $week . ' weeks');

            foreach ($byDay as $weekday) {
                $at = $monday->modify('+' . ($weekday - 1) . ' days');

                if ($at < $start) {
                    continue;
                }

                if ($at > $last || ($count !== null && $made >= $count)) {
                    return $out;
                }

                $out[] = $at;
                $made++;
            }
        }

        return $out;
    }

    /** A TEXT value with its escapes undone. */
    private static function text(string $value): string
    {
        return trim(strtr($value, ['\\n' => ' ', '\\N' => ' ', '\\,' => ',', '\\;' => ';', '\\\\' => '\\']));
    }
}
