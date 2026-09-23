<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/** What people have said on a ticket. */
class CommentRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public function forTicket(int $ticketId): array
    {
        $statement = $this->db->prepare(
            'SELECT c.*, u.name AS user_name
             FROM comments c JOIN users u ON u.id = c.user_id
             WHERE c.ticket_id = :ticket
             ORDER BY c.created_at, c.id'
        );
        $statement->execute(['ticket' => $ticketId]);

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT c.*, u.name AS user_name FROM comments c JOIN users u ON u.id = c.user_id WHERE c.id = :id'
        );
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function create(int $ticketId, int $userId, string $body): int
    {
        $this->db->prepare('INSERT INTO comments (ticket_id, user_id, body) VALUES (:ticket, :user, :body)')
            ->execute(['ticket' => $ticketId, 'user' => $userId, 'body' => $body]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $body): void
    {
        $this->db->prepare('UPDATE comments SET body = :body, edited_at = NOW() WHERE id = :id')
            ->execute(['body' => $body, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM comments WHERE id = :id')->execute(['id' => $id]);
    }

    public function countForTicket(int $ticketId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM comments WHERE ticket_id = :ticket');
        $statement->execute(['ticket' => $ticketId]);

        return (int) $statement->fetchColumn();
    }
}
