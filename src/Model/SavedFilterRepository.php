<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/** Ticket lists kept by name: somebody's own, and the ones shared with everyone. */
class SavedFilterRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** The filters a person sees: their own, then the shared ones of others. */
    public function visibleTo(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT f.*, u.name AS user_name, f.user_id = :me AS mine
             FROM saved_filters f JOIN users u ON u.id = f.user_id
             WHERE f.user_id = :me2 OR f.is_shared = 1
             ORDER BY mine DESC, f.name'
        );
        $statement->execute(['me' => $userId, 'me2' => $userId]);

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM saved_filters WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function create(int $userId, string $name, string $query, bool $shared): int
    {
        $this->db->prepare(
            'INSERT INTO saved_filters (user_id, name, query, is_shared) VALUES (:user, :name, :query, :shared)'
        )->execute(['user' => $userId, 'name' => $name, 'query' => $query, 'shared' => $shared ? 1 : 0]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM saved_filters WHERE id = :id')->execute(['id' => $id]);
    }
}
