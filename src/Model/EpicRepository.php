<?php

namespace CantoTrack\Model;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * Epics: the grouping between a project and its tickets.
 *
 * An epic holds no work of its own. Everything about it — how much is left, how
 * many hours went in — is the sum of its tickets, which is why nothing is
 * stored here that could disagree with them.
 */
class EpicRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * The epics of a project, each with how many tickets it holds and how many
     * are done — the tickets themselves, not the steps they are broken into.
     */
    public function forProject(int $projectId): array
    {
        $statement = $this->db->prepare(
            'SELECT e.*,
                    COUNT(t.id) AS ticket_count,
                    SUM(s.category = \'done\') AS done_count
             FROM epics e
             LEFT JOIN tickets t ON t.epic_id = e.id AND t.parent_id IS NULL
             LEFT JOIN statuses s ON s.id = t.status_id
             WHERE e.project_id = :project
             GROUP BY e.id
             ORDER BY e.is_done, e.title'
        );

        $statement->execute(['project' => $projectId]);

        return $statement->fetchAll();
    }

    /** The open ones, for the dropdown on a ticket form. */
    public function openForProject(int $projectId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM epics WHERE project_id = :project AND is_done = 0 ORDER BY title'
        );

        $statement->execute(['project' => $projectId]);

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM epics WHERE id = :id' . Access::sql('project_id'));
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function create(int $projectId, string $title, ?string $description, ?string $startsOn = null, ?string $endsOn = null): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO epics (project_id, title, description, starts_on, ends_on) VALUES (:project, :title, :description, :starts, :ends)'
        );

        $statement->execute([
            'project' => $projectId,
            'title' => trim($title),
            'description' => $this->emptyToNull($description),
            'starts' => $startsOn,
            'ends' => $endsOn,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $title, ?string $description, bool $isDone, ?string $startsOn = null, ?string $endsOn = null): void
    {
        $statement = $this->db->prepare(
            'UPDATE epics SET title = :title, description = :description, is_done = :done, starts_on = :starts, ends_on = :ends WHERE id = :id'
        );

        $statement->execute([
            'title' => trim($title),
            'description' => $this->emptyToNull($description),
            'done' => $isDone ? 1 : 0,
            'starts' => $startsOn,
            'ends' => $endsOn,
            'id' => $id,
        ]);
    }

    /** Only the days, from the roadmap: a bar dragged or stretched. */
    public function setDays(int $id, ?string $startsOn, ?string $endsOn): void
    {
        $this->db->prepare('UPDATE epics SET starts_on = :starts, ends_on = :ends WHERE id = :id')
            ->execute(['starts' => $startsOn, 'ends' => $endsOn, 'id' => $id]);
    }

    /** Removing an epic leaves its tickets in the project, without one. */
    public function delete(int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM epics WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * How far an epic's work has got: its tickets (not their steps), how
     * many are done, their points, estimates and the hours logged on them
     * and on their subtasks.
     *
     * @return array{tickets: int, done: int, points: int, done_points: int, estimate: int, logged: int}
     */
    public function progress(int $id): array
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) AS tickets, COALESCE(SUM(s.category = \'done\'), 0) AS done,
                    COALESCE(SUM(t.story_points), 0) AS points,
                    COALESCE(SUM(CASE WHEN s.category = \'done\' THEN t.story_points ELSE 0 END), 0) AS done_points,
                    COALESCE(SUM(t.estimate_minutes), 0) AS estimate
             FROM tickets t JOIN statuses s ON s.id = t.status_id
             WHERE t.epic_id = :epic AND t.parent_id IS NULL'
        );
        $statement->execute(['epic' => $id]);
        $row = (array) $statement->fetch();

        $logged = $this->db->prepare(
            'SELECT COALESCE(SUM(w.minutes), 0) FROM worklogs w JOIN tickets t ON t.id = w.ticket_id WHERE t.epic_id = :epic'
        );
        $logged->execute(['epic' => $id]);

        return [
            'tickets' => (int) $row['tickets'],
            'done' => (int) $row['done'],
            'points' => (int) $row['points'],
            'done_points' => (int) $row['done_points'],
            'estimate' => (int) $row['estimate'],
            'logged' => (int) $logged->fetchColumn(),
        ];
    }

    // -----------------------------------------------------------------------
    // What was said about an epic, and what happened to it — see the 0042
    // migration.
    // -----------------------------------------------------------------------

    /** @return list<array<string, mixed>> the oldest first */
    public function comments(int $epicId): array
    {
        $statement = $this->db->prepare(
            'SELECT c.*, u.name AS user_name FROM epic_comments c JOIN users u ON u.id = c.user_id
             WHERE c.epic_id = :epic ORDER BY c.created_at, c.id'
        );
        $statement->execute(['epic' => $epicId]);

        return array_values($statement->fetchAll());
    }

    public function addComment(int $epicId, int $userId, string $body): int
    {
        $this->db->prepare('INSERT INTO epic_comments (epic_id, user_id, body) VALUES (:epic, :user, :body)')
            ->execute(['epic' => $epicId, 'user' => $userId, 'body' => $body]);

        return (int) $this->db->lastInsertId();
    }

    /** A comment on an epic the person may see, or null. */
    public function findComment(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT c.* FROM epic_comments c JOIN epics e ON e.id = c.epic_id WHERE c.id = :id' . Access::sql('e.project_id')
        );
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function editComment(int $id, string $body): void
    {
        $this->db->prepare('UPDATE epic_comments SET body = :body, edited_at = NOW() WHERE id = :id')->execute(['body' => $body, 'id' => $id]);
    }

    public function deleteComment(int $id): void
    {
        $this->db->prepare('DELETE FROM epic_comments WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return list<array<string, mixed>> the oldest first */
    public function events(int $epicId): array
    {
        $statement = $this->db->prepare(
            'SELECT ev.*, u.name AS user_name FROM epic_events ev LEFT JOIN users u ON u.id = ev.user_id
             WHERE ev.epic_id = :epic ORDER BY ev.created_at, ev.id'
        );
        $statement->execute(['epic' => $epicId]);

        return array_values($statement->fetchAll());
    }

    public function addEvent(int $epicId, ?int $userId, string $kind, ?string $field = null, ?string $old = null, ?string $new = null): void
    {
        $this->db->prepare(
            'INSERT INTO epic_events (epic_id, user_id, kind, field, old_value, new_value) VALUES (:epic, :user, :kind, :field, :old, :new)'
        )->execute([
            'epic' => $epicId,
            'user' => $userId,
            'kind' => $kind,
            'field' => $field,
            'old' => $old === null ? null : mb_substr($old, 0, 255),
            'new' => $new === null ? null : mb_substr($new, 0, 255),
        ]);
    }

    public function watch(int $epicId, int $userId): void
    {
        $this->db->prepare('INSERT IGNORE INTO epic_watchers (epic_id, user_id) VALUES (:epic, :user)')->execute(['epic' => $epicId, 'user' => $userId]);
    }

    public function unwatch(int $epicId, int $userId): void
    {
        $this->db->prepare('DELETE FROM epic_watchers WHERE epic_id = :epic AND user_id = :user')->execute(['epic' => $epicId, 'user' => $userId]);
    }

    public function isWatching(int $epicId, int $userId): bool
    {
        $statement = $this->db->prepare('SELECT EXISTS (SELECT 1 FROM epic_watchers WHERE epic_id = :epic AND user_id = :user)');
        $statement->execute(['epic' => $epicId, 'user' => $userId]);

        return (bool) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> the active people who follow an epic */
    public function watchers(int $epicId): array
    {
        $statement = $this->db->prepare(
            'SELECT u.* FROM epic_watchers w JOIN users u ON u.id = w.user_id WHERE w.epic_id = :epic AND u.is_active = 1 AND u.is_service = 0 ORDER BY u.name'
        );
        $statement->execute(['epic' => $epicId]);

        return array_values($statement->fetchAll());
    }

    private function emptyToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
