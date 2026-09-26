<?php

namespace CantoTrack\Model;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * Boards: the projects a sprint is planned from — see the 0047 migration.
 *
 * Every project has one board of its own, holding only it, made with the
 * project and gone with it. Any other board is shared: made by hand, with
 * whichever projects a team works on together, and columns of its own.
 */
class BoardRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT b.* FROM boards b WHERE b.id = :id' . Access::boardSql('b.id'));
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** A project's own board, made now if an older project somehow has none. */
    public function ownOf(int $projectId): array
    {
        $statement = $this->db->prepare('SELECT * FROM boards WHERE project_id = :project');
        $statement->execute(['project' => $projectId]);
        $board = $statement->fetch();

        if ($board) {
            return $board;
        }

        $code = $this->db->prepare('SELECT code FROM projects WHERE id = :project');
        $code->execute(['project' => $projectId]);
        $this->createOwn($projectId, (string) $code->fetchColumn());

        $statement->execute(['project' => $projectId]);

        return $statement->fetch();
    }

    /** The board a new project comes with. */
    public function createOwn(int $projectId, string $code): int
    {
        $this->db->prepare('INSERT INTO boards (name, project_id) VALUES (:name, :project)')
            ->execute(['name' => mb_substr($code . ' board', 0, 80), 'project' => $projectId]);
        $id = (int) $this->db->lastInsertId();

        $this->setProjects($id, [$projectId]);

        return $id;
    }

    /**
     * The shared boards somebody may see, by name, each with the codes of
     * its projects — for the sidebar and the list of boards.
     */
    public function shared(): array
    {
        $statement = $this->db->prepare(
            'SELECT b.*,
                    (SELECT GROUP_CONCAT(p.code ORDER BY p.code SEPARATOR \', \')
                       FROM board_projects bp JOIN projects p ON p.id = bp.project_id
                      WHERE bp.board_id = b.id) AS project_codes,
                    (SELECT sp.id FROM sprints sp WHERE sp.board_id = b.id AND sp.state = \'active\' LIMIT 1) AS active_sprint_id
             FROM boards b
             WHERE b.project_id IS NULL' . Access::boardSql('b.id') . '
             ORDER BY b.name, b.id'
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    /** Every board somebody may see: the projects' own by code, then the shared ones by name — for the API. */
    public function visible(): array
    {
        $statement = $this->db->prepare(
            'SELECT b.* FROM boards b LEFT JOIN projects p ON p.id = b.project_id
             WHERE 1 = 1' . Access::boardSql('b.id') . '
             ORDER BY b.project_id IS NULL, p.code, b.name, b.id'
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    /** The shared boards a project is on, by name. */
    public function sharedWith(int $projectId): array
    {
        $statement = $this->db->prepare(
            'SELECT b.* FROM boards b JOIN board_projects bp ON bp.board_id = b.id
             WHERE bp.project_id = :project AND b.project_id IS NULL
             ORDER BY b.name, b.id'
        );
        $statement->execute(['project' => $projectId]);

        return $statement->fetchAll();
    }

    /**
     * The projects on a board, in the order of their codes.
     *
     * @return array<int, array>
     */
    public function projects(int $boardId): array
    {
        $statement = $this->db->prepare(
            'SELECT p.* FROM board_projects bp JOIN projects p ON p.id = bp.project_id
             WHERE bp.board_id = :board ORDER BY p.code'
        );
        $statement->execute(['board' => $boardId]);

        return $statement->fetchAll();
    }

    /** @return list<int> */
    public function projectIds(int $boardId): array
    {
        return array_values(array_map(static fn(array $p): int => (int) $p['id'], $this->projects($boardId)));
    }

    public function create(string $name, ?string $query): int
    {
        $this->db->prepare('INSERT INTO boards (name, query) VALUES (:name, :query)')
            ->execute(['name' => $name, 'query' => $query]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, ?string $query): void
    {
        $this->db->prepare('UPDATE boards SET name = :name, query = :query WHERE id = :id')
            ->execute(['name' => $name, 'query' => $query, 'id' => $id]);
    }

    /** @param list<int> $projectIds */
    public function setProjects(int $boardId, array $projectIds): void
    {
        $this->db->prepare('DELETE FROM board_projects WHERE board_id = :board')->execute(['board' => $boardId]);

        $insert = $this->db->prepare('INSERT IGNORE INTO board_projects (board_id, project_id) VALUES (:board, :project)');
        foreach (array_unique(array_map('intval', $projectIds)) as $projectId) {
            $insert->execute(['board' => $boardId, 'project' => $projectId]);
        }
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM boards WHERE id = :id AND project_id IS NULL')->execute(['id' => $id]);
    }

    /**
     * A board's own columns, in order, each with the ids of the statuses put
     * in it by hand. None: the board takes its projects' columns as they are.
     *
     * @return list<array{id: int, name: string, position: int, status_ids: list<int>}>
     */
    public function columns(int $boardId): array
    {
        $mapped = $this->db->prepare(
            'SELECT cs.column_id, cs.status_id FROM board_column_statuses cs
             JOIN board_columns c ON c.id = cs.column_id WHERE c.board_id = :board'
        );
        $mapped->execute(['board' => $boardId]);
        $statusIds = [];

        foreach ($mapped->fetchAll() as $row) {
            $statusIds[(int) $row['column_id']][] = (int) $row['status_id'];
        }

        $statement = $this->db->prepare('SELECT * FROM board_columns WHERE board_id = :board ORDER BY position, id');
        $statement->execute(['board' => $boardId]);
        $columns = [];

        foreach ($statement->fetchAll() as $row) {
            $columns[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'position' => (int) $row['position'],
                'status_ids' => $statusIds[(int) $row['id']] ?? [],
            ];
        }

        return $columns;
    }

    /**
     * Puts a board's columns as given: their names in order, and for each
     * status put somewhere by hand, the position of its column in that list.
     * A column keeps its id — and whatever points at it — when its name
     * stays, whatever it moved to.
     *
     * @param list<string> $names
     * @param array<int, int> $statusColumns status id => index into $names
     */
    public function saveColumns(int $boardId, array $names, array $statusColumns): void
    {
        $existing = [];
        foreach ($this->columns($boardId) as $column) {
            $existing[mb_strtolower($column['name'])] = $column['id'];
        }

        $ids = [];
        $insert = $this->db->prepare('INSERT INTO board_columns (board_id, name, position) VALUES (:board, :name, :position)');
        $update = $this->db->prepare('UPDATE board_columns SET name = :name, position = :position WHERE id = :id');

        foreach ($names as $position => $name) {
            $key = mb_strtolower($name);

            if (isset($existing[$key])) {
                $update->execute(['name' => $name, 'position' => $position, 'id' => $existing[$key]]);
                $ids[$position] = $existing[$key];
                unset($existing[$key]);
            } else {
                $insert->execute(['board' => $boardId, 'name' => $name, 'position' => $position]);
                $ids[$position] = (int) $this->db->lastInsertId();
            }
        }

        if ($existing !== []) {
            $placeholders = implode(',', array_fill(0, count($existing), '?'));
            $this->db->prepare('DELETE FROM board_columns WHERE id IN (' . $placeholders . ')')->execute(array_values($existing));
        }

        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $this->db->prepare('DELETE FROM board_column_statuses WHERE column_id IN (' . $placeholders . ')')->execute(array_values($ids));
        }

        $map = $this->db->prepare('INSERT IGNORE INTO board_column_statuses (column_id, status_id) VALUES (:column, :status)');
        foreach ($statusColumns as $statusId => $index) {
            if (isset($ids[$index])) {
                $map->execute(['column' => $ids[$index], 'status' => (int) $statusId]);
            }
        }
    }
}
