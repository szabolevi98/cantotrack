<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/** What each person should hear about, and who follows which ticket. */
class NotificationRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public function forUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $statement = $this->db->prepare(
            'SELECT n.*, a.name AS user_name, t.title AS ticket_title, t.number AS ticket_number, p.code AS project_code
             FROM notifications n
             JOIN tickets t ON t.id = n.ticket_id
             JOIN projects p ON p.id = t.project_id
             LEFT JOIN users a ON a.id = n.actor_id
             WHERE n.user_id = :user
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $statement->execute(['user' => $userId]);

        return $statement->fetchAll();
    }

    public function unreadCount(int $userId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user AND read_at IS NULL');
        $statement->execute(['user' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM notifications WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $this->db->prepare(
            'INSERT INTO notifications (user_id, ticket_id, actor_id, reason, kind, field, old_value, new_value)
             VALUES (:user, :ticket, :actor, :reason, :kind, :field, :old, :new)'
        )->execute([
            'user' => $data['user_id'],
            'ticket' => $data['ticket_id'],
            'actor' => $data['actor_id'],
            'reason' => $data['reason'],
            'kind' => $data['kind'],
            'field' => $data['field'],
            'old' => $data['old_value'] === null ? null : mb_substr((string) $data['old_value'], 0, 255),
            'new' => $data['new_value'] === null ? null : mb_substr((string) $data['new_value'], 0, 255),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function markRead(int $id): void
    {
        $this->db->prepare('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = :id')->execute(['id' => $id]);
    }

    public function markAllRead(int $userId): void
    {
        $this->db->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = :user AND read_at IS NULL')
            ->execute(['user' => $userId]);
    }

    /** Reading a ticket reads what was said about it. */
    public function markTicketRead(int $userId, int $ticketId): void
    {
        $this->db->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = :user AND ticket_id = :ticket AND read_at IS NULL')
            ->execute(['user' => $userId, 'ticket' => $ticketId]);
    }

    public function markEmailed(int $id): void
    {
        $this->db->prepare('UPDATE notifications SET emailed_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    }

    // -----------------------------------------------------------------------
    // Watchers
    // -----------------------------------------------------------------------

    /** Everybody who hears about a ticket: its watchers, its reporter and its assignee. */
    public function audience(int $ticketId): array
    {
        $statement = $this->db->prepare(
            'SELECT u.* FROM users u
             WHERE u.is_active = 1 AND (
                 u.id IN (SELECT user_id FROM ticket_watchers WHERE ticket_id = :a)
                 OR u.id = (SELECT reporter_id FROM tickets WHERE id = :b)
                 OR u.id = (SELECT assignee_id FROM tickets WHERE id = :c)
             )'
        );
        $statement->execute(['a' => $ticketId, 'b' => $ticketId, 'c' => $ticketId]);

        return $statement->fetchAll();
    }

    public function isWatching(int $ticketId, int $userId): bool
    {
        $statement = $this->db->prepare('SELECT EXISTS (SELECT 1 FROM ticket_watchers WHERE ticket_id = :t AND user_id = :u)');
        $statement->execute(['t' => $ticketId, 'u' => $userId]);

        return (bool) $statement->fetchColumn();
    }

    public function watch(int $ticketId, int $userId): void
    {
        $this->db->prepare('INSERT IGNORE INTO ticket_watchers (ticket_id, user_id) VALUES (:t, :u)')
            ->execute(['t' => $ticketId, 'u' => $userId]);
    }

    public function unwatch(int $ticketId, int $userId): void
    {
        $this->db->prepare('DELETE FROM ticket_watchers WHERE ticket_id = :t AND user_id = :u')
            ->execute(['t' => $ticketId, 'u' => $userId]);
    }

    public function watcherCount(int $ticketId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM ticket_watchers WHERE ticket_id = :t');
        $statement->execute(['t' => $ticketId]);

        return (int) $statement->fetchColumn();
    }
}
