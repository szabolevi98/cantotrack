<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * A project's columns: the statuses its tickets move through.
 *
 * Every status belongs to one of three categories, and the categories are what
 * the rest of the application reasons with — see the 0005 migration. The name
 * is only for people, which is why it can be anything and the categories
 * cannot.
 */
class StatusRepository
{
    public const CATEGORIES = ['todo', 'in_progress', 'done'];

    public const COLOURS = ['slate', 'blue', 'amber', 'green', 'violet', 'red'];

    /** What a new project starts with: the five columns there always were. */
    public const DEFAULTS = [
        ['Backlog', 'todo', 'slate'],
        ['To do', 'todo', 'slate'],
        ['In progress', 'in_progress', 'blue'],
        ['Review', 'in_progress', 'amber'],
        ['Done', 'done', 'green'],
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** A project's columns in board order, each with how many tickets it holds. */
    public function forProject(int $projectId): array
    {
        $statement = $this->db->prepare(
            'SELECT s.*, (SELECT COUNT(*) FROM tickets t WHERE t.status_id = s.id) AS ticket_count
             FROM statuses s
             WHERE s.project_id = :project
             ORDER BY s.position, s.id'
        );
        $statement->execute(['project' => $projectId]);

        return $this->withMoves($statement->fetchAll());
    }

    /** Every project's columns at once, keyed by project — for lists that mix projects. */
    public function byProject(): array
    {
        $statement = $this->db->prepare('SELECT * FROM statuses ORDER BY project_id, position, id');
        $statement->execute();

        $grouped = [];
        foreach ($this->withMoves($statement->fetchAll()) as $status) {
            $grouped[(int) $status['project_id']][] = $status;
        }

        return $grouped;
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM statuses WHERE id = :id');
        $statement->execute(['id' => $id]);
        $status = $statement->fetch();

        return $status ? $this->withMoves([$status])[0] : null;
    }

    /**
     * Each column with `moves`: the ids of the columns its tickets may go
     * to, or null when they may go anywhere — see the 0031 migration.
     *
     * @param array<int, array<string, mixed>> $statuses
     * @return array<int, array<string, mixed>>
     */
    private function withMoves(array $statuses): array
    {
        $limited = array_values(array_map(
            static fn(array $s): int => (int) $s['id'],
            array_filter($statuses, static fn(array $s): bool => (int) ($s['moves_limited'] ?? 0) === 1)
        ));

        $moves = array_fill_keys($limited, []);

        if ($limited !== []) {
            $marks = implode(',', array_fill(0, count($limited), '?'));
            $statement = $this->db->prepare(
                'SELECT from_status_id, to_status_id FROM status_transitions WHERE from_status_id IN (' . $marks . ')'
            );
            $statement->execute($limited);

            foreach ($statement->fetchAll() as $row) {
                $moves[(int) $row['from_status_id']][] = (int) $row['to_status_id'];
            }
        }

        foreach ($statuses as &$status) {
            $status['moves'] = $moves[(int) $status['id']] ?? null;
        }
        unset($status);

        return $statuses;
    }

    /** Whether a ticket in one column may be moved to another. Staying put always may. */
    public static function allows(array $from, int $toId): bool
    {
        if ((int) $from['id'] === $toId || !isset($from['moves'])) {
            return true;
        }

        return in_array($toId, (array) $from['moves'], true);
    }

    /**
     * A project's moves, from the settings: for each column, the columns its
     * tickets may go to. A column allowed to go to every other is not
     * limited at all — so a column added later is open to it, as it would
     * be to anyone who never limited anything.
     *
     * @param array<int|string, mixed> $given column id => list of column ids
     */
    public function setMoves(int $projectId, array $given): void
    {
        $ids = array_map(static fn(array $s): int => (int) $s['id'], $this->forProject($projectId));

        $this->db->beginTransaction();

        try {
            $clear = $this->db->prepare('DELETE FROM status_transitions WHERE from_status_id = :id');
            $mark = $this->db->prepare('UPDATE statuses SET moves_limited = :limited WHERE id = :id');
            $add = $this->db->prepare('INSERT INTO status_transitions (from_status_id, to_status_id) VALUES (:from, :to)');

            foreach ($ids as $from) {
                $to = array_values(array_intersect(
                    $ids,
                    array_map('intval', (array) ($given[$from] ?? []))
                ));
                $to = array_values(array_diff($to, [$from]));
                $limited = count($to) < count($ids) - 1;

                $clear->execute(['id' => $from]);
                $mark->execute(['limited' => $limited ? 1 : 0, 'id' => $from]);

                if ($limited) {
                    foreach ($to as $target) {
                        $add->execute(['from' => $from, 'to' => $target]);
                    }
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    public function createDefaults(int $projectId): void
    {
        foreach (self::DEFAULTS as [$name, $category, $colour]) {
            $this->create($projectId, $name, $category, $colour, null);
        }
    }

    public function create(int $projectId, string $name, string $category, string $colour, ?int $wipLimit): int
    {
        $position = $this->db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM statuses WHERE project_id = :project');
        $position->execute(['project' => $projectId]);

        $statement = $this->db->prepare(
            'INSERT INTO statuses (project_id, name, category, colour, wip_limit, position)
             VALUES (:project, :name, :category, :colour, :wip, :position)'
        );
        $statement->execute([
            'project' => $projectId,
            'name' => trim($name),
            'category' => self::category($category),
            'colour' => self::colour($colour),
            'wip' => $wipLimit ?: null,
            'position' => (int) $position->fetchColumn(),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, string $category, string $colour, ?int $wipLimit): void
    {
        $this->db->prepare(
            'UPDATE statuses SET name = :name, category = :category, colour = :colour, wip_limit = :wip WHERE id = :id'
        )->execute([
            'name' => trim($name),
            'category' => self::category($category),
            'colour' => self::colour($colour),
            'wip' => $wipLimit ?: null,
            'id' => $id,
        ]);

        // A column that changes category changes what its tickets are: moved
        // into "done", they were finished now; moved out of it, they are open
        // again and no longer finished at all.
        $this->db->prepare(
            'UPDATE tickets SET closed_at = CASE WHEN :category = \'done\' THEN COALESCE(closed_at, NOW()) ELSE NULL END,
                 resolution = CASE WHEN :again = \'done\' THEN COALESCE(resolution, \'done\') ELSE NULL END
             WHERE status_id = :id'
        )->execute(['category' => self::category($category), 'again' => self::category($category), 'id' => $id]);
    }

    /** Swaps a column with its neighbour to the left (-1) or right (+1). */
    public function move(int $id, int $direction): void
    {
        $status = $this->find($id);
        if ($status === null) {
            return;
        }

        $ids = array_values(array_map(
            static fn(array $s): int => (int) $s['id'],
            $this->forProject((int) $status['project_id'])
        ));

        $index = array_search($id, $ids, true);
        if (!is_int($index)) {
            return;
        }

        $neighbour = $index + ($direction < 0 ? -1 : 1);
        if (!isset($ids[$neighbour])) {
            return;
        }

        [$ids[$index], $ids[$neighbour]] = [$ids[$neighbour], $ids[$index]];

        $statement = $this->db->prepare('UPDATE statuses SET position = :position WHERE id = :id');
        foreach ($ids as $position => $statusId) {
            $statement->execute(['position' => $position + 1, 'id' => $statusId]);
        }
    }

    /**
     * Deletes a column, after moving its tickets to another of the same
     * project. Both in one transaction: a column is never gone while tickets
     * still point at it.
     */
    public function delete(int $id, ?int $moveTo): void
    {
        $this->db->beginTransaction();

        try {
            if ($moveTo !== null) {
                $target = $this->find($moveTo);

                $this->db->prepare(
                    'UPDATE tickets SET status_id = :target,
                         closed_at = CASE WHEN :category = \'done\' THEN COALESCE(closed_at, NOW()) ELSE NULL END,
                         resolution = CASE WHEN :again = \'done\' THEN COALESCE(resolution, \'done\') ELSE NULL END
                     WHERE status_id = :id'
                )->execute(['target' => $moveTo, 'category' => $target['category'] ?? 'todo', 'again' => $target['category'] ?? 'todo', 'id' => $id]);
            }

            $this->db->prepare('DELETE FROM statuses WHERE id = :id')->execute(['id' => $id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    /**
     * The status a request means, within one project: its id, its name ("In
     * progress", "in_progress" — the API and old links say it either way), or a
     * category, which means that category's first column.
     */
    public function resolve(int $projectId, mixed $given): ?array
    {
        $columns = $this->forProject($projectId);
        $given = trim((string) $given);

        if ($given === '') {
            return $columns[0] ?? null;
        }

        if (ctype_digit($given)) {
            foreach ($columns as $status) {
                if ((int) $status['id'] === (int) $given) {
                    return $status;
                }
            }

            return null;
        }

        $wanted = self::plain($given);

        foreach ($columns as $status) {
            if (self::plain((string) $status['name']) === $wanted) {
                return $status;
            }
        }

        foreach ($columns as $status) {
            if (self::plain((string) $status['category']) === $wanted) {
                return $status;
            }
        }

        return null;
    }

    public static function category(string $category): string
    {
        return in_array($category, self::CATEGORIES, true) ? $category : 'todo';
    }

    public static function colour(string $colour): string
    {
        return in_array($colour, self::COLOURS, true) ? $colour : 'slate';
    }

    /** "In progress", "in_progress" and "inprogress" all read the same. */
    private static function plain(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($name));
    }
}
