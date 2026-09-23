<?php

namespace CantoTrack\Model;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * Releases: what goes out together. Every read counts what is in it the way
 * a person would — the tickets, not the steps they are broken into.
 */
class ReleaseRepository
{
    private const SELECT = 'SELECT r.*,
                   p.code AS project_code,
                   p.name AS project_name,
                   (SELECT COUNT(*) FROM tickets t WHERE t.release_id = r.id AND t.parent_id IS NULL) AS ticket_count,
                   (SELECT COUNT(*) FROM tickets t JOIN statuses s ON s.id = t.status_id
                     WHERE t.release_id = r.id AND t.parent_id IS NULL AND s.category = \'done\') AS done_count,
                   (SELECT COALESCE(SUM(t.story_points), 0) FROM tickets t WHERE t.release_id = r.id AND t.parent_id IS NULL) AS points
            FROM releases r
            JOIN projects p ON p.id = r.project_id';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(self::SELECT . ' WHERE r.id = :id' . Access::sql('r.project_id'));
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /**
     * A project's releases: the ones still coming first, soonest first, then
     * the ones that went out, the latest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forProject(int $projectId): array
    {
        $statement = $this->db->prepare(
            self::SELECT . ' WHERE r.project_id = :project' . Access::sql('r.project_id') . '
             ORDER BY r.released_at IS NOT NULL, CASE WHEN r.released_at IS NULL THEN COALESCE(r.release_on, \'9999-12-31\') END,
                      r.released_at DESC, r.id'
        );
        $statement->execute(['project' => $projectId]);

        return array_values($statement->fetchAll());
    }

    /** @return list<array<string, mixed>> the ones still coming, for a ticket to be put in */
    public function unreleased(int $projectId): array
    {
        return array_values(array_filter($this->forProject($projectId), static fn(array $r): bool => $r['released_at'] === null));
    }

    /** A project's release by its id or its name, the way an API client or a spreadsheet says it. */
    public function resolve(int $projectId, string $given): ?array
    {
        foreach ($this->forProject($projectId) as $release) {
            if ((string) $release['id'] === $given || mb_strtolower((string) $release['name']) === mb_strtolower($given)) {
                return $release;
            }
        }

        return null;
    }

    public function nameTaken(int $projectId, string $name, ?int $exceptId = null): bool
    {
        $statement = $this->db->prepare('SELECT id FROM releases WHERE project_id = :project AND name = :name');
        $statement->execute(['project' => $projectId, 'name' => $name]);
        $id = $statement->fetchColumn();

        return $id !== false && (int) $id !== $exceptId;
    }

    public function create(int $projectId, string $name, ?string $description, ?string $startsOn, ?string $releaseOn): int
    {
        $this->db->prepare(
            'INSERT INTO releases (project_id, name, description, starts_on, release_on)
             VALUES (:project, :name, :description, :starts, :release)'
        )->execute(['project' => $projectId, 'name' => $name, 'description' => $description, 'starts' => $startsOn, 'release' => $releaseOn]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, ?string $description, ?string $startsOn, ?string $releaseOn): void
    {
        $this->db->prepare(
            'UPDATE releases SET name = :name, description = :description, starts_on = :starts, release_on = :release WHERE id = :id'
        )->execute(['name' => $name, 'description' => $description, 'starts' => $startsOn, 'release' => $releaseOn, 'id' => $id]);
    }

    /** Out now — or, with null, back to still coming. */
    public function setReleased(int $id, ?string $at): void
    {
        $this->db->prepare('UPDATE releases SET released_at = :at WHERE id = :id')->execute(['at' => $at, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM releases WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return list<int> the unfinished tickets of a release, without their subtasks */
    public function unfinishedTicketIds(int $id): array
    {
        $statement = $this->db->prepare(
            'SELECT t.id FROM tickets t JOIN statuses s ON s.id = t.status_id
             WHERE t.release_id = :release AND t.parent_id IS NULL AND s.category <> \'done\''
        );
        $statement->execute(['release' => $id]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** Puts tickets — and their subtasks with them — into a release, or into none. */
    public function assign(array $ticketIds, ?int $releaseId): void
    {
        if ($ticketIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $ids = array_map('intval', $ticketIds);

        $this->db->prepare('UPDATE tickets SET release_id = ? WHERE id IN (' . $placeholders . ') OR parent_id IN (' . $placeholders . ')')
            ->execute(array_merge([$releaseId], $ids, $ids));
    }
}
