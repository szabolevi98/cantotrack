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

        return $statement->fetchAll();
    }

    /** Every project's columns at once, keyed by project — for lists that mix projects. */
    public function byProject(): array
    {
        $statement = $this->db->prepare('SELECT * FROM statuses ORDER BY project_id, position, id');
        $statement->execute();

        $grouped = [];
        foreach ($statement->fetchAll() as $status) {
            $grouped[(int) $status['project_id']][] = $status;
        }

        return $grouped;
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM statuses WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
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
            'UPDATE tickets SET closed_at = CASE WHEN :category = \'done\' THEN COALESCE(closed_at, NOW()) ELSE NULL END
             WHERE status_id = :id'
        )->execute(['category' => self::category($category), 'id' => $id]);
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
                         closed_at = CASE WHEN :category = \'done\' THEN COALESCE(closed_at, NOW()) ELSE NULL END
                     WHERE status_id = :id'
                )->execute(['target' => $moveTo, 'category' => $target['category'] ?? 'todo', 'id' => $id]);
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
