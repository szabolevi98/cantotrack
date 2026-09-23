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
                       SUM(s.category = \'done\') AS done_count
                FROM projects p
                LEFT JOIN tickets t ON t.project_id = p.id
                LEFT JOIN statuses s ON s.id = t.status_id';

        if (!$includeArchived) {
            $sql .= ' WHERE p.is_archived = 0';
        }

        $sql .= ' GROUP BY p.id ORDER BY p.is_archived, p.name';

        return $this->rows($sql);
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT p.*, c.name AS client_name FROM projects p LEFT JOIN clients c ON c.id = p.client_id WHERE p.id = :id'
        );
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

        $id = (int) $this->db->lastInsertId();

        // Every project starts with the five columns there always were; its
        // settings page changes them from there.
        (new StatusRepository($this->db))->createDefaults($id);

        return $id;
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

    /** Who the project is for, and whether its hours are billed unless said otherwise. */
    public function setBilling(int $id, ?int $clientId, bool $billableByDefault): void
    {
        $this->db->prepare('UPDATE projects SET client_id = :client, billable_default = :billable WHERE id = :id')
            ->execute(['client' => $clientId, 'billable' => $billableByDefault ? 1 : 0, 'id' => $id]);
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

    /**
     * Deletes a project and its tickets.
     *
     * The tickets go first, explicitly. Left to the cascade, the database
     * would reach the project's columns and its tickets in an order of its own
     * choosing — and a column cannot go while a ticket still stands in it.
     */
    public function delete(int $id): void
    {
        $this->db->beginTransaction();

        try {
            $this->db->prepare('DELETE FROM tickets WHERE project_id = :id')->execute(['id' => $id]);
            $this->db->prepare('DELETE FROM projects WHERE id = :id')->execute(['id' => $id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
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
