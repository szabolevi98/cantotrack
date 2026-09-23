<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * Projects, and the counter that gives their tickets their numbers.
 */
class ProjectRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** The projects worth showing in a list, with how much work is in each. */
    public function allWithCounts(bool $includeArchived = false): array
    {
        $sql = 'SELECT p.*,
                       COUNT(t.id) AS ticket_count,
                       SUM(t.status = \'done\') AS done_count
                FROM projects p
                LEFT JOIN tickets t ON t.project_id = p.id';

        if (!$includeArchived) {
            $sql .= ' WHERE p.is_archived = 0';
        }

        $sql .= ' GROUP BY p.id ORDER BY p.is_archived, p.name';

        return $this->rows($sql);
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM projects WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM projects WHERE code = :code');
        $statement->execute(['code' => strtoupper(trim($code))]);

        return $statement->fetch() ?: null;
    }

    public function create(string $code, string $name, ?string $description): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO projects (code, name, description) VALUES (:code, :name, :description)'
        );

        $statement->execute([
            'code' => strtoupper(trim($code)),
            'name' => trim($name),
            'description' => $this->emptyToNull($description),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, ?string $description, bool $isArchived): void
    {
        // The code is not editable. It is printed on every ticket this project
        // ever had, and in every message and commit that mentions one of them.
        $statement = $this->db->prepare(
            'UPDATE projects SET name = :name, description = :description, is_archived = :archived WHERE id = :id'
        );

        $statement->execute([
            'name' => trim($name),
            'description' => $this->emptyToNull($description),
            'archived' => $isArchived ? 1 : 0,
            'id' => $id,
        ]);
    }

    /** Whether any hour has been logged against any ticket in the project. */
    public function hasWorklogs(int $id): bool
    {
        $statement = $this->db->prepare(
            'SELECT EXISTS (
                 SELECT 1 FROM worklogs w JOIN tickets t ON t.id = w.ticket_id WHERE t.project_id = :id
             )'
        );
        $statement->execute(['id' => $id]);

        return (bool) $statement->fetchColumn();
    }

    public function delete(int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM projects WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * Takes the next ticket number for a project.
     *
     * The row is locked and the counter raised in the same statement, so two
     * people creating a ticket at the same moment get different numbers. Reading
     * a maximum and adding one gives them both the same one, and the unique key
     * then fails the second insert — a race that shows up exactly when several
     * people are working, which is when a tracker is worth anything.
     *
     * Must be called inside a transaction; TicketRepository::create opens it.
     */
    public function takeNextNumber(int $projectId): int
    {
        $this->db->prepare('UPDATE projects SET next_ticket_number = next_ticket_number + 1 WHERE id = :id')
            ->execute(['id' => $projectId]);

        $statement = $this->db->prepare('SELECT next_ticket_number FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);

        return ((int) $statement->fetchColumn()) - 1;
    }

    private function emptyToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Every row a query without parameters returns. */
    private function rows(string $sql): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute();

        return $statement->fetchAll();
    }
}
