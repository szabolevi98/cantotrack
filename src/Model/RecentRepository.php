<?php

namespace CantoTrack\Model;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * The tickets and pages each person opened lately — see the 0035
 * migration. Read back only as far as the person may still see them.
 */
class RecentRepository
{
    /** How many a person's list keeps. */
    private const KEPT = 30;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public function viewed(int $userId, string $kind, int $itemId): void
    {
        $this->db->prepare(
            'INSERT INTO recent_views (user_id, kind, item_id, viewed_at) VALUES (:user, :kind, :item, NOW())
             ON DUPLICATE KEY UPDATE viewed_at = NOW()'
        )->execute(['user' => $userId, 'kind' => $kind, 'item' => $itemId]);

        // Now and then, not on every visit: the list only needs to stay short.
        if (random_int(1, 20) === 1) {
            $this->db->prepare(
                'DELETE FROM recent_views WHERE user_id = :user AND viewed_at < (
                     SELECT viewed_at FROM (
                         SELECT viewed_at FROM recent_views WHERE user_id = :user2 ORDER BY viewed_at DESC LIMIT 1 OFFSET ' . self::KEPT . '
                     ) kept
                 )'
            )->execute(['user' => $userId, 'user2' => $userId]);
        }
    }

    /**
     * The latest first: what it is, what it is called, and where it is.
     *
     * @return list<array{kind: string, id: int, label: string, hint: string, url: string, viewed_at: string}>
     */
    public function latest(int $userId, int $limit = 8): array
    {
        $statement = $this->db->prepare(
            "SELECT rv.kind, rv.item_id, rv.viewed_at,
                    CASE rv.kind WHEN 'ticket' THEN t.title ELSE pg.title END AS label,
                    CASE rv.kind WHEN 'ticket' THEN CONCAT(tp.code, '-', t.number) ELSE pp.code END AS hint
             FROM recent_views rv
             LEFT JOIN tickets t ON rv.kind = 'ticket' AND t.id = rv.item_id
             LEFT JOIN projects tp ON tp.id = t.project_id
             LEFT JOIN pages pg ON rv.kind = 'page' AND pg.id = rv.item_id
             LEFT JOIN projects pp ON pp.id = pg.project_id
             WHERE rv.user_id = :user AND (t.id IS NOT NULL OR pg.id IS NOT NULL)"
            . Access::sql('COALESCE(t.project_id, pg.project_id)') . '
             ORDER BY rv.viewed_at DESC LIMIT ' . max(1, $limit)
        );
        $statement->execute(['user' => $userId]);

        return array_values(array_map(static fn(array $row): array => [
            'kind' => (string) $row['kind'],
            'id' => (int) $row['item_id'],
            'label' => (string) $row['label'],
            'hint' => (string) $row['hint'],
            'url' => ($row['kind'] === 'ticket' ? '/tickets/' : '/pages/') . $row['item_id'],
            'viewed_at' => (string) $row['viewed_at'],
        ], $statement->fetchAll()));
    }
}
