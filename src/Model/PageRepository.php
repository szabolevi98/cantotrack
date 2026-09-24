<?php

namespace CantoTrack\Model;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * A project's pages, their versions, and the tickets they mention.
 */
class PageRepository
{
    private const SELECT = 'SELECT pg.*, p.code AS project_code, p.name AS project_name,
                   cu.name AS created_by_name, uu.name AS updated_by_name
            FROM pages pg
            JOIN projects p ON p.id = pg.project_id
            LEFT JOIN users cu ON cu.id = pg.created_by
            LEFT JOIN users uu ON uu.id = pg.updated_by';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(self::SELECT . ' WHERE pg.id = :id' . Access::sql('pg.project_id'));
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> a project's pages, in the order of the tree: parents before children */
    public function forProject(int $projectId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, parent_id, title, position, updated_at FROM pages WHERE project_id = :project ORDER BY position, title'
        );
        $statement->execute(['project' => $projectId]);

        return array_values($statement->fetchAll());
    }

    /**
     * A project's pages as a tree: each with its children, and its depth.
     *
     * @return list<array<string, mixed>> the top pages, each with 'children'
     */
    public function tree(int $projectId): array
    {
        $pages = $this->forProject($projectId);
        $children = [];
        foreach ($pages as $page) {
            $children[(int) ($page['parent_id'] ?? 0)][] = $page;
        }

        $build = static function (int $parent, int $depth) use (&$build, &$children): array {
            $out = [];
            foreach ($children[$parent] ?? [] as $page) {
                $page['depth'] = $depth;
                $page['children'] = $build((int) $page['id'], $depth + 1);
                $out[] = $page;
            }

            return $out;
        };

        return $build(0, 0);
    }

    /**
     * The same tree, flat, parents first and each with its depth — for a
     * list of pages to pick a parent from.
     *
     * @return list<array<string, mixed>>
     */
    public function flatTree(int $projectId): array
    {
        $out = [];
        $walk = static function (array $pages) use (&$walk, &$out): void {
            foreach ($pages as $page) {
                $children = $page['children'];
                unset($page['children']);
                $out[] = $page;
                $walk($children);
            }
        };
        $walk($this->tree($projectId));

        return $out;
    }

    /** @return list<array<string, mixed>> the pages above one, the top first */
    public function ancestors(array $page): array
    {
        $out = [];
        $seen = [];
        $parent = $page['parent_id'] === null ? null : (int) $page['parent_id'];

        while ($parent !== null && !isset($seen[$parent])) {
            $seen[$parent] = true;
            $statement = $this->db->prepare('SELECT id, parent_id, title FROM pages WHERE id = :id');
            $statement->execute(['id' => $parent]);
            $row = $statement->fetch();
            if ($row === false) {
                break;
            }
            array_unshift($out, $row);
            $parent = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }

        return $out;
    }

    /** @return list<int> a page's descendants, however deep */
    public function descendantIds(int $id): array
    {
        $out = [];
        $next = [$id];

        while ($next !== []) {
            $placeholders = implode(',', array_fill(0, count($next), '?'));
            $statement = $this->db->prepare('SELECT id FROM pages WHERE parent_id IN (' . $placeholders . ')');
            $statement->execute($next);
            $next = array_values(array_diff(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)), $out));
            $out = array_merge($out, $next);
        }

        return $out;
    }

    public function byTitle(int $projectId, string $title): ?array
    {
        $statement = $this->db->prepare('SELECT id, title FROM pages WHERE project_id = :project AND title = :title LIMIT 1');
        $statement->execute(['project' => $projectId, 'title' => $title]);

        return $statement->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> pages whose title or text holds some words */
    public function search(int $projectId, string $words): array
    {
        $like = '%' . addcslashes($words, '%_\\') . '%';
        $statement = $this->db->prepare(
            'SELECT id, parent_id, title, body, updated_at FROM pages
             WHERE project_id = :project AND (title LIKE :q OR body LIKE :q2)
             ORDER BY title LIKE :q3 DESC, updated_at DESC LIMIT 50'
        );
        $statement->execute(['project' => $projectId, 'q' => $like, 'q2' => $like, 'q3' => $like]);

        return array_values($statement->fetchAll());
    }

    /** @return list<array<string, mixed>> the latest pages edited, in the projects the person may see */
    public function recent(int $limit = 8): array
    {
        $statement = $this->db->query(
            self::SELECT . ' WHERE 1 = 1' . Access::sql('pg.project_id') . ' ORDER BY pg.updated_at DESC LIMIT ' . max(1, $limit)
        );

        return $statement === false ? [] : array_values($statement->fetchAll());
    }

    public function create(int $projectId, ?int $parentId, string $title, string $body, int $userId): int
    {
        $last = $this->db->prepare('SELECT COALESCE(MAX(position), 0) FROM pages WHERE project_id = :project AND parent_id <=> :parent');
        $last->execute(['project' => $projectId, 'parent' => $parentId]);

        $this->db->prepare(
            'INSERT INTO pages (project_id, parent_id, title, body, position, created_by, updated_by)
             VALUES (:project, :parent, :title, :body, :position, :user, :user2)'
        )->execute(['project' => $projectId, 'parent' => $parentId, 'title' => $title, 'body' => $body, 'position' => (int) $last->fetchColumn() + 1, 'user' => $userId, 'user2' => $userId]);

        return (int) $this->db->lastInsertId();
    }

    /** Saves over the version it was edited from; false when somebody saved in between. */
    public function update(int $id, ?int $parentId, string $title, string $body, int $userId, int $expectedVersion): bool
    {
        $statement = $this->db->prepare(
            'UPDATE pages SET parent_id = :parent, title = :title, body = :body, updated_by = :user, version = version + 1
             WHERE id = :id AND version = :version'
        );
        $statement->execute(['parent' => $parentId, 'title' => $title, 'body' => $body, 'user' => $userId, 'id' => $id, 'version' => $expectedVersion]);

        return $statement->rowCount() === 1;
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM pages WHERE id = :id')->execute(['id' => $id]);
    }

    public function keepVersion(array $page): void
    {
        $this->db->prepare(
            'INSERT IGNORE INTO page_versions (page_id, version, title, body, user_id, created_at)
             VALUES (:page, :version, :title, :body, :user, :at)'
        )->execute([
            'page' => $page['id'], 'version' => $page['version'], 'title' => $page['title'], 'body' => $page['body'],
            'user' => $page['updated_by'], 'at' => $page['updated_at'],
        ]);
    }

    /** @return list<array<string, mixed>> a page's earlier versions, the latest first */
    public function versions(int $pageId): array
    {
        $statement = $this->db->prepare(
            'SELECT v.*, u.name AS user_name FROM page_versions v LEFT JOIN users u ON u.id = v.user_id
             WHERE v.page_id = :page ORDER BY v.version DESC'
        );
        $statement->execute(['page' => $pageId]);

        return array_values($statement->fetchAll());
    }

    public function version(int $pageId, int $version): ?array
    {
        $statement = $this->db->prepare(
            'SELECT v.*, u.name AS user_name FROM page_versions v LEFT JOIN users u ON u.id = v.user_id
             WHERE v.page_id = :page AND v.version = :version'
        );
        $statement->execute(['page' => $pageId, 'version' => $version]);

        return $statement->fetch() ?: null;
    }

    /** @param list<int> $ticketIds the tickets a page mentions now */
    public function setTickets(int $pageId, array $ticketIds): void
    {
        $this->db->prepare('DELETE FROM page_tickets WHERE page_id = :page')->execute(['page' => $pageId]);
        $insert = $this->db->prepare('INSERT IGNORE INTO page_tickets (page_id, ticket_id) VALUES (:page, :ticket)');

        foreach (array_unique($ticketIds) as $ticketId) {
            $insert->execute(['page' => $pageId, 'ticket' => $ticketId]);
        }
    }

    /** @return list<array<string, mixed>> the pages that mention a ticket, the ones the person may see */
    public function mentioning(int $ticketId): array
    {
        $statement = $this->db->prepare(
            self::SELECT . ' JOIN page_tickets pt ON pt.page_id = pg.id WHERE pt.ticket_id = :ticket' . Access::sql('pg.project_id') . ' ORDER BY pg.updated_at DESC'
        );
        $statement->execute(['ticket' => $ticketId]);

        return array_values($statement->fetchAll());
    }
}
