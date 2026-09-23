<?php

namespace CantoTrack\Model;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * A ticket's history: one row per thing that happened to it. See the 0007
 * migration for why the values are kept as words.
 */
class EventRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public function record(
        int $ticketId,
        ?int $userId,
        string $kind,
        ?string $field = null,
        ?string $old = null,
        ?string $new = null
    ): void {
        $this->db->prepare(
            'INSERT INTO ticket_events (ticket_id, user_id, kind, field, old_value, new_value)
             VALUES (:ticket, :user, :kind, :field, :old, :new)'
        )->execute([
            'ticket' => $ticketId,
            'user' => $userId,
            'kind' => $kind,
            'field' => $field,
            'old' => self::clip($old),
            'new' => self::clip($new),
        ]);
    }

    /** Whether a commit is in a ticket's history already — pushed to a second branch, say. */
    public function hasCommit(int $ticketId, string $sha): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM ticket_events WHERE ticket_id = :ticket AND kind = \'commit\' AND old_value = :sha LIMIT 1'
        );
        $statement->execute(['ticket' => $ticketId, 'sha' => $sha]);

        return $statement->fetchColumn() !== false;
    }

    public function forTicket(int $ticketId): array
    {
        $statement = $this->db->prepare(
            'SELECT e.*, u.name AS user_name
             FROM ticket_events e LEFT JOIN users u ON u.id = e.user_id
             WHERE e.ticket_id = :ticket
             ORDER BY e.created_at, e.id'
        );
        $statement->execute(['ticket' => $ticketId]);

        return $statement->fetchAll();
    }

    /**
     * What happened lately, across every project: the dashboard's feed.
     * Comments are part of it — they are the most interesting thing that
     * happens to a ticket — and come from their own table.
     */
    public function recent(int $limit = 20, ?array $projectIds = null): array
    {
        // Never more than the signed-in person may see, whatever was asked for.
        $visible = Access::projectIds();
        if ($visible !== null) {
            $projectIds = $projectIds === null ? $visible : array_values(array_intersect($projectIds, $visible));
        }

        $only = $projectIds === null ? '' : ' AND t.project_id IN (' . ($projectIds === [] ? '0' : implode(',', array_map('intval', $projectIds))) . ')';

        $statement = $this->db->prepare(
            '(SELECT e.id, e.ticket_id, e.user_id, e.kind, e.field, e.old_value, e.new_value, e.created_at,
                     NULL AS comment_id, NULL AS body,
                     u.name AS user_name, t.number AS ticket_number, t.title AS ticket_title, p.code AS project_code
              FROM ticket_events e
              JOIN tickets t ON t.id = e.ticket_id
              JOIN projects p ON p.id = t.project_id
              LEFT JOIN users u ON u.id = e.user_id
              WHERE e.kind <> \'commented\'' . $only . '
              ORDER BY e.created_at DESC, e.id DESC
              LIMIT ' . max(1, $limit) . ')
             UNION ALL
             (SELECT c.id, c.ticket_id, c.user_id, \'commented\', NULL, NULL, NULL, c.created_at,
                     c.id, c.body,
                     u.name, t.number, t.title, p.code
              FROM comments c
              JOIN tickets t ON t.id = c.ticket_id
              JOIN projects p ON p.id = t.project_id
              JOIN users u ON u.id = c.user_id
              WHERE 1 = 1' . $only . '
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ' . max(1, $limit) . ')
             ORDER BY created_at DESC
             LIMIT ' . max(1, $limit)
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    private static function clip(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, 255);
    }
}
