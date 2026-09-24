<?php

namespace CantoTrack\Model;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use PDO;

/** Ticket templates, and the tickets made from them on their day — see the 0045 migration. */
class TemplateRepository
{
    private const FIELDS = ['name', 'type', 'priority', 'title', 'description', 'labels', 'estimate_minutes', 'story_points', 'subtasks'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** @return list<array<string, mixed>> */
    public function forProject(int $projectId): array
    {
        $statement = $this->db->prepare('SELECT * FROM ticket_templates WHERE project_id = :project ORDER BY name');
        $statement->execute(['project' => $projectId]);

        return array_values($statement->fetchAll());
    }

    /** One the person may see, or null. */
    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM ticket_templates WHERE id = :id' . Access::sql('project_id'));
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** @param array<string, mixed> $data the FIELDS, checked */
    public function create(int $projectId, array $data, ?int $userId): int
    {
        $this->db->prepare(
            'INSERT INTO ticket_templates (project_id, ' . implode(', ', self::FIELDS) . ', created_by)
             VALUES (:project, :' . implode(', :', self::FIELDS) . ', :user)'
        )->execute(['project' => $projectId, 'user' => $userId] + $this->only($data));

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $data the FIELDS, checked */
    public function update(int $id, array $data): void
    {
        $this->db->prepare(
            'UPDATE ticket_templates SET ' . implode(', ', array_map(static fn(string $f): string => $f . ' = :' . $f, self::FIELDS)) . ' WHERE id = :id'
        )->execute(['id' => $id] + $this->only($data));
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM ticket_templates WHERE id = :id')->execute(['id' => $id]);
    }

    // -----------------------------------------------------------------------
    // Repeating tickets
    // -----------------------------------------------------------------------

    /** @return list<array<string, mixed>> a project's, with their template's name and the last ticket made */
    public function recurringFor(int $projectId): array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, tt.name AS template_name, tt.project_id, u.name AS assignee_name,
                    lt.number AS last_number, p.code AS project_code
             FROM recurring_tickets r
             JOIN ticket_templates tt ON tt.id = r.template_id
             JOIN projects p ON p.id = tt.project_id
             LEFT JOIN users u ON u.id = r.assignee_id
             LEFT JOIN tickets lt ON lt.id = r.last_ticket_id
             WHERE tt.project_id = :project
             ORDER BY r.is_active DESC, r.next_on, r.id'
        );
        $statement->execute(['project' => $projectId]);

        return array_values($statement->fetchAll());
    }

    public function findRecurring(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, tt.project_id FROM recurring_tickets r JOIN ticket_templates tt ON tt.id = r.template_id
             WHERE r.id = :id' . Access::sql('tt.project_id')
        );
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** @param array{template_id: int, assignee_id: ?int, frequency: string, weekday: ?int, month_day: ?int, due_days: ?int, next_on: string} $data */
    public function createRecurring(array $data, ?int $userId): int
    {
        $this->db->prepare(
            'INSERT INTO recurring_tickets (template_id, assignee_id, frequency, weekday, month_day, due_days, next_on, created_by)
             VALUES (:template_id, :assignee_id, :frequency, :weekday, :month_day, :due_days, :next_on, :user)'
        )->execute($data + ['user' => $userId]);

        return (int) $this->db->lastInsertId();
    }

    public function setActive(int $id, bool $active, ?string $nextOn = null): void
    {
        $this->db->prepare('UPDATE recurring_tickets SET is_active = :active, next_on = COALESCE(:next, next_on) WHERE id = :id')
            ->execute(['active' => $active ? 1 : 0, 'next' => $nextOn, 'id' => $id]);
    }

    public function deleteRecurring(int $id): void
    {
        $this->db->prepare('DELETE FROM recurring_tickets WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return list<array<string, mixed>> the active ones due on or before a day, with their template */
    public function due(string $day): array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, tt.project_id FROM recurring_tickets r JOIN ticket_templates tt ON tt.id = r.template_id
             JOIN projects p ON p.id = tt.project_id
             WHERE r.is_active = 1 AND r.next_on <= :day AND p.is_archived = 0
             ORDER BY r.next_on, r.id'
        );
        $statement->execute(['day' => $day]);

        return array_values($statement->fetchAll());
    }

    /**
     * Moves a repeating ticket on — but only from the day it was read at, so
     * that two runs at once do not both make the same day's ticket. Returns
     * whether this run is the one that moved it.
     */
    public function claim(int $id, string $was, string $next): bool
    {
        $statement = $this->db->prepare('UPDATE recurring_tickets SET next_on = :next WHERE id = :id AND next_on = :was');
        $statement->execute(['next' => $next, 'id' => $id, 'was' => $was]);

        return $statement->rowCount() > 0;
    }

    public function made(int $id, int $ticketId): void
    {
        $this->db->prepare('UPDATE recurring_tickets SET last_ticket_id = :ticket, last_made_at = NOW() WHERE id = :id')
            ->execute(['ticket' => $ticketId, 'id' => $id]);
    }

    /** @return array<string, mixed> */
    private function only(array $data): array
    {
        $out = [];

        foreach (self::FIELDS as $field) {
            $out[$field] = $data[$field] ?? null;
        }

        return $out;
    }
}
