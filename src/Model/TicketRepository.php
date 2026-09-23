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

    /** A ticket name as people type it: CT-14. */
    public const KEY_PATTERN = '/^([A-Z][A-Z0-9]{1,9})-(\d+)$/';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * What every list needs beside the ticket's own columns.
     *
     * The hours are a correlated subquery rather than a join on a grouped
     * derived table. The derived table summed the whole worklog table for every
     * query — one ticket's page included — and only then kept the one row it
     * needed; the subquery asks the ticket index for exactly that ticket's rows.
     */
    private const SELECT = 'SELECT t.*,
                   p.code AS project_code,
                   p.name AS project_name,
                   p.is_archived AS project_archived,
                   e.title AS epic_title,
                   a.name AS assignee_name,
                   r.name AS reporter_name,
                   (SELECT COALESCE(SUM(w.minutes), 0) FROM worklogs w WHERE w.ticket_id = t.id) AS logged_minutes
            FROM tickets t
            JOIN projects p ON p.id = t.project_id
            LEFT JOIN epics e ON e.id = t.epic_id
            LEFT JOIN users a ON a.id = t.assignee_id
            LEFT JOIN users r ON r.id = t.reporter_id';

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(self::SELECT . ' WHERE t.id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** A ticket by the name people know it by, "CT-14". */
    public function findByKey(string $key): ?array
    {
        if (preg_match(self::KEY_PATTERN, strtoupper(trim($key)), $m) !== 1) {
            return null;
        }

        $statement = $this->db->prepare(self::SELECT . ' WHERE p.code = :code AND t.number = :number');
        $statement->execute(['code' => $m[1], 'number' => (int) $m[2]]);

        return $statement->fetch() ?: null;
    }

    /**
     * A filtered list, one page of it. Every filter is optional, and the ones
     * that are given are built into the statement as placeholders — never as
     * text, so a project name with a quote in it stays a project name.
     */
    public function search(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $parameters] = $this->conditions($filters);

        $sql = self::SELECT
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            // Unfinished work first, then by priority, then the newest — which
            // is the order somebody scanning the list is looking for.
            . ' ORDER BY t.status = \'done\', FIELD(t.priority, \'urgent\', \'high\', \'normal\', \'low\'), t.id DESC'
            . ' LIMIT ' . max(1, min($limit, 500)) . ' OFFSET ' . max(0, $offset);

        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /** How many tickets the same filters find, for the pages under a list. */
    public function count(array $filters = []): int
    {
        [$where, $parameters] = $this->conditions($filters);

        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM tickets t JOIN projects p ON p.id = t.project_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** @return array{0: list<string>, 1: array<string, mixed>} */
    private function conditions(array $filters): array
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
            $q = trim((string) $filters['q']);

            if (preg_match(self::KEY_PATTERN, strtoupper($q), $m) === 1) {
                // A ticket's own name finds that ticket and nothing that merely
                // mentions it.
                $where[] = '(p.code = :key_code AND t.number = :key_number)';
                $parameters['key_code'] = $m[1];
                $parameters['key_number'] = (int) $m[2];
            } else {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $where[] = '(t.title LIKE :q OR t.description LIKE :q2)';
                // The same value twice under two names: with real prepared
                // statements a placeholder may appear only once in a statement.
                $parameters['q'] = $like;
                $parameters['q2'] = $like;
            }
        }

        return [$where, $parameters];
    }

    /**
     * A project's tickets grouped into the board's columns.
     *
     * Every unfinished ticket, however many — a board that quietly stops at
     * some number is a board that hides work. The done column is the one that
     * only ever grows, so it shows the most recently finished and says how many
     * more there are.
     *
     * @return array{columns: array<string, array>, more_done: int}
     */
    public function board(int $projectId, int $doneShown = 20): array
    {
        $columns = array_fill_keys(self::STATUSES, []);

        $open = $this->db->prepare(
            self::SELECT . ' WHERE t.project_id = :project AND t.status <> \'done\'
             ORDER BY FIELD(t.priority, \'urgent\', \'high\', \'normal\', \'low\'), t.id DESC'
        );
        $open->execute(['project' => $projectId]);

        foreach ($open->fetchAll() as $ticket) {
            $columns[$ticket['status']][] = $ticket;
        }

        $done = $this->db->prepare(
            self::SELECT . ' WHERE t.project_id = :project AND t.status = \'done\'
             ORDER BY t.closed_at DESC, t.id DESC LIMIT ' . max(1, $doneShown)
        );
        $done->execute(['project' => $projectId]);
        $columns['done'] = $done->fetchAll();

        $total = $this->countsByStatus($projectId)['done'];

        return ['columns' => $columns, 'more_done' => max(0, (int) $total - count($columns['done']))];
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
        $projects = new ProjectRepository($this->db);

        $this->db->beginTransaction();

        try {
            $number = $projects->takeNextNumber((int) $data['project_id']);

            $statement = $this->db->prepare(
                'INSERT INTO tickets
                    (project_id, number, epic_id, title, description, status, priority,
                     assignee_id, reporter_id, estimate_minutes, closed_at)
                 VALUES
                    (:project_id, :number, :epic_id, :title, :description, :status, :priority,
                     :assignee_id, :reporter_id, :estimate_minutes, :closed_at)'
            );

            $status = in_array($data['status'] ?? '', self::STATUSES, true) ? $data['status'] : 'backlog';

            $statement->execute([
                'project_id' => (int) $data['project_id'],
                'number' => $number,
                'epic_id' => $data['epic_id'] ?: null,
                'title' => trim((string) $data['title']),
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'status' => $status,
                'priority' => in_array($data['priority'] ?? '', self::PRIORITIES, true) ? $data['priority'] : 'normal',
                'assignee_id' => $data['assignee_id'] ?: null,
                'reporter_id' => $data['reporter_id'] ?: null,
                'estimate_minutes' => $data['estimate_minutes'] ?: null,
                // A ticket created straight into done was finished now.
                'closed_at' => $status === 'done' ? date('Y-m-d H:i:s') : null,
            ]);

            $id = (int) $this->db->lastInsertId();
            $this->db->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    /**
     * Saves the edit form — but only over the version the form was drawn from.
     *
     * Returns false when somebody else saved in between: the version no longer
     * matches, no row is touched, and the caller says so instead of quietly
     * undoing the other person's work.
     */
    public function update(int $id, array $data, int $expectedVersion): bool
    {
        $statement = $this->db->prepare(
            'UPDATE tickets SET
                epic_id = :epic_id,
                title = :title,
                description = :description,
                priority = :priority,
                assignee_id = :assignee_id,
                estimate_minutes = :estimate_minutes,
                version = version + 1
             WHERE id = :id AND version = :version'
        );

        $statement->execute([
            'epic_id' => $data['epic_id'] ?: null,
            'title' => trim((string) $data['title']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'priority' => in_array($data['priority'] ?? '', self::PRIORITIES, true) ? $data['priority'] : 'normal',
            'assignee_id' => $data['assignee_id'] ?: null,
            'estimate_minutes' => $data['estimate_minutes'] ?: null,
            'id' => $id,
            'version' => $expectedVersion,
        ]);

        return $statement->rowCount() === 1;
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

    public function hasWorklogs(int $id): bool
    {
        $statement = $this->db->prepare('SELECT EXISTS (SELECT 1 FROM worklogs WHERE ticket_id = :id)');
        $statement->execute(['id' => $id]);

        return (bool) $statement->fetchColumn();
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
