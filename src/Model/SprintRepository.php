<?php

namespace CantoTrack\Model;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use PDO;

/** A board's sprints, and what is in them — see the 0047 migration. */
class SprintRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * Every sprint of a board, with what it holds now: open ones first, then
     * the closed, newest first. Counted in tickets, not in their subtasks —
     * those are steps of a ticket's work, and done when the ticket is.
     */
    public function forBoard(int $boardId): array
    {
        $statement = $this->db->prepare(
            'SELECT sp.*,
                    COUNT(t.id) AS ticket_count,
                    COALESCE(SUM(t.story_points), 0) AS points,
                    SUM(s.category = \'done\') AS done_count,
                    COALESCE(SUM(CASE WHEN s.category = \'done\' THEN t.story_points END), 0) AS done_points
             FROM sprints sp
             LEFT JOIN tickets t ON t.sprint_id = sp.id AND t.parent_id IS NULL
             LEFT JOIN statuses s ON s.id = t.status_id
             WHERE sp.board_id = :board
             GROUP BY sp.id
             ORDER BY FIELD(sp.state, \'active\', \'planned\', \'closed\'),
                      CASE WHEN sp.state = \'closed\' THEN sp.closed_at END DESC,
                      sp.starts_on, sp.id'
        );
        $statement->execute(['board' => $boardId]);

        return $statement->fetchAll();
    }

    /**
     * The sprints a ticket of a project can be put in: the ones not closed
     * on every board the project is on — its own board's first — each with
     * the name of its board.
     */
    public function openForProject(int $projectId): array
    {
        $statement = $this->db->prepare(
            'SELECT sp.*, b.name AS board_name, b.project_id AS board_project_id
             FROM sprints sp
             JOIN boards b ON b.id = sp.board_id
             JOIN board_projects bp ON bp.board_id = b.id AND bp.project_id = :project
             WHERE sp.state <> \'closed\'
             ORDER BY b.project_id IS NULL, b.name, FIELD(sp.state, \'active\', \'planned\'), sp.starts_on, sp.id'
        );
        $statement->execute(['project' => $projectId]);

        return $statement->fetchAll();
    }

    /** The sprints running on the boards a project is on, its own board's first. */
    public function activeForProject(int $projectId): array
    {
        return array_values(array_filter(
            $this->openForProject($projectId),
            static fn(array $s): bool => $s['state'] === 'active'
        ));
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT sp.*, b.name AS board_name, b.project_id AS board_project_id
             FROM sprints sp JOIN boards b ON b.id = sp.board_id
             WHERE sp.id = :id' . Access::boardSql('sp.board_id')
        );
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** The sprint running on a board, if one is. */
    public function active(int $boardId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM sprints WHERE board_id = :board AND state = \'active\' LIMIT 1');
        $statement->execute(['board' => $boardId]);

        return $statement->fetch() ?: null;
    }

    /** How many sprints the board has had, for naming the next one. */
    public function countForBoard(int $boardId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM sprints WHERE board_id = :board');
        $statement->execute(['board' => $boardId]);

        return (int) $statement->fetchColumn();
    }

    public function create(int $boardId, string $name, ?string $goal, ?string $startsOn, ?string $endsOn): int
    {
        $this->db->prepare(
            'INSERT INTO sprints (board_id, name, goal, starts_on, ends_on) VALUES (:board, :name, :goal, :starts, :ends)'
        )->execute([
            'board' => $boardId,
            'name' => $name,
            'goal' => $goal,
            'starts' => $startsOn,
            'ends' => $endsOn,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, ?string $goal, ?string $startsOn, ?string $endsOn): void
    {
        $this->db->prepare(
            'UPDATE sprints SET name = :name, goal = :goal, starts_on = :starts, ends_on = :ends WHERE id = :id'
        )->execute(['name' => $name, 'goal' => $goal, 'starts' => $startsOn, 'ends' => $endsOn, 'id' => $id]);
    }

    public function start(int $id, int $points, int $count): void
    {
        $this->db->prepare(
            'UPDATE sprints SET state = \'active\', started_at = NOW(),
                 starts_on = COALESCE(starts_on, CURDATE()),
                 committed_points = :points, committed_count = :count
             WHERE id = :id'
        )->execute(['points' => $points, 'count' => $count, 'id' => $id]);
    }

    public function close(int $id, int $points, int $count): void
    {
        $this->db->prepare(
            'UPDATE sprints SET state = \'closed\', closed_at = NOW(),
                 completed_points = :points, completed_count = :count
             WHERE id = :id'
        )->execute(['points' => $points, 'count' => $count, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM sprints WHERE id = :id')->execute(['id' => $id]);
    }

    /** The tickets in a sprint — without their subtasks — with whether each is finished. */
    public function tickets(int $sprintId): array
    {
        $statement = $this->db->prepare(
            'SELECT t.id, t.story_points, t.closed_at, s.category
             FROM tickets t JOIN statuses s ON s.id = t.status_id
             WHERE t.sprint_id = :sprint AND t.parent_id IS NULL'
        );
        $statement->execute(['sprint' => $sprintId]);

        return $statement->fetchAll();
    }

    /**
     * The tickets a closed sprint handed on unfinished — to the next sprint
     * or back to the backlog — found by the line their history got when it
     * closed. They are no longer in the sprint, but they were its work, and a
     * burndown without them ends at zero for a sprint that did not finish.
     */
    public function carriedOut(array $sprint): array
    {
        if ($sprint['closed_at'] === null) {
            return [];
        }

        $statement = $this->db->prepare(
            'SELECT DISTINCT t.id, t.story_points, NULL AS closed_at, \'todo\' AS category
             FROM ticket_events e JOIN tickets t ON t.id = e.ticket_id
             WHERE t.project_id IN (SELECT bp.project_id FROM board_projects bp WHERE bp.board_id = :board)
               AND t.parent_id IS NULL AND e.kind = \'sprint\' AND e.old_value = :name
               AND e.created_at >= :closed - INTERVAL 1 MINUTE
               AND (t.sprint_id IS NULL OR t.sprint_id <> :sprint)'
        );
        $statement->execute([
            'board' => $sprint['board_id'],
            'name' => $sprint['name'],
            'closed' => $sprint['closed_at'],
            'sprint' => $sprint['id'],
        ]);

        return $statement->fetchAll();
    }

    /** Sets the sprint of some tickets at once (null: back to the backlog). */
    public function assign(array $ticketIds, ?int $sprintId): void
    {
        if ($ticketIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $this->db->prepare('UPDATE tickets SET sprint_id = ? WHERE id IN (' . $placeholders . ')')
            ->execute(array_merge([$sprintId], array_map('intval', $ticketIds)));
    }

    /** The closed sprints of a board, oldest first, for the velocity chart. */
    public function closed(int $boardId, int $limit = 8): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM (
                 SELECT * FROM sprints WHERE board_id = :board AND state = \'closed\'
                 ORDER BY closed_at DESC LIMIT ' . max(1, $limit) . '
             ) recent ORDER BY closed_at'
        );
        $statement->execute(['board' => $boardId]);

        return $statement->fetchAll();
    }
}
