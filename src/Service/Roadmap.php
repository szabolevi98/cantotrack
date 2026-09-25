<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Model\BoardRepository;
use CantoTrack\Model\ReleaseRepository;
use CantoTrack\Model\SprintRepository;
use DateTimeImmutable;
use PDO;

/**
 * The roadmap: a project's epics as bars across the months, its releases as
 * the days they go out, and its sprints as the stretches they cover.
 *
 * The placing is plain arithmetic over a window of whole months, kept apart
 * from the reading so it can be tested on its own: a bar is a left edge and a
 * width, both as a share of the window, and a bar that runs past either end
 * of the window says so rather than being quietly cut.
 */
final class Roadmap
{
    /** How many months the roadmap shows at once. */
    public const MONTHS = 6;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * The months shown: from the first of the given month ("2026-09"), or
     * from two months back — what was just done, and what is coming — for
     * six months.
     *
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable}
     */
    public static function window(string $given, DateTimeImmutable $today): array
    {
        $from = preg_match('/^(\d{4})-(\d{2})$/', $given, $m) === 1 && checkdate((int) $m[2], 1, (int) $m[1])
            ? new DateTimeImmutable($m[1] . '-' . $m[2] . '-01')
            : $today->modify('first day of this month')->modify('-2 months')->setTime(0, 0);

        return ['from' => $from, 'to' => $from->modify('+' . self::MONTHS . ' months')->modify('-1 day')];
    }

    /**
     * The months as columns: each one's first day, and where it starts and
     * how wide it is, in percent of the window.
     *
     * @return list<array{month: string, left: float, width: float}>
     */
    public static function months(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $out = [];

        for ($month = $from; $month <= $to; $month = $month->modify('+1 month')) {
            $placed = self::place($month->format('Y-m-d'), $month->modify('last day of this month')->format('Y-m-d'), $from, $to);

            if ($placed !== null) {
                $out[] = ['month' => $month->format('Y-m-d'), 'left' => $placed['left'], 'width' => $placed['width']];
            }
        }

        return $out;
    }

    /**
     * Where a stretch of days sits in the window — both ends counted, so a
     * one-day stretch still has a width — or null when it is outside it.
     *
     * @return array{left: float, width: float, before: bool, after: bool}|null
     */
    public static function place(?string $start, ?string $end, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        if ($start === null && $end === null) {
            return null;
        }

        $start ??= $end;
        $end ??= $start;

        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');

        if ($end < $first || $start > $last) {
            return null;
        }

        $days = self::days($first, $last) + 1;
        $left = max(0, self::days($first, $start));
        $right = min($days, self::days($first, $end) + 1);

        return [
            'left' => round($left / $days * 100, 3),
            'width' => round(max(0.4, ($right - $left) / $days * 100), 3),
            'before' => $start < $first,
            'after' => $end > $last,
        ];
    }

    /** Days from one Y-m-d to another, negative when the second is earlier. */
    private static function days(string $from, string $to): int
    {
        $diff = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to));

        return (int) $diff->days * ($diff->invert === 1 ? -1 : 1);
    }

    /** Where one day sits in the window, in percent, or null outside it. */
    public static function at(string $day, DateTimeImmutable $from, DateTimeImmutable $to): ?float
    {
        $placed = self::place($day, $day, $from, $to);

        return $placed === null ? null : round($placed['left'] + $placed['width'] / 2, 3);
    }

    /**
     * Everything the roadmap draws for some projects.
     *
     * @param list<array<string, mixed>> $projects
     * @return list<array{project: array, epics: list<array>, releases: list<array>, sprints: list<array>}>
     */
    public function lanes(array $projects, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $releases = new ReleaseRepository($this->db);
        $sprints = new SprintRepository($this->db);
        $boards = new BoardRepository($this->db);
        $lanes = [];

        foreach ($projects as $project) {
            $id = (int) $project['id'];
            $epics = [];

            foreach ($this->epics($id) as $epic) {
                $start = $epic['starts_on'] ?? $epic['first_day'];
                $end = $epic['ends_on'] ?? $epic['last_day'];
                $epic['guessed'] = $epic['starts_on'] === null || $epic['ends_on'] === null;
                $epic['start'] = $start;
                $epic['end'] = $end;
                $epic['bar'] = self::place($start, $end, $from, $to);
                $epic['share'] = (int) $epic['ticket_count'] > 0 ? round((int) $epic['done_count'] / (int) $epic['ticket_count'] * 100) : 0;
                $epics[] = $epic;
            }

            $marks = [];
            foreach ($releases->forProject($id) as $release) {
                $day = $release['released_at'] !== null ? substr((string) $release['released_at'], 0, 10) : $release['release_on'];

                if ($day !== null && ($at = self::at((string) $day, $from, $to)) !== null) {
                    $release['at'] = $at;
                    $release['day'] = $day;
                    $marks[] = $release;
                }
            }

            // The sprints of the project's own board, and of the shared boards
            // it is on: its work is planned in both.
            $stretches = [];
            $planned = $sprints->forBoard((int) $boards->ownOf($id)['id']);
            foreach ($boards->sharedWith($id) as $board) {
                $planned = array_merge($planned, $sprints->forBoard((int) $board['id']));
            }

            foreach ($planned as $sprint) {
                $placed = self::place($sprint['starts_on'] ?? null, $sprint['ends_on'] ?? null, $from, $to);

                if ($placed !== null) {
                    $sprint['bar'] = $placed;
                    $stretches[] = $sprint;
                }
            }

            $lanes[] = ['project' => $project, 'epics' => $epics, 'releases' => $marks, 'sprints' => $stretches];
        }

        return $lanes;
    }

    /**
     * A project's epics with what the roadmap needs: their own days, and the
     * days their tickets span for the ones that have none — from the first
     * written down to the last due or finished, or on to today while any is
     * still open.
     *
     * @return list<array<string, mixed>>
     */
    private function epics(int $projectId): array
    {
        $statement = $this->db->prepare(
            'SELECT e.*,
                    COUNT(t.id) AS ticket_count,
                    COALESCE(SUM(s.category = \'done\'), 0) AS done_count,
                    MIN(DATE(t.created_at)) AS first_day,
                    CASE WHEN SUM(s.category <> \'done\') > 0
                         THEN GREATEST(CURDATE(), COALESCE(MAX(t.due_on), CURDATE()))
                         ELSE GREATEST(COALESCE(MAX(DATE(t.closed_at)), MIN(DATE(t.created_at))), COALESCE(MAX(t.due_on), \'1970-01-01\'))
                    END AS last_day
             FROM epics e
             LEFT JOIN tickets t ON t.epic_id = e.id AND t.parent_id IS NULL
             LEFT JOIN statuses s ON s.id = t.status_id
             WHERE e.project_id = :project
             GROUP BY e.id
             ORDER BY COALESCE(e.starts_on, MIN(DATE(t.created_at)), \'9999-12-31\'), e.title'
        );
        $statement->execute(['project' => $projectId]);

        return array_values($statement->fetchAll());
    }
}
