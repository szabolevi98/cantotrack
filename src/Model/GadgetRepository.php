<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/** The pieces of each person's dashboard — see the 0034 and 0041 migrations. */
class GadgetRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** @return list<array<string, mixed>> the main column's first, each column in its order */
    public function forUser(int $userId): array
    {
        $statement = $this->db->prepare('SELECT * FROM dashboard_gadgets WHERE user_id = :user ORDER BY area = \'side\', position, id');
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

    public function create(int $userId, string $kind, string $title, string $query, ?string $groupBy, string $area = 'main'): int
    {
        $area = $area === 'side' ? 'side' : 'main';
        $position = $this->db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM dashboard_gadgets WHERE user_id = :user AND area = :area');
        $position->execute(['user' => $userId, 'area' => $area]);

        $this->db->prepare(
            'INSERT INTO dashboard_gadgets (user_id, kind, title, query, group_by, area, position)
             VALUES (:user, :kind, :title, :query, :group, :area, :position)'
        )->execute([
            'user' => $userId,
            'kind' => $kind,
            'title' => $title,
            'query' => $query,
            'group' => $groupBy,
            'area' => $area,
            'position' => (int) $position->fetchColumn(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** A piece's title, query and how it shows them — changed in place. */
    public function update(int $id, int $userId, string $kind, string $title, string $query, ?string $groupBy): void
    {
        $this->db->prepare(
            'UPDATE dashboard_gadgets SET kind = :kind, title = :title, query = :query, group_by = :group WHERE id = :id AND user_id = :user'
        )->execute(['kind' => $kind, 'title' => $title, 'query' => $query, 'group' => $groupBy, 'id' => $id, 'user' => $userId]);
    }

    public function delete(int $id, int $userId): void
    {
        $this->db->prepare('DELETE FROM dashboard_gadgets WHERE id = :id AND user_id = :user')
            ->execute(['id' => $id, 'user' => $userId]);
    }

    /**
     * The whole layout at once, as it was dragged: each column's pieces in
     * order. Only the person's own pieces move; anything else named is let be.
     *
     * @param list<int> $main
     * @param list<int> $side
     */
    public function arrange(int $userId, array $main, array $side): void
    {
        $statement = $this->db->prepare('UPDATE dashboard_gadgets SET area = :area, position = :position WHERE id = :id AND user_id = :user');

        foreach (['main' => $main, 'side' => $side] as $area => $ids) {
            foreach ($ids as $position => $id) {
                $statement->execute(['area' => $area, 'position' => $position + 1, 'id' => $id, 'user' => $userId]);
            }
        }
    }

    /**
     * One step up or down its column, or over to the other column's end —
     * the keyboard's way of doing what dragging does.
     */
    public function move(int $id, int $userId, string $direction): void
    {
        $gadget = $this->find($id, $userId);
        if ($gadget === null) {
            return;
        }

        $columns = ['main' => [], 'side' => []];
        foreach ($this->forUser($userId) as $row) {
            $columns[(string) $row['area']][] = (int) $row['id'];
        }

        $area = (string) $gadget['area'];
        $ids = $columns[$area];
        $index = (int) array_search($id, $ids, true);

        if ($direction === 'other') {
            array_splice($ids, $index, 1);
            $columns[$area] = $ids;
            $other = $area === 'main' ? 'side' : 'main';
            $columns[$other][] = $id;
        } else {
            $neighbour = $index + ($direction === 'up' ? -1 : 1);
            if (!isset($ids[$neighbour])) {
                return;
            }
            [$ids[$index], $ids[$neighbour]] = [$ids[$neighbour], $ids[$index]];
            $columns[$area] = $ids;
        }

        $this->arrange($userId, array_values($columns['main']), array_values($columns['side']));
    }

    public function isLaidOut(int $userId): bool
    {
        $statement = $this->db->prepare('SELECT dashboard_laid_out FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function markLaidOut(int $userId, bool $laidOut = true): void
    {
        $this->db->prepare('UPDATE users SET dashboard_laid_out = :done WHERE id = :id')
            ->execute(['done' => $laidOut ? 1 : 0, 'id' => $userId]);
    }
}
