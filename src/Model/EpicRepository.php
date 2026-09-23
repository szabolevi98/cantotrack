<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * Epics: the grouping between a project and its tickets.
 *
 * An epic holds no work of its own. Everything about it — how much is left, how
 * many hours went in — is the sum of its tickets, which is why nothing is
 * stored here that could disagree with them.
 */
class EpicRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** The epics of a project, each with how many tickets it holds and how many are done. */
    public function forProject(int $projectId): array
    {
        $statement = $this->db->prepare(
            'SELECT e.*,
                    COUNT(t.id) AS ticket_count,
                    SUM(s.category = \'done\') AS done_count
             FROM epics e
             LEFT JOIN tickets t ON t.epic_id = e.id
             LEFT JOIN statuses s ON s.id = t.status_id
             WHERE e.project_id = :project
             GROUP BY e.id
             ORDER BY e.is_done, e.title'
        );

        $statement->execute(['project' => $projectId]);

        return $statement->fetchAll();
    }

    /** The open ones, for the dropdown on a ticket form. */
    public function openForProject(int $projectId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM epics WHERE project_id = :project AND is_done = 0 ORDER BY title'
        );

        $statement->execute(['project' => $projectId]);

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM epics WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function create(int $projectId, string $title, ?string $description): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO epics (project_id, title, description) VALUES (:project, :title, :description)'
        );

        $statement->execute([
            'project' => $projectId,
            'title' => trim($title),
            'description' => $this->emptyToNull($description),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $title, ?string $description, bool $isDone): void
    {
        $statement = $this->db->prepare(
            'UPDATE epics SET title = :title, description = :description, is_done = :done WHERE id = :id'
        );

        $statement->execute([
            'title' => trim($title),
            'description' => $this->emptyToNull($description),
            'done' => $isDone ? 1 : 0,
            'id' => $id,
        ]);
    }

    /** Removing an epic leaves its tickets in the project, without one. */
    public function delete(int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM epics WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    private function emptyToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
