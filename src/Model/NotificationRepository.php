<?php

namespace CantoTrack\Model;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use PDO;

/** What each person should hear about, and who follows which ticket. */
class NotificationRepository
{
    /**
     * Which of the profile's kinds a notification is (see NotifySettings),
     * in SQL: why the person was told, and what happened.
     */
    private const KIND = "CASE WHEN n.reason = 'assigned' THEN 'assigned' WHEN n.reason = 'mentioned' THEN 'mentioned'
        WHEN n.kind IN ('status', 'done') THEN 'status' WHEN n.kind = 'commented' THEN 'commented' ELSE 'changes' END";

    private const SELECT = 'SELECT n.*, a.name AS user_name, t.title AS ticket_title, t.number AS ticket_number, p.code AS project_code,
                    p.id AS project_id, ep.title AS epic_title, ' . self::KIND . ' AS told_kind
             FROM notifications n
             LEFT JOIN tickets t ON t.id = n.ticket_id
             LEFT JOIN epics ep ON ep.id = n.epic_id
             JOIN projects p ON p.id = COALESCE(t.project_id, ep.project_id)
             LEFT JOIN users a ON a.id = n.actor_id';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * One person's notifications, newest first — narrowed, if asked, to the
     * unread ones, one kind (see NotifySettings::KINDS) or one project.
     *
     * @param array{unread?: bool, kind?: ?string, project_id?: ?int} $filters
     */
    public function forUser(int $userId, int $limit = 50, int $offset = 0, array $filters = []): array
    {
        [$where, $parameters] = $this->narrowed($userId, $filters);

        $statement = $this->db->prepare(
            self::SELECT . ' WHERE ' . $where . '
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * The unread ones that came after the one with `$afterId` — what the page
     * that asks every minute has not shown yet — oldest first.
     */
    public function unreadSince(int $userId, int $afterId, int $limit = 5): array
    {
        $statement = $this->db->prepare(
            self::SELECT . ' WHERE n.user_id = :user AND n.read_at IS NULL AND n.id > :after' . Access::sql('p.id') . '
             ORDER BY n.id DESC LIMIT ' . max(1, $limit)
        );
        $statement->execute(['user' => $userId, 'after' => $afterId]);

        return array_reverse($statement->fetchAll());
    }

    /** The id of somebody's newest notification, or 0. */
    public function latestId(int $userId): int
    {
        $statement = $this->db->prepare('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = :user');
        $statement->execute(['user' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function unreadCount(int $userId): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM notifications n LEFT JOIN tickets t ON t.id = n.ticket_id LEFT JOIN epics ep ON ep.id = n.epic_id
             WHERE n.user_id = :user AND n.read_at IS NULL AND (t.id IS NOT NULL OR ep.id IS NOT NULL)' . Access::sql('COALESCE(t.project_id, ep.project_id)')
        );
        $statement->execute(['user' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * The projects somebody has notifications from, by code — what the
     * notifications page can be narrowed to.
     *
     * @return array<int, string> project id => code
     */
    public function projectsOf(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT p.id, p.code FROM notifications n
             LEFT JOIN tickets t ON t.id = n.ticket_id LEFT JOIN epics ep ON ep.id = n.epic_id
             JOIN projects p ON p.id = COALESCE(t.project_id, ep.project_id)
             WHERE n.user_id = :user' . Access::sql('p.id') . ' ORDER BY p.code'
        );
        $statement->execute(['user' => $userId]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM notifications WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** One with everything a line about it needs, for whoever it is for — whatever the request's own person may see. */
    public function findFull(int $id): ?array
    {
        $statement = $this->db->prepare(self::SELECT . ' WHERE n.id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $this->db->prepare(
            'INSERT INTO notifications (user_id, ticket_id, epic_id, actor_id, reason, kind, field, old_value, new_value)
             VALUES (:user, :ticket, :epic, :actor, :reason, :kind, :field, :old, :new)'
        )->execute([
            'user' => $data['user_id'],
            'ticket' => $data['ticket_id'] ?? null,
            'epic' => $data['epic_id'] ?? null,
            'actor' => $data['actor_id'],
            'reason' => $data['reason'],
            'kind' => $data['kind'],
            'field' => $data['field'],
            'old' => $data['old_value'] === null ? null : mb_substr((string) $data['old_value'], 0, 255),
            'new' => $data['new_value'] === null ? null : mb_substr((string) $data['new_value'], 0, 255),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function markRead(int $id): void
    {
        $this->db->prepare('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = :id')->execute(['id' => $id]);
    }

    public function markUnread(int $id): void
    {
        $this->db->prepare('UPDATE notifications SET read_at = NULL WHERE id = :id')->execute(['id' => $id]);
    }

    public function markAllRead(int $userId): void
    {
        $this->db->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = :user AND read_at IS NULL')
            ->execute(['user' => $userId]);
    }

    /** Reading a ticket reads what was said about it. */
    public function markTicketRead(int $userId, int $ticketId): void
    {
        $this->db->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = :user AND ticket_id = :ticket AND read_at IS NULL')
            ->execute(['user' => $userId, 'ticket' => $ticketId]);
    }

    /** Reading an epic reads what was said about it. */
    public function markEpicRead(int $userId, int $epicId): void
    {
        $this->db->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = :user AND epic_id = :epic AND read_at IS NULL')
            ->execute(['user' => $userId, 'epic' => $epicId]);
    }

    /** Marked emailed once the message it went in has gone — with every other one that message carried. */
    public function markEmailed(int $id): void
    {
        $this->db->prepare('UPDATE notifications SET emailed_at = NOW() WHERE id = :id OR mail_batch_id = :batch')
            ->execute(['id' => $id, 'batch' => $id]);
    }

    // -----------------------------------------------------------------------
    // Email, a few minutes later and together — see the 0048 migration
    // -----------------------------------------------------------------------

    /** To be emailed once `$minutes` have passed without more about the same ticket. */
    public function wantMail(int $id, int $minutes): void
    {
        $this->db->prepare('UPDATE notifications SET mail_after = NOW() + INTERVAL ' . max(0, $minutes) . ' MINUTE WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * What is waiting to be emailed and has waited long enough: for each
     * person and ticket (or epic), the notifications to go in one message —
     * when the newest of them is due, so a conversation still going on is
     * waited for. By the database's clock, the one `mail_after` was set by;
     * `$ahead` minutes on, for a test that does not want to wait.
     *
     * @return list<array{user_id: int, ids: non-empty-list<int>}>
     */
    public function mailDue(int $ahead = 0): array
    {
        $statement = $this->db->prepare(
            "SELECT n.user_id, COALESCE(CONCAT('t', n.ticket_id), CONCAT('e', n.epic_id)) AS about,
                    GROUP_CONCAT(n.id ORDER BY n.id) AS ids
             FROM notifications n
             WHERE n.mail_after IS NOT NULL AND n.mail_batch_id IS NULL AND n.emailed_at IS NULL
             GROUP BY n.user_id, about
             HAVING MAX(n.mail_after) <= NOW() + INTERVAL " . max(0, $ahead) . ' MINUTE
             ORDER BY MIN(n.id)'
        );
        $statement->execute();

        return array_values(array_map(static fn(array $row): array => [
            'user_id' => (int) $row['user_id'],
            'ids' => array_map('intval', explode(',', (string) $row['ids'])),
        ], $statement->fetchAll()));
    }

    /**
     * Takes some for one message, so two runs at once do not both send it:
     * true when all of them were still free.
     *
     * @param list<int> $ids
     */
    public function claimForMail(array $ids, int $batchId): bool
    {
        if ($ids === []) {
            return false;
        }

        $statement = $this->db->prepare(
            'UPDATE notifications SET mail_batch_id = ?, mail_after = NULL
             WHERE mail_batch_id IS NULL AND id IN (' . implode(',', array_map('intval', $ids)) . ')'
        );
        $statement->execute([$batchId]);

        return $statement->rowCount() === count($ids);
    }

    /**
     * Some of them, with everything a line about each needs, oldest first.
     *
     * @param list<int> $ids
     */
    public function several(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $statement = $this->db->query(
            self::SELECT . ' WHERE n.id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY n.created_at, n.id'
        );

        return $statement === false ? [] : $statement->fetchAll();
    }

    /** Read notifications older than `$readDays`, and any older than `$anyDays`, cleared out. Returns how many. */
    public function prune(int $readDays = 180, int $anyDays = 365): int
    {
        $statement = $this->db->prepare(
            'DELETE FROM notifications
             WHERE (read_at IS NOT NULL AND created_at < NOW() - INTERVAL ' . max(1, $readDays) . ' DAY)
                OR created_at < NOW() - INTERVAL ' . max(1, $anyDays) . ' DAY'
        );
        $statement->execute();

        return $statement->rowCount();
    }

    // -----------------------------------------------------------------------
    // Followers
    // -----------------------------------------------------------------------

    /**
     * Everybody who hears about a ticket: its watchers, its reporter and its
     * assignee — but nobody who muted it.
     */
    public function audience(int $ticketId): array
    {
        $statement = $this->db->prepare(
            'SELECT u.* FROM users u
             WHERE u.is_active = 1 AND (
                 u.id IN (SELECT user_id FROM ticket_watchers WHERE ticket_id = :a)
                 OR u.id = (SELECT reporter_id FROM tickets WHERE id = :b)
                 OR u.id = (SELECT assignee_id FROM tickets WHERE id = :c)
             )
             AND u.id NOT IN (SELECT user_id FROM ticket_mutes WHERE ticket_id = :d)'
        );
        $statement->execute(['a' => $ticketId, 'b' => $ticketId, 'c' => $ticketId, 'd' => $ticketId]);

        return $statement->fetchAll();
    }

    /**
     * Whether somebody hears about a ticket's changes: they follow it, or it
     * is theirs — reported by them or given to them — and they did not mute
     * it.
     */
    public function isWatching(int $ticketId, int $userId): bool
    {
        $statement = $this->db->prepare(
            'SELECT (EXISTS (SELECT 1 FROM ticket_watchers WHERE ticket_id = :t AND user_id = :u)
                     OR EXISTS (SELECT 1 FROM tickets WHERE id = :t2 AND (reporter_id = :u2 OR assignee_id = :u3)))
                AND NOT EXISTS (SELECT 1 FROM ticket_mutes WHERE ticket_id = :t3 AND user_id = :u4)'
        );
        $statement->execute(['t' => $ticketId, 'u' => $userId, 't2' => $ticketId, 'u2' => $userId, 'u3' => $userId, 't3' => $ticketId, 'u4' => $userId]);

        return (bool) $statement->fetchColumn();
    }

    /** Following a ticket — which unmutes it, too. */
    public function watch(int $ticketId, int $userId): void
    {
        $this->db->prepare('INSERT IGNORE INTO ticket_watchers (ticket_id, user_id) VALUES (:t, :u)')
            ->execute(['t' => $ticketId, 'u' => $userId]);
        $this->db->prepare('DELETE FROM ticket_mutes WHERE ticket_id = :t AND user_id = :u')
            ->execute(['t' => $ticketId, 'u' => $userId]);
    }

    /**
     * Not following it any more. Its reporter and its assignee hear about it
     * without following it, so for them it is muted as well.
     */
    public function unwatch(int $ticketId, int $userId): void
    {
        $this->db->prepare('DELETE FROM ticket_watchers WHERE ticket_id = :t AND user_id = :u')
            ->execute(['t' => $ticketId, 'u' => $userId]);
        $this->db->prepare(
            'INSERT IGNORE INTO ticket_mutes (ticket_id, user_id)
             SELECT id, :u FROM tickets WHERE id = :t AND (reporter_id = :u2 OR assignee_id = :u3)'
        )->execute(['t' => $ticketId, 'u' => $userId, 'u2' => $userId, 'u3' => $userId]);
    }

    public function isMuted(int $ticketId, int $userId): bool
    {
        $statement = $this->db->prepare('SELECT EXISTS (SELECT 1 FROM ticket_mutes WHERE ticket_id = :t AND user_id = :u)');
        $statement->execute(['t' => $ticketId, 'u' => $userId]);

        return (bool) $statement->fetchColumn();
    }

    public function watcherCount(int $ticketId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM ticket_watchers WHERE ticket_id = :t');
        $statement->execute(['t' => $ticketId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{unread?: bool, kind?: ?string, project_id?: ?int} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function narrowed(int $userId, array $filters): array
    {
        $where = ['n.user_id = :user'];
        $parameters = ['user' => $userId];

        if (!empty($filters['unread'])) {
            $where[] = 'n.read_at IS NULL';
        }

        if (!empty($filters['kind'])) {
            $where[] = self::KIND . ' = :told_kind';
            $parameters['told_kind'] = (string) $filters['kind'];
        }

        if (!empty($filters['project_id'])) {
            $where[] = 'p.id = :project';
            $parameters['project'] = (int) $filters['project_id'];
        }

        return [implode(' AND ', $where) . Access::sql('p.id'), $parameters];
    }
}
