<?php

namespace CantoTrack\Model;

use CantoTrack\Core\Access;
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

    /** Why a finished ticket is finished, and what that is called. */
    public const RESOLUTIONS = [
        'done' => 'Done',
        'wont_do' => 'Won’t do',
        'duplicate' => 'Duplicate',
        'cannot_reproduce' => 'Cannot reproduce',
    ];

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
                   p.billable_default AS project_billable,
                   s.name AS status_name,
                   s.category AS status_category,
                   s.colour AS status_colour,
                   e.title AS epic_title,
                   a.name AS assignee_name,
                   r.name AS reporter_name,
                   (SELECT COALESCE(SUM(w.minutes), 0) FROM worklogs w WHERE w.ticket_id = t.id) AS logged_minutes,
                   (SELECT GROUP_CONCAT(l.name ORDER BY l.name SEPARATOR \'\n\')
                      FROM ticket_labels tl JOIN labels l ON l.id = tl.label_id
                     WHERE tl.ticket_id = t.id) AS label_names,
                   sp.name AS sprint_name,
                   sp.state AS sprint_state,
                   rl.name AS release_name,
                   rl.released_at AS release_released_at,
                   pt.number AS parent_number,
                   pt.title AS parent_title,
                   (SELECT COUNT(*) FROM tickets c WHERE c.parent_id = t.id) AS subtask_count,
                   (SELECT COUNT(*) FROM tickets c JOIN statuses cs ON cs.id = c.status_id
                     WHERE c.parent_id = t.id AND cs.category = \'done\') AS subtasks_done,
                   (SELECT COALESCE(SUM(w.minutes), 0) FROM worklogs w JOIN tickets c ON c.id = w.ticket_id
                     WHERE c.parent_id = t.id) AS subtask_minutes,
                   (SELECT SUM(c.estimate_minutes) FROM tickets c WHERE c.parent_id = t.id) AS subtask_estimate,
                   (SELECT COUNT(*) FROM ticket_links bl
                      JOIN tickets bt ON bt.id = bl.source_id
                      JOIN statuses bs ON bs.id = bt.status_id
                     WHERE bl.target_id = t.id AND bl.kind = \'blocks\' AND bs.category <> \'done\') AS blocked_by
            FROM tickets t
            JOIN projects p ON p.id = t.project_id
            JOIN statuses s ON s.id = t.status_id
            LEFT JOIN sprints sp ON sp.id = t.sprint_id
            LEFT JOIN tickets pt ON pt.id = t.parent_id
            LEFT JOIN releases rl ON rl.id = t.release_id
            LEFT JOIN epics e ON e.id = t.epic_id
            LEFT JOIN users a ON a.id = t.assignee_id
            LEFT JOIN users r ON r.id = t.reporter_id';

    /** Unfinished first, then by priority, then the newest. */
    private const ORDER = ' ORDER BY s.category = \'done\', FIELD(t.priority, \'urgent\', \'high\', \'normal\', \'low\'), t.id DESC';

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(self::SELECT . ' WHERE t.id = :id' . Access::sql('t.project_id'));
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** A ticket by the name people know it by, "CT-14". */
    public function findByKey(string $key): ?array
    {
        if (preg_match(self::KEY_PATTERN, strtoupper(trim($key)), $m) !== 1) {
            return null;
        }

        $statement = $this->db->prepare(self::SELECT . ' WHERE p.code = :code AND t.number = :number' . Access::sql('t.project_id'));
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

        // A query's own order, if it gave one — only ever columns it chose
        // from its own list — with the newest last for ties.
        $order = !empty($filters['query_order']) ? ' ORDER BY ' . $filters['query_order'] . ', t.id DESC' : self::ORDER;

        $statement = $this->db->prepare(
            self::SELECT
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . $order
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
             JOIN statuses s ON s.id = t.status_id
             LEFT JOIN sprints sp ON sp.id = t.sprint_id
             LEFT JOIN releases rl ON rl.id = t.release_id
             LEFT JOIN epics e ON e.id = t.epic_id
             LEFT JOIN users a ON a.id = t.assignee_id'
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

        // Only what the signed-in person may see, whatever else is asked for.
        $visible = Access::where('t.project_id');
        if ($visible !== null) {
            $where[] = $visible;
        }

        if (!empty($filters['project_id'])) {
            $where[] = 't.project_id = :project_id';
            $parameters['project_id'] = (int) $filters['project_id'];
        }

        if (!empty($filters['epic_id'])) {
            $where[] = 't.epic_id = :epic_id';
            $parameters['epic_id'] = (int) $filters['epic_id'];
        }

        // A query from the query language, compiled already: its condition
        // and the values bound to it (see TicketQuery).
        if (!empty($filters['query_where'])) {
            $where[] = (string) $filters['query_where'];
            $parameters += (array) ($filters['query_params'] ?? []);
        }

        if (!empty($filters['release_id'])) {
            $where[] = 't.release_id = :release_id';
            $parameters['release_id'] = (int) $filters['release_id'];
        }

        if (!empty($filters['parent_id'])) {
            $where[] = 't.parent_id = :parent_id';
            $parameters['parent_id'] = (int) $filters['parent_id'];
        }

        // Only the tickets themselves, without the steps they are broken into.
        if (!empty($filters['top_level'])) {
            $where[] = 't.parent_id IS NULL';
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

        if (!empty($filters['sprint_id'])) {
            $where[] = 't.sprint_id = :sprint_id';
            $parameters['sprint_id'] = (int) $filters['sprint_id'];
        }

        if (!empty($filters['backlog_only'])) {
            $where[] = 't.sprint_id IS NULL';
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
                    (project_id, number, type, epic_id, parent_id, release_id, title, description, status_id, priority,
                     assignee_id, reporter_id, estimate_minutes, due_on, story_points, `rank`, closed_at, resolution)
                 VALUES
                    (:project_id, :number, :type, :epic_id, :parent_id, :release_id, :title, :description, :status_id, :priority,
                     :assignee_id, :reporter_id, :estimate_minutes, :due_on, :story_points, :rank, :closed_at, :resolution)'
            );

            $statement->execute([
                'project_id' => (int) $data['project_id'],
                'number' => $number,
                'rank' => (int) $last->fetchColumn() + 1024,
                'type' => in_array($data['type'] ?? '', self::TYPES, true) ? $data['type'] : 'task',
                'due_on' => ($data['due_on'] ?? null) ?: null,
                'story_points' => $data['story_points'] ?? null,
                'epic_id' => $data['epic_id'] ?: null,
                'parent_id' => ($data['parent_id'] ?? null) ?: null,
                'release_id' => ($data['release_id'] ?? null) ?: null,
                'title' => trim((string) $data['title']),
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'status_id' => (int) $data['status_id'],
                'priority' => in_array($data['priority'] ?? '', self::PRIORITIES, true) ? $data['priority'] : 'normal',
                'assignee_id' => $data['assignee_id'] ?: null,
                'reporter_id' => $data['reporter_id'] ?: null,
                'estimate_minutes' => $data['estimate_minutes'] ?: null,
                // A ticket created straight into a done column was finished now.
                'closed_at' => ($data['status_category'] ?? '') === 'done' ? date('Y-m-d H:i:s') : null,
                'resolution' => ($data['status_category'] ?? '') === 'done' ? 'done' : null,
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
                parent_id = :parent_id,
                release_id = :release_id,
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
            'parent_id' => ($data['parent_id'] ?? null) ?: null,
            'release_id' => ($data['release_id'] ?? null) ?: null,
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
    public function changeStatus(int $id, int $statusId, string $category, ?string $resolution = null): void
    {
        $statement = $this->db->prepare(
            'UPDATE tickets
             SET status_id = :status,
                 closed_at = CASE WHEN :category = \'done\' THEN COALESCE(closed_at, NOW()) ELSE NULL END,
                 resolution = :resolution
             WHERE id = :id'
        );

        $statement->execute([
            'status' => $statusId,
            'category' => $category,
            'resolution' => $category === 'done' ? ($resolution ?? 'done') : null,
            'id' => $id,
        ]);
    }

    /** Why a finished ticket is finished — see the 0031 migration. */
    public function setResolution(int $id, string $resolution): void
    {
        $this->db->prepare('UPDATE tickets SET resolution = :resolution WHERE id = :id AND closed_at IS NOT NULL')
            ->execute(['resolution' => $resolution, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM tickets WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /** How much is left, as somebody just said — or null for "the estimate less what was logged". */
    public function setRemaining(int $id, ?int $minutes): void
    {
        $this->db->prepare('UPDATE tickets SET remaining_minutes = :minutes WHERE id = :id')
            ->execute(['minutes' => $minutes, 'id' => $id]);
    }

    /**
     * What is left on a ticket: what was last said, or else the estimate less
     * the hours logged, or nothing when there was never an estimate.
     */
    public static function remaining(array $ticket): ?int
    {
        if ($ticket['remaining_minutes'] !== null) {
            return (int) $ticket['remaining_minutes'];
        }

        if (empty($ticket['estimate_minutes'])) {
            return null;
        }

        return max(0, (int) $ticket['estimate_minutes'] - (int) $ticket['logged_minutes']);
    }

    /**
     * A ticket's subtasks, in the order they were made — the order the steps
     * were thought of, which is usually the order they are done in.
     *
     * @return list<array<string, mixed>>
     */
    public function subtasks(int $parentId): array
    {
        $statement = $this->db->prepare(self::SELECT . ' WHERE t.parent_id = :parent' . Access::sql('t.project_id') . ' ORDER BY t.number');
        $statement->execute(['parent' => $parentId]);

        return array_values($statement->fetchAll());
    }

    /** @return list<int> the ids of a ticket's subtasks, whoever may see them */
    public function subtaskIds(int $parentId): array
    {
        $statement = $this->db->prepare('SELECT id FROM tickets WHERE parent_id = :parent');
        $statement->execute(['parent' => $parentId]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** A parent's epic, handed on to its subtasks: they are part of the same work. */
    public function setSubtasksEpic(int $parentId, ?int $epicId): void
    {
        $this->db->prepare('UPDATE tickets SET epic_id = :epic WHERE parent_id = :parent')
            ->execute(['epic' => $epicId, 'parent' => $parentId]);
    }

    /** And its release: the steps of a ticket ship with it. */
    public function setSubtasksRelease(int $parentId, ?int $releaseId): void
    {
        $this->db->prepare('UPDATE tickets SET release_id = :release WHERE parent_id = :parent')
            ->execute(['release' => $releaseId, 'parent' => $parentId]);
    }

    public function hasWorklogs(int $id): bool
    {
        $statement = $this->db->prepare('SELECT EXISTS (SELECT 1 FROM worklogs WHERE ticket_id = :id)');
        $statement->execute(['id' => $id]);

        return (bool) $statement->fetchColumn();
    }

    /**
     * The tickets somebody logged time on lately, the most recent first —
     * what they are most likely to log on again.
     *
     * @return list<array<string, mixed>>
     */
    public function recentlyLoggedBy(int $userId, int $limit = 6): array
    {
        $statement = $this->db->prepare(
            'SELECT w.ticket_id, MAX(w.work_date) AS last_day FROM worklogs w
             WHERE w.user_id = :user AND w.work_date >= CURDATE() - INTERVAL 21 DAY
             GROUP BY w.ticket_id ORDER BY last_day DESC, MAX(w.id) DESC LIMIT ' . max(1, $limit)
        );
        $statement->execute(['user' => $userId]);
        $found = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ticket = $this->find((int) $id);

            if ($ticket !== null) {
                $found[] = $ticket;
            }
        }

        return $found;
    }

    /** Whether somebody has starred a ticket. */
    public function isFavourite(int $ticketId, int $userId): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM ticket_favourites WHERE ticket_id = :t AND user_id = :u');
        $statement->execute(['t' => $ticketId, 'u' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    /** Stars a ticket, or takes the star off. Returns whether it is starred now. */
    public function toggleFavourite(int $ticketId, int $userId): bool
    {
        if ($this->isFavourite($ticketId, $userId)) {
            $this->db->prepare('DELETE FROM ticket_favourites WHERE ticket_id = :t AND user_id = :u')->execute(['t' => $ticketId, 'u' => $userId]);

            return false;
        }

        $this->db->prepare('INSERT INTO ticket_favourites (ticket_id, user_id) VALUES (:t, :u)')->execute(['t' => $ticketId, 'u' => $userId]);

        return true;
    }

    /**
     * Somebody's starred tickets that are not finished — the ones they can
     * see — most recently starred first.
     *
     * @return list<array<string, mixed>>
     */
    public function favouritesOf(int $userId, int $limit = 20): array
    {
        $statement = $this->db->prepare(
            self::SELECT . ' JOIN ticket_favourites f ON f.ticket_id = t.id AND f.user_id = :user
             WHERE s.category <> \'done\'' . Access::sql('t.project_id') . '
             ORDER BY f.created_at DESC LIMIT ' . max(1, $limit)
        );
        $statement->execute(['user' => $userId]);

        return array_values($statement->fetchAll());
    }

    /**
     * The tickets somebody logged time on in a span of days — last week's,
     * to give this week's grid the same rows.
     *
     * @return list<string> their keys
     */
    public function keysLoggedBy(int $userId, string $from, string $to): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT CONCAT(p.code, \'-\', t.number) FROM worklogs w
             JOIN tickets t ON t.id = w.ticket_id JOIN projects p ON p.id = t.project_id
             WHERE w.user_id = :user AND w.work_date BETWEEN :from AND :to' . Access::sql('t.project_id', 'w.user_id')
        );
        $statement->execute(['user' => $userId, 'from' => $from, 'to' => $to]);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** What one person has open, newest first — the dashboard's main list. */
    public function openFor(int $userId, int $limit = 25): array
    {
        return $this->search(['assignee_id' => $userId, 'open_only' => true], $limit);
    }

    /**
     * A project's planning view: every ticket that is not finished, and every
     * ticket of a sprint that is still open, keyed by sprint (0 for the
     * backlog) and in rank order — the order the board uses too. Subtasks
     * are not planned on their own: they go wherever their parent goes.
     *
     * @return array<int, list<array>>
     */
    public function planning(int $projectId): array
    {
        $statement = $this->db->prepare(
            self::SELECT . ' WHERE t.project_id = :project' . Access::sql('t.project_id') . '
               AND t.parent_id IS NULL
               AND (s.category <> \'done\' OR (t.sprint_id IS NOT NULL AND sp.state <> \'closed\'))
             ORDER BY t.`rank`, t.id'
        );
        $statement->execute(['project' => $projectId]);

        $grouped = [];
        foreach ($statement->fetchAll() as $ticket) {
            $grouped[(int) ($ticket['sprint_id'] ?? 0)][] = $ticket;
        }

        return $grouped;
    }
}
