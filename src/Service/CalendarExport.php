<?php

namespace CantoTrack\Service;

use CantoTrack\Core\Config;
use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * A person's dates as an iCalendar feed (RFC 5545) that their calendar
 * subscribes to — see the 0036 migration: the days their open tickets are
 * due, the releases coming in the projects they see, and the last days of
 * the sprints running there. All-day events, each with a link back.
 *
 * Read as the person (Auth::actAs), so it holds nothing they could not see
 * signed in.
 */
final class CalendarExport
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** A new private address's secret, and the hash kept of it. @return array{0: string, 1: string} */
    public static function newSecret(): array
    {
        $secret = bin2hex(random_bytes(24));

        return [$secret, hash('sha256', $secret)];
    }

    /**
     * The person's dates, as events.
     *
     * @return list<array{uid: string, day: string, summary: string, description: string, url: string}>
     */
    public function events(int $userId): array
    {
        $base = rtrim((string) Config::get('app.base_url'), '/');
        $events = [];

        $due = $this->db->prepare(
            "SELECT t.id, t.number, t.title, t.due_on, p.code, s.name AS status_name
             FROM tickets t JOIN projects p ON p.id = t.project_id JOIN statuses s ON s.id = t.status_id
             WHERE t.assignee_id = :user AND t.due_on IS NOT NULL AND s.category <> 'done'"
            . \CantoTrack\Core\Access::sql('t.project_id') . ' ORDER BY t.due_on'
        );
        $due->execute(['user' => $userId]);

        foreach ($due->fetchAll() as $ticket) {
            $key = $ticket['code'] . '-' . $ticket['number'];
            $events[] = [
                'uid' => 'ticket-' . $ticket['id'] . '-due',
                'day' => (string) $ticket['due_on'],
                'summary' => __('{key} due: {title}', ['key' => $key, 'title' => $ticket['title']]),
                'description' => __('Status: {status}', ['status' => __((string) $ticket['status_name'])]),
                'url' => $base . '/tickets/' . $ticket['id'],
            ];
        }

        $releases = $this->db->query(
            'SELECT r.id, r.name, r.release_on, p.code FROM releases r JOIN projects p ON p.id = r.project_id
             WHERE r.released_at IS NULL AND r.release_on IS NOT NULL AND p.is_archived = 0'
            . \CantoTrack\Core\Access::sql('r.project_id')
        );

        foreach ($releases === false ? [] : $releases->fetchAll() as $release) {
            $events[] = [
                'uid' => 'release-' . $release['id'],
                'day' => (string) $release['release_on'],
                'summary' => __('{code} {name} goes out', ['code' => $release['code'], 'name' => $release['name']]),
                'description' => '',
                'url' => $base . '/releases/' . $release['id'],
            ];
        }

        $sprints = $this->db->query(
            "SELECT sp.id, sp.name, sp.ends_on, COALESCE(p.code, b.name) AS code
             FROM sprints sp JOIN boards b ON b.id = sp.board_id LEFT JOIN projects p ON p.id = b.project_id
             WHERE sp.state <> 'closed' AND sp.ends_on IS NOT NULL AND (p.id IS NULL OR p.is_archived = 0)"
            . \CantoTrack\Core\Access::boardSql('sp.board_id')
        );

        foreach ($sprints === false ? [] : $sprints->fetchAll() as $sprint) {
            $events[] = [
                'uid' => 'sprint-' . $sprint['id'] . '-end',
                'day' => (string) $sprint['ends_on'],
                'summary' => __('{name} ends', ['name' => $sprint['name']]),
                'description' => (string) $sprint['code'],
                'url' => $base . '/sprints/' . $sprint['id'],
            ];
        }

        return $events;
    }

    /**
     * The events written as a calendar: CRLF line ends, text escaped, lines
     * folded at 75 bytes, each event a whole day.
     *
     * @param list<array{uid: string, day: string, summary: string, description: string, url: string}> $events
     */
    public static function ics(array $events, string $name, string $host, string $stamp): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//CantoTrack//Dates//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::text($name),
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
        ];

        foreach ($events as $event) {
            $day = str_replace('-', '', substr($event['day'], 0, 10));
            $next = date('Ymd', (int) strtotime($event['day'] . ' +1 day'));

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $event['uid'] . '@' . $host;
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART;VALUE=DATE:' . $day;
            $lines[] = 'DTEND;VALUE=DATE:' . $next;
            $lines[] = 'SUMMARY:' . self::text($event['summary']);
            if ($event['description'] !== '') {
                $lines[] = 'DESCRIPTION:' . self::text($event['description']);
            }
            $lines[] = 'URL:' . $event['url'];
            $lines[] = 'TRANSP:TRANSPARENT';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /** Text as a calendar reads it: backslashes, semicolons, commas and line breaks escaped. */
    private static function text(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $text);
    }

    /** A line longer than 75 bytes goes on in the next, after a space — never inside a character. */
    private static function fold(string $line): string
    {
        $out = '';
        $width = 0;

        foreach (mb_str_split($line) as $char) {
            $bytes = strlen($char);
            if ($width + $bytes > 75) {
                $out .= "\r\n ";
                $width = 1;
            }
            $out .= $char;
            $width += $bytes;
        }

        return $out;
    }
}
