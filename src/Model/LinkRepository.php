<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * How tickets depend on each other. Each link is one row, stored in one
 * direction; see the 0010 migration.
 */
class LinkRepository
{
    public const KINDS = ['blocks', 'relates', 'duplicates'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * A ticket's links, each read from this ticket's end: "blocks CT-7" for a
     * row it is the source of, "is blocked by CT-3" for one it is the target
     * of. `relates` reads the same both ways.
     */
    public function forTicket(int $ticketId): array
    {
        $statement = $this->db->prepare(
            'SELECT l.id, l.kind, \'out\' AS direction, o.id AS other_id, o.number AS other_number, o.title AS other_title,
                    p.code AS other_code, s.category AS other_category, s.name AS other_status, s.colour AS other_colour
             FROM ticket_links l
             JOIN tickets o ON o.id = l.target_id
             JOIN projects p ON p.id = o.project_id
             JOIN statuses s ON s.id = o.status_id
             WHERE l.source_id = :a
             UNION ALL
             SELECT l.id, l.kind, \'in\', o.id, o.number, o.title, p.code, s.category, s.name, s.colour
             FROM ticket_links l
             JOIN tickets o ON o.id = l.source_id
             JOIN projects p ON p.id = o.project_id
             JOIN statuses s ON s.id = o.status_id
             WHERE l.target_id = :b
             ORDER BY kind, other_code, other_number'
        );
        $statement->execute(['a' => $ticketId, 'b' => $ticketId]);

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM ticket_links WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function exists(int $sourceId, int $targetId, string $kind): bool
    {
        $statement = $this->db->prepare(
            'SELECT EXISTS (SELECT 1 FROM ticket_links WHERE kind = :kind
                AND ((source_id = :s AND target_id = :t) OR (:kind2 = \'relates\' AND source_id = :t2 AND target_id = :s2)))'
        );
        $statement->execute(['kind' => $kind, 'kind2' => $kind, 's' => $sourceId, 't' => $targetId, 's2' => $sourceId, 't2' => $targetId]);

        return (bool) $statement->fetchColumn();
    }

    public function create(int $sourceId, int $targetId, string $kind, ?int $userId): int
    {
        $this->db->prepare(
            'INSERT INTO ticket_links (source_id, target_id, kind, created_by) VALUES (:s, :t, :kind, :user)'
        )->execute(['s' => $sourceId, 't' => $targetId, 'kind' => $kind, 'user' => $userId]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM ticket_links WHERE id = :id')->execute(['id' => $id]);
    }
}
