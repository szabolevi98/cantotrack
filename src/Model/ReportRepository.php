<?php

namespace CantoTrack\Model;

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
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
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
                    w.minutes, w.billable, w.note
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
             JOIN users u ON u.id = w.user_id';

    /** @return array{0: string, 1: array<string, mixed>} */
    private function conditions(array $filters): array
    {
        $where = ['w.work_date BETWEEN :from AND :to'];
        $parameters = ['from' => $filters['from'], 'to' => $filters['to']];

        foreach (['project_id' => 'p.id', 'client_id' => 'p.client_id', 'user_id' => 'w.user_id'] as $key => $column) {
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
