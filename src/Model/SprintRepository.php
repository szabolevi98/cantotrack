<?php

namespace CantoTrack\Model;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use PDO;

/** A project's sprints, and what is in them. */
class SprintRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** Every sprint of a project, with what it holds now: open ones first, then the closed, newest first. */
    public function forProject(int $projectId): array
    {
        $statement = $this->db->prepare(
            'SELECT sp.*,
                    COUNT(t.id) AS ticket_count,
                    COALESCE(SUM(t.story_points), 0) AS points,
                    SUM(s.category = \'done\') AS done_count,
                    COALESCE(SUM(CASE WHEN s.category = \'done\' THEN t.story_points END), 0) AS done_points
             FROM sprints sp
             LEFT JOIN tickets t ON t.sprint_id = sp.id
             LEFT JOIN statuses s ON s.id = t.status_id
             WHERE sp.project_id = :project
             GROUP BY sp.id
             ORDER BY FIELD(sp.state, \'active\', \'planned\', \'closed\'),
                      CASE WHEN sp.state = \'closed\' THEN sp.closed_at END DESC,
                      sp.starts_on, sp.id'
        );
        $statement->execute(['project' => $projectId]);

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM sprints WHERE id = :id' . Access::sql('project_id'));
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function active(int $projectId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM sprints WHERE project_id = :project AND state = \'active\' LIMIT 1');
        $statement->execute(['project' => $projectId]);

        return $statement->fetch() ?: null;
    }

    /** How many sprints the project has had, for naming the next one. */
    public function countForProject(int $projectId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM sprints WHERE project_id = :project');
        $statement->execute(['project' => $projectId]);

        return (int) $statement->fetchColumn();
    }

    public function create(int $projectId, string $name, ?string $goal, ?string $startsOn, ?string $endsOn): int
    {
        $this->db->prepare(
            'INSERT INTO sprints (project_id, name, goal, starts_on, ends_on) VALUES (:project, :name, :goal, :starts, :ends)'
        )->execute([
            'project' => $projectId,
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

    /** The tickets in a sprint, with whether each is finished. */
    public function tickets(int $sprintId): array
    {
        $statement = $this->db->prepare(
            'SELECT t.id, t.story_points, t.closed_at, s.category
             FROM tickets t JOIN statuses s ON s.id = t.status_id
             WHERE t.sprint_id = :sprint'
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
             WHERE t.project_id = :project AND e.kind = \'sprint\' AND e.old_value = :name
               AND e.created_at >= :closed - INTERVAL 1 MINUTE
               AND (t.sprint_id IS NULL OR t.sprint_id <> :sprint)'
        );
        $statement->execute([
            'project' => $sprint['project_id'],
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

    /** The closed sprints of a project, oldest first, for the velocity chart. */
    public function closed(int $projectId, int $limit = 8): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM (
                 SELECT * FROM sprints WHERE project_id = :project AND state = \'closed\'
                 ORDER BY closed_at DESC LIMIT ' . max(1, $limit) . '
             ) recent ORDER BY closed_at'
        );
        $statement->execute(['project' => $projectId]);

        return $statement->fetchAll();
    }
}
