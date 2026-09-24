<?php

namespace CantoTrack\Model;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * The hours, added up: by project, by person, by client, by ticket or by day,
 * over any span of days — and the rows themselves, for an export.
 *
 * Every figure is summed from the worklogs when it is asked for, like
 * everything else here; a report never disagrees with the timesheets it is
 * made of.
 */
class ReportRepository
{
    /** What a report can be grouped by, and what each group is named by. */
    public const GROUPS = [
        'project' => ['p.id', "CONCAT(p.code, ' — ', p.name)"],
        'person' => ['u.id', 'u.name'],
        'client' => ['c.id', "COALESCE(c.name, '')"],
        'ticket' => ['t.id', "CONCAT(p.code, '-', t.number, ' ', t.title)"],
        'day' => ['w.work_date', 'w.work_date'],
        'type' => ['wt.id', "COALESCE(wt.name, '')"],
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * Each ticket's three moments, for the flow charts (see Flow): made,
     * first out of the to-do columns, finished. Top-level tickets only — a
     * subtask is a step of its parent's work, not a piece of work of its own.
     *
     * @return list<array{id: int, key: string, title: string, created_at: string, started_at: ?string, closed_at: ?string, resolution: ?string}>
     */
    public function flowTickets(?int $projectId, ?int $releaseId = null): array
    {
        $where = ['t.parent_id IS NULL'];
        $parameters = [];

        if ($projectId !== null) {
            $where[] = 't.project_id = :project';
            $parameters['project'] = $projectId;
        }

        if ($releaseId !== null) {
            $where[] = 't.release_id = :release';
            $parameters['release'] = $releaseId;
        }

        // Started: the first move into a column that is not a to-do one —
        // or, for a ticket made straight into one, when it was made.
        $statement = $this->db->prepare(
            'SELECT t.id, CONCAT(p.code, \'-\', t.number) AS `key`, t.title, t.created_at, t.closed_at, t.resolution,
                    COALESCE(
                        (SELECT MIN(e.created_at) FROM ticket_events e
                           JOIN statuses es ON es.project_id = t.project_id AND es.name = e.new_value
                          WHERE e.ticket_id = t.id AND e.kind = \'status\' AND es.category <> \'todo\'),
                        CASE WHEN s.category <> \'todo\' THEN t.created_at END
                    ) AS started_at
             FROM tickets t
             JOIN projects p ON p.id = t.project_id
             JOIN statuses s ON s.id = t.status_id
             WHERE ' . implode(' AND ', $where) . Access::sql('t.project_id') . '
             ORDER BY t.created_at'
        );
        $statement->execute($parameters);

        return array_values(array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'key' => (string) $row['key'],
            'title' => (string) $row['title'],
            'created_at' => (string) $row['created_at'],
            // Moved on before it was finished, whatever the history says.
            'started_at' => $row['started_at'] === null ? null
                : ($row['closed_at'] !== null && $row['started_at'] > $row['closed_at'] ? (string) $row['closed_at'] : (string) $row['started_at']),
            'closed_at' => $row['closed_at'] === null ? null : (string) $row['closed_at'],
            'resolution' => $row['resolution'] === null ? null : (string) $row['resolution'],
        ], $statement->fetchAll()));
    }

    /** @return list<array{key: mixed, label: string, minutes: int, billable: int, entries: int}> */
    public function summary(array $filters, string $group): array
    {
        [$key, $label] = self::GROUPS[$group] ?? self::GROUPS['project'];
        [$where, $parameters] = $this->conditions($filters);

        $statement = $this->db->prepare(
            'SELECT ' . $key . ' AS `key`, ' . $label . ' AS label,
                    SUM(w.minutes) AS minutes,
                    SUM(CASE WHEN w.billable = 1 THEN w.minutes ELSE 0 END) AS billable,
                    COUNT(*) AS entries
             ' . self::FROM . ' WHERE ' . $where . '
             GROUP BY ' . $key . ', label
             ORDER BY ' . ($group === 'day' ? 'w.work_date' : 'minutes DESC')
        );
        $statement->execute($parameters);

        return array_values(array_map(static fn(array $row): array => [
            'key' => $row['key'],
            'label' => (string) $row['label'],
            'minutes' => (int) $row['minutes'],
            'billable' => (int) $row['billable'],
            'entries' => (int) $row['entries'],
        ], $statement->fetchAll()));
    }

    /**
     * Projects down, people across: who put how long into what.
     *
     * @return array{projects: array<int, string>, people: array<int, string>, cells: array<int, array<int, int>>}
     */
    public function matrix(array $filters): array
    {
        [$where, $parameters] = $this->conditions($filters);

        $statement = $this->db->prepare(
            'SELECT p.id AS project_id, CONCAT(p.code, \' — \', p.name) AS project, u.id AS user_id, u.name AS person,
                    SUM(w.minutes) AS minutes
             ' . self::FROM . ' WHERE ' . $where . '
             GROUP BY p.id, project, u.id, person
             ORDER BY project, person'
        );
        $statement->execute($parameters);

        $matrix = ['projects' => [], 'people' => [], 'cells' => []];
        foreach ($statement->fetchAll() as $row) {
            $matrix['projects'][(int) $row['project_id']] = (string) $row['project'];
            $matrix['people'][(int) $row['user_id']] = (string) $row['person'];
            $matrix['cells'][(int) $row['project_id']][(int) $row['user_id']] = (int) $row['minutes'];
        }

        asort($matrix['people']);

        return $matrix;
    }

    /** Every entry the filters take in, oldest first — what an export holds. */
    public function rows(array $filters): array
    {
        [$where, $parameters] = $this->conditions($filters);

        $statement = $this->db->prepare(
            'SELECT w.work_date, u.name AS person, p.code AS project_code, p.name AS project_name,
                    c.name AS client, CONCAT(p.code, \'-\', t.number) AS ticket_key, t.title AS ticket_title,
                    w.minutes, w.billable, w.note, w.started_at, wt.name AS work_type
             ' . self::FROM . ' WHERE ' . $where . '
             ORDER BY w.work_date, u.name, w.id'
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    private const FROM = 'FROM worklogs w
             JOIN tickets t ON t.id = w.ticket_id
             JOIN projects p ON p.id = t.project_id
             LEFT JOIN clients c ON c.id = p.client_id
             JOIN users u ON u.id = w.user_id
             LEFT JOIN work_types wt ON wt.id = w.work_type_id';

    /** @return array{0: string, 1: array<string, mixed>} */
    private function conditions(array $filters): array
    {
        $where = ['w.work_date BETWEEN :from AND :to'];

        // Hours in a project somebody cannot see are not in their reports.
        $visible = Access::where('p.id');
        if ($visible !== null) {
            $where[] = $visible;
        }
        $parameters = ['from' => $filters['from'], 'to' => $filters['to']];

        foreach (['project_id' => 'p.id', 'client_id' => 'p.client_id', 'user_id' => 'w.user_id', 'work_type_id' => 'w.work_type_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = $column . ' = :' . $key;
                $parameters[$key] = (int) $filters[$key];
            }
        }

        if (($filters['billable'] ?? '') === 'yes') {
            $where[] = 'w.billable = 1';
        } elseif (($filters['billable'] ?? '') === 'no') {
            $where[] = 'w.billable = 0';
        }

        if (!empty($filters['project_ids']) && is_array($filters['project_ids'])) {
            $where[] = 'p.id IN (' . implode(',', array_map('intval', $filters['project_ids'])) . ')';
        }

        return [implode(' AND ', $where), $parameters];
    }
}
