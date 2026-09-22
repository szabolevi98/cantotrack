<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * Tickets: the things people pick up, move across the board and log time
 * against.
 *
 * Every read here joins in the names a page needs — the project's code, the
 * epic's title, who it is assigned to — because the alternative is a list page
 * that runs one query for the list and three more for every row on it.
 */
class TicketRepository
{
    /** The board's columns, in the order work moves through them. */
    public const STATUSES = ['backlog', 'todo', 'in_progress', 'review', 'done'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::get();
    }

    /** What every list needs beside the ticket's own columns. */
    private const SELECT = 'SELECT t.*,
                   p.code AS project_code,
                   p.name AS project_name,
                   e.title AS epic_title,
                   a.name AS assignee_name,
                   r.name AS reporter_name,
                   COALESCE(w.logged_minutes, 0) AS logged_minutes
            FROM tickets t
            JOIN projects p ON p.id = t.project_id
            LEFT JOIN epics e ON e.id = t.epic_id
            LEFT JOIN users a ON a.id = t.assignee_id
            LEFT JOIN users r ON r.id = t.reporter_id
            LEFT JOIN (
                SELECT ticket_id, SUM(minutes) AS logged_minutes
                FROM worklogs
                GROUP BY ticket_id
            ) w ON w.ticket_id = t.id';

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(self::SELECT . ' WHERE t.id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /**
     * A filtered list. Every filter is optional, and the ones that are given are
     * built into the statement as placeholders — never as text, so a project
     * name with a quote in it stays a project name.
     */
    public function search(array $filters = [], int $limit = 200): array
    {
        $where = [];
        $parameters = [];

        if (!empty($filters['project_id'])) {
            $where[] = 't.project_id = :project_id';
            $parameters['project_id'] = (int) $filters['project_id'];
        }

        if (!empty($filters['epic_id'])) {
            $where[] = 't.epic_id = :epic_id';
            $parameters['epic_id'] = (int) $filters['epic_id'];
        }

        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 't.status = :status';
            $parameters['status'] = $filters['status'];
        }

        if (!empty($filters['assignee_id'])) {
            $where[] = 't.assignee_id = :assignee_id';
            $parameters['assignee_id'] = (int) $filters['assignee_id'];
        }

        // "Anything not finished" is what people mean by "open", and it is the
        // view a tracker spends most of its life showing.
        if (!empty($filters['open_only'])) {
            $where[] = 't.status <> \'done\'';
        }

        if (!empty($filters['q'])) {
            $where[] = '(t.title LIKE :q OR t.description LIKE :q2 OR CONCAT(p.code, \'-\', t.number) LIKE :q3)';
            // The same value three times under three names: with real prepared
            // statements a placeholder may appear only once in a statement.
            $parameters['q'] = '%' . $filters['q'] . '%';
            $parameters['q2'] = '%' . $filters['q'] . '%';
            $parameters['q3'] = '%' . $filters['q'] . '%';
        }

        $sql = self::SELECT
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            // Unfinished work first, then by priority, then the newest — which
            // is the order somebody scanning the list is looking for.
            . ' ORDER BY t.status = \'done\', FIELD(t.priority, \'urgent\', \'high\', \'normal\', \'low\'), t.id DESC'
            . ' LIMIT ' . max(1, min($limit, 500));

        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /** A project's tickets grouped into the board's columns. */
    public function board(int $projectId): array
    {
        $board = array_fill_keys(self::STATUSES, []);

        foreach ($this->search(['project_id' => $projectId], 500) as $ticket) {
            $board[$ticket['status']][] = $ticket;
        }

        return $board;
    }

    /**
     * Creates a ticket and gives it its number.
     *
     * Both happen in one transaction: the counter is raised and the row written
     * together, so a failure cannot leave a gap in the numbering and two people
     * at once cannot be given the same number.
     */
    public function create(array $data): int
    {
        $projects = new ProjectRepository();

        $this->db->beginTransaction();

        try {
            $number = $projects->takeNextNumber((int) $data['project_id']);

            $statement = $this->db->prepare(
                'INSERT INTO tickets
                    (project_id, number, epic_id, title, description, status, priority,
                     assignee_id, reporter_id, estimate_minutes)
                 VALUES
                    (:project_id, :number, :epic_id, :title, :description, :status, :priority,
                     :assignee_id, :reporter_id, :estimate_minutes)'
            );

            $statement->execute([
                'project_id' => (int) $data['project_id'],
                'number' => $number,
                'epic_id' => $data['epic_id'] ?: null,
                'title' => trim((string) $data['title']),
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'status' => in_array($data['status'] ?? '', self::STATUSES, true) ? $data['status'] : 'backlog',
                'priority' => in_array($data['priority'] ?? '', self::PRIORITIES, true) ? $data['priority'] : 'normal',
                'assignee_id' => $data['assignee_id'] ?: null,
                'reporter_id' => $data['reporter_id'] ?: null,
                'estimate_minutes' => $data['estimate_minutes'] ?: null,
            ]);

            $id = (int) $this->db->lastInsertId();
            $this->db->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    public function update(int $id, array $data): void
    {
        $statement = $this->db->prepare(
            'UPDATE tickets SET
                epic_id = :epic_id,
                title = :title,
                description = :description,
                priority = :priority,
                assignee_id = :assignee_id,
                estimate_minutes = :estimate_minutes
             WHERE id = :id'
        );

        $statement->execute([
            'epic_id' => $data['epic_id'] ?: null,
            'title' => trim((string) $data['title']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'priority' => in_array($data['priority'] ?? '', self::PRIORITIES, true) ? $data['priority'] : 'normal',
            'assignee_id' => $data['assignee_id'] ?: null,
            'estimate_minutes' => $data['estimate_minutes'] ?: null,
            'id' => $id,
        ]);
    }

    /**
     * Moves a ticket to another column, and keeps `closed_at` honest: it is set
     * when the ticket reaches done and cleared when it leaves again, so
     * "finished last week" cannot include something that was reopened.
     */
    public function changeStatus(int $id, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }

        $statement = $this->db->prepare(
            'UPDATE tickets
             SET status = :status,
                 closed_at = CASE WHEN :status2 = \'done\' THEN COALESCE(closed_at, NOW()) ELSE NULL END
             WHERE id = :id'
        );

        $statement->execute(['status' => $status, 'status2' => $status, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM tickets WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /** What one person has open, newest first — the dashboard's main list. */
    public function openFor(int $userId, int $limit = 25): array
    {
        return $this->search(['assignee_id' => $userId, 'open_only' => true], $limit);
    }

    /** How many tickets are in each status for a project, for the column headings. */
    public function countsByStatus(int $projectId): array
    {
        $statement = $this->db->prepare(
            'SELECT status, COUNT(*) AS count FROM tickets WHERE project_id = :project GROUP BY status'
        );

        $statement->execute(['project' => $projectId]);

        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($statement->fetchAll() as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }
}
