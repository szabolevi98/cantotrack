<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * Tickets: the things people pick up, move across the board and log time
 * against.
 *
 * Every read here joins in the names a page needs — the project's code, the
 * epic's title, the column it is in, who it is assigned to — because the
 * alternative is a list page that runs one query for the list and three more
 * for every row on it.
 */
class TicketRepository
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const TYPES = ['task', 'bug', 'story'];

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
                   s.name AS status_name,
                   s.category AS status_category,
                   s.colour AS status_colour,
                   e.title AS epic_title,
                   a.name AS assignee_name,
                   r.name AS reporter_name,
                   (SELECT COALESCE(SUM(w.minutes), 0) FROM worklogs w WHERE w.ticket_id = t.id) AS logged_minutes,
                   (SELECT GROUP_CONCAT(l.name ORDER BY l.name SEPARATOR \'\n\')
                      FROM ticket_labels tl JOIN labels l ON l.id = tl.label_id
                     WHERE tl.ticket_id = t.id) AS label_names
            FROM tickets t
            JOIN projects p ON p.id = t.project_id
            JOIN statuses s ON s.id = t.status_id
            LEFT JOIN epics e ON e.id = t.epic_id
            LEFT JOIN users a ON a.id = t.assignee_id
            LEFT JOIN users r ON r.id = t.reporter_id';

    /** Unfinished first, then by priority, then the newest. */
    private const ORDER = ' ORDER BY s.category = \'done\', FIELD(t.priority, \'urgent\', \'high\', \'normal\', \'low\'), t.id DESC';

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

        $statement = $this->db->prepare(
            self::SELECT
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . self::ORDER
            . ' LIMIT ' . max(1, min($limit, 500)) . ' OFFSET ' . max(0, $offset)
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /** How many tickets the same filters find, for the pages under a list. */
    public function count(array $filters = []): int
    {
        [$where, $parameters] = $this->conditions($filters);

        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM tickets t
             JOIN projects p ON p.id = t.project_id
             JOIN statuses s ON s.id = t.status_id'
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

        // A status is either one column (an id) or a category, which is what
        // "every project's done column" needs: the columns differ by project.
        if (!empty($filters['status'])) {
            $status = (string) $filters['status'];

            if (ctype_digit($status)) {
                $where[] = 't.status_id = :status_id';
                $parameters['status_id'] = (int) $status;
            } elseif (in_array($status, StatusRepository::CATEGORIES, true)) {
                $where[] = 's.category = :category';
                $parameters['category'] = $status;
            }
        }

        if (!empty($filters['assignee_id'])) {
            $where[] = 't.assignee_id = :assignee_id';
            $parameters['assignee_id'] = (int) $filters['assignee_id'];
        }

        if (!empty($filters['unassigned'])) {
            $where[] = 't.assignee_id IS NULL';
        }

        if (!empty($filters['no_epic'])) {
            $where[] = 't.epic_id IS NULL';
        }

        if (!empty($filters['type']) && in_array($filters['type'], self::TYPES, true)) {
            $where[] = 't.type = :type';
            $parameters['type'] = $filters['type'];
        }

        if (!empty($filters['label'])) {
            $where[] = 'EXISTS (SELECT 1 FROM ticket_labels tl JOIN labels l ON l.id = tl.label_id
                                WHERE tl.ticket_id = t.id AND l.name = :label)';
            $parameters['label'] = (string) $filters['label'];
        }

        // Due: already late, or due within the week. Only open tickets can be
        // either — something finished is not late.
        if (($filters['due'] ?? '') === 'overdue') {
            $where[] = 't.due_on < CURDATE() AND s.category <> \'done\'';
        } elseif (($filters['due'] ?? '') === 'week') {
            $where[] = 't.due_on BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY AND s.category <> \'done\'';
        }

        // "Anything not finished" is what people mean by "open", and it is the
        // view a tracker spends most of its life showing.
        if (!empty($filters['open_only'])) {
            $where[] = 's.category <> \'done\'';
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
     * A project's tickets grouped into its columns.
     *
     * Every unfinished ticket, however many — a board that quietly stops at
     * some number is a board that hides work. A done column is the one that
     * only ever grows, so it shows the most recently finished and says how
     * many more there are.
     *
     * The open columns are in the order people arranged them (the rank); a
     * done column shows the most recently finished first.
     *
     * The filters narrow what is drawn — "only mine", one epic, one label —
     * without changing the columns or their counts, which are the project's.
     *
     * @param array<array> $statuses the project's columns, in order
     * @return array{columns: array<int, array>, more: array<int, int>}
     */
    public function board(int $projectId, array $statuses, array $filters = [], int $doneShown = 20): array
    {
        $columns = [];
        foreach ($statuses as $status) {
            $columns[(int) $status['id']] = [];
        }

        [$where, $parameters] = $this->conditions(['project_id' => $projectId] + $filters);
        $narrowed = implode(' AND ', $where);

        $open = $this->db->prepare(
            self::SELECT . ' WHERE ' . $narrowed . ' AND s.category <> \'done\' ORDER BY t.`rank`, t.id'
        );
        $open->execute($parameters);

        foreach ($open->fetchAll() as $ticket) {
            $columns[(int) $ticket['status_id']][] = $ticket;
        }

        $more = [];
        $done = $this->db->prepare(
            self::SELECT . ' WHERE ' . $narrowed . ' AND t.status_id = :done_status
             ORDER BY t.closed_at DESC, t.id DESC LIMIT ' . max(1, $doneShown)
        );

        foreach ($statuses as $status) {
            if ($status['category'] !== 'done') {
                continue;
            }

            $done->execute($parameters + ['done_status' => (int) $status['id']]);
            $columns[(int) $status['id']] = $done->fetchAll();
            $more[(int) $status['id']] = max(0, (int) $status['ticket_count'] - count($columns[(int) $status['id']]));
        }

        return ['columns' => $columns, 'more' => $more];
    }

    /**
     * Puts a ticket between two others in a column: the one above it and the
     * one below it, either of which may be missing (the top, the bottom, an
     * empty column).
     *
     * The rank is halfway between the neighbours'. When there is no room left
     * between them, the column is renumbered first — 1024 apart again, in the
     * same order — which is rare and costs one statement per card.
     */
    public function place(int $id, int $statusId, ?int $aboveId, ?int $belowId): void
    {
        $rank = $this->rankBetween($statusId, $aboveId, $belowId);

        if ($rank === null) {
            $this->renumber($statusId);
            $rank = (int) $this->rankBetween($statusId, $aboveId, $belowId);
        }

        $this->db->prepare('UPDATE tickets SET `rank` = :rank WHERE id = :id')->execute(['rank' => $rank, 'id' => $id]);
    }

    private function rankBetween(int $statusId, ?int $aboveId, ?int $belowId): ?int
    {
        $above = $aboveId === null ? null : $this->rankOf($aboveId);
        $below = $belowId === null ? null : $this->rankOf($belowId);

        if ($above === null && $below === null) {
            $last = $this->db->prepare('SELECT MAX(`rank`) FROM tickets WHERE status_id = :status');
            $last->execute(['status' => $statusId]);

            return (int) $last->fetchColumn() + 1024;
        }

        if ($above === null) {
            return (int) $below - 1024;
        }

        if ($below === null) {
            return $above + 1024;
        }

        return $below - $above >= 2 ? intdiv($above + $below, 2) : null;
    }

    private function rankOf(int $id): ?int
    {
        $statement = $this->db->prepare('SELECT `rank` FROM tickets WHERE id = :id');
        $statement->execute(['id' => $id]);
        $rank = $statement->fetchColumn();

        return $rank === false ? null : (int) $rank;
    }

    private function renumber(int $statusId): void
    {
        $statement = $this->db->prepare('SELECT id FROM tickets WHERE status_id = :status ORDER BY `rank`, id');
        $statement->execute(['status' => $statusId]);

        $update = $this->db->prepare('UPDATE tickets SET `rank` = :rank WHERE id = :id');
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $position => $ticketId) {
            $update->execute(['rank' => ($position + 1) * 1024, 'id' => $ticketId]);
        }
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

            // A new ticket goes to the bottom of its column: the order above
            // it is somebody's decision, and a newcomer does not jump it.
            $last = $this->db->prepare('SELECT COALESCE(MAX(`rank`), 0) FROM tickets WHERE project_id = :project');
            $last->execute(['project' => (int) $data['project_id']]);

            $statement = $this->db->prepare(
                'INSERT INTO tickets
                    (project_id, number, type, epic_id, title, description, status_id, priority,
                     assignee_id, reporter_id, estimate_minutes, due_on, story_points, `rank`, closed_at)
                 VALUES
                    (:project_id, :number, :type, :epic_id, :title, :description, :status_id, :priority,
                     :assignee_id, :reporter_id, :estimate_minutes, :due_on, :story_points, :rank, :closed_at)'
            );

            $statement->execute([
                'project_id' => (int) $data['project_id'],
                'number' => $number,
                'rank' => (int) $last->fetchColumn() + 1024,
                'type' => in_array($data['type'] ?? '', self::TYPES, true) ? $data['type'] : 'task',
                'due_on' => ($data['due_on'] ?? null) ?: null,
                'story_points' => $data['story_points'] ?? null,
                'epic_id' => $data['epic_id'] ?: null,
                'title' => trim((string) $data['title']),
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'status_id' => (int) $data['status_id'],
                'priority' => in_array($data['priority'] ?? '', self::PRIORITIES, true) ? $data['priority'] : 'normal',
                'assignee_id' => $data['assignee_id'] ?: null,
                'reporter_id' => $data['reporter_id'] ?: null,
                'estimate_minutes' => $data['estimate_minutes'] ?: null,
                // A ticket created straight into a done column was finished now.
                'closed_at' => ($data['status_category'] ?? '') === 'done' ? date('Y-m-d H:i:s') : null,
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
                type = :type,
                epic_id = :epic_id,
                title = :title,
                description = :description,
                priority = :priority,
                assignee_id = :assignee_id,
                estimate_minutes = :estimate_minutes,
                due_on = :due_on,
                story_points = :story_points,
                version = version + 1
             WHERE id = :id AND version = :version'
        );

        $statement->execute([
            'type' => in_array($data['type'] ?? '', self::TYPES, true) ? $data['type'] : 'task',
            'due_on' => ($data['due_on'] ?? null) ?: null,
            'story_points' => $data['story_points'] ?? null,
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
     * when the ticket reaches a done column and cleared when it leaves again,
     * so "finished last week" cannot include something that was reopened.
     */
    public function changeStatus(int $id, int $statusId, string $category): void
    {
        $statement = $this->db->prepare(
            'UPDATE tickets
             SET status_id = :status,
                 closed_at = CASE WHEN :category = \'done\' THEN COALESCE(closed_at, NOW()) ELSE NULL END
             WHERE id = :id'
        );

        $statement->execute(['status' => $statusId, 'category' => $category, 'id' => $id]);
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
}
