<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/** The pieces of each person's dashboard — see the 0034 migration. */
class GadgetRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        $statement = $this->db->prepare('SELECT * FROM dashboard_gadgets WHERE user_id = :user ORDER BY position, id');
        $statement->execute(['user' => $userId]);

        return array_values($statement->fetchAll());
    }

    /** One of a person's own, or null. */
    public function find(int $id, int $userId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM dashboard_gadgets WHERE id = :id AND user_id = :user');
        $statement->execute(['id' => $id, 'user' => $userId]);

        return $statement->fetch() ?: null;
    }

    public function create(int $userId, string $kind, string $title, string $query, ?string $groupBy): int
    {
        $position = $this->db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM dashboard_gadgets WHERE user_id = :user');
        $position->execute(['user' => $userId]);

        $this->db->prepare(
            'INSERT INTO dashboard_gadgets (user_id, kind, title, query, group_by, position)
             VALUES (:user, :kind, :title, :query, :group, :position)'
        )->execute([
            'user' => $userId,
            'kind' => $kind,
            'title' => $title,
            'query' => $query,
            'group' => $groupBy,
            'position' => (int) $position->fetchColumn(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id, int $userId): void
    {
        $this->db->prepare('DELETE FROM dashboard_gadgets WHERE id = :id AND user_id = :user')
            ->execute(['id' => $id, 'user' => $userId]);
    }

    /** Swaps a gadget with the one before (-1) or after (+1) it. */
    public function move(int $id, int $userId, int $direction): void
    {
        $ids = array_map(static fn(array $g): int => (int) $g['id'], $this->forUser($userId));
        $index = array_search($id, $ids, true);

        if (!is_int($index) || !isset($ids[$index + ($direction < 0 ? -1 : 1)])) {
            return;
        }

        $other = $index + ($direction < 0 ? -1 : 1);
        [$ids[$index], $ids[$other]] = [$ids[$other], $ids[$index]];

        $statement = $this->db->prepare('UPDATE dashboard_gadgets SET position = :position WHERE id = :id');
        foreach ($ids as $position => $gadgetId) {
            $statement->execute(['position' => $position + 1, 'id' => $gadgetId]);
        }
    }
}
