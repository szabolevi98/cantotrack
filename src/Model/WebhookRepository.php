<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/** Webhooks, and the log of what was sent to each. */
class WebhookRepository
{
    /** What a webhook can be told about. "*" is all of them. */
    public const EVENTS = [
        'ticket.created', 'ticket.changed', 'ticket.status', 'ticket.commented', 'ticket.logged',
        'ticket.attached', 'ticket.detached', 'ticket.linked', 'ticket.unlinked', 'ticket.sprint', 'ticket.commit',
    ];

    /** How long to wait before each new try of a failed message, in minutes. */
    public const BACKOFF = [1, 5, 30, 120, 720];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public function all(): array
    {
        $statement = $this->db->prepare(
            'SELECT w.*, p.code AS project_code,
                    (SELECT d.state FROM webhook_deliveries d WHERE d.webhook_id = w.id ORDER BY d.id DESC LIMIT 1) AS last_state,
                    (SELECT d.created_at FROM webhook_deliveries d WHERE d.webhook_id = w.id ORDER BY d.id DESC LIMIT 1) AS last_at
             FROM webhooks w LEFT JOIN projects p ON p.id = w.project_id
             ORDER BY w.name'
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT w.*, p.code AS project_code FROM webhooks w LEFT JOIN projects p ON p.id = w.project_id WHERE w.id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** The active webhooks that want to hear about an event in a project. */
    public function listeningTo(string $event, int $projectId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM webhooks WHERE is_active = 1 AND (project_id IS NULL OR project_id = :project)'
        );
        $statement->execute(['project' => $projectId]);

        return array_values(array_filter(
            $statement->fetchAll(),
            static fn(array $hook): bool => self::wants($hook, $event)
        ));
    }

    public static function wants(array $hook, string $event): bool
    {
        $events = array_map('trim', explode(',', (string) $hook['events']));

        return in_array('*', $events, true) || in_array($event, $events, true) || $event === 'ping';
    }

    /** @param list<string> $events */
    public function create(string $name, string $url, array $events, ?int $projectId, ?int $createdBy): int
    {
        $this->db->prepare(
            'INSERT INTO webhooks (name, url, secret, events, project_id, created_by)
             VALUES (:name, :url, :secret, :events, :project, :by)'
        )->execute([
            'name' => mb_substr(trim($name), 0, 80),
            'url' => $url,
            'secret' => bin2hex(random_bytes(32)),
            'events' => self::eventList($events),
            'project' => $projectId,
            'by' => $createdBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param list<string> $events */
    public function update(int $id, string $name, string $url, array $events, ?int $projectId, bool $active): void
    {
        $this->db->prepare(
            'UPDATE webhooks SET name = :name, url = :url, events = :events, project_id = :project, is_active = :active WHERE id = :id'
        )->execute([
            'name' => mb_substr(trim($name), 0, 80),
            'url' => $url,
            'events' => self::eventList($events),
            'project' => $projectId,
            'active' => $active ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function newSecret(int $id): void
    {
        $this->db->prepare('UPDATE webhooks SET secret = :secret WHERE id = :id')
            ->execute(['secret' => bin2hex(random_bytes(32)), 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM webhooks WHERE id = :id')->execute(['id' => $id]);
    }

    /** @param list<string> $events */
    private static function eventList(array $events): string
    {
        $events = array_values(array_intersect(self::EVENTS, $events));

        return $events === [] || count($events) === count(self::EVENTS) ? '*' : implode(',', $events);
    }

    // -----------------------------------------------------------------------
    // Deliveries
    // -----------------------------------------------------------------------

    public function queue(int $webhookId, string $event, string $payload): int
    {
        $this->db->prepare('INSERT INTO webhook_deliveries (webhook_id, event, payload) VALUES (:hook, :event, :payload)')
            ->execute(['hook' => $webhookId, 'event' => $event, 'payload' => $payload]);

        return (int) $this->db->lastInsertId();
    }

    public function delivery(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM webhook_deliveries WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function deliveries(int $webhookId, int $limit = 30): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM webhook_deliveries WHERE webhook_id = :hook ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $statement->execute(['hook' => $webhookId]);

        return $statement->fetchAll();
    }

    /** @return list<int> the pending deliveries whose turn it is */
    public function due(int $limit = 50): array
    {
        $statement = $this->db->prepare(
            'SELECT id FROM webhook_deliveries WHERE state = \'pending\' AND next_attempt_at <= NOW() ORDER BY id LIMIT ' . max(1, $limit)
        );
        $statement->execute();

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param array{status: ?int, body: ?string, error: ?string, ms: int} $result */
    public function recordAttempt(int $id, bool $delivered, array $result): void
    {
        $delivery = $this->delivery($id);

        if ($delivery === null) {
            return;
        }

        $attempts = (int) $delivery['attempts'] + 1;
        $wait = self::BACKOFF[$attempts - 1] ?? null;

        $this->db->prepare(
            'UPDATE webhook_deliveries SET
                 state = :state, attempts = :attempts,
                 next_attempt_at = NOW() + INTERVAL :wait MINUTE,
                 response_status = :status, response_body = :body, error = :error, duration_ms = :ms,
                 delivered_at = IF(:delivered = 1, NOW(), delivered_at)
             WHERE id = :id'
        )->execute([
            'state' => $delivered ? 'delivered' : ($wait === null ? 'failed' : 'pending'),
            'attempts' => $attempts,
            'wait' => $wait ?? 0,
            'status' => $result['status'],
            'body' => $result['body'] === null ? null : mb_substr($result['body'], 0, 1000),
            'error' => $result['error'] === null ? null : mb_substr($result['error'], 0, 255),
            'ms' => $result['ms'],
            'delivered' => $delivered ? 1 : 0,
            'id' => $id,
        ]);
    }

    /** Sends a message again from the start, as if new. */
    public function retry(int $id): void
    {
        $this->db->prepare(
            'UPDATE webhook_deliveries SET state = \'pending\', attempts = 0, next_attempt_at = NOW() WHERE id = :id'
        )->execute(['id' => $id]);
    }

    /** Delivered messages older than a month go; the failures stay until looked at. */
    public function prune(): int
    {
        $statement = $this->db->prepare(
            'DELETE FROM webhook_deliveries WHERE state = \'delivered\' AND created_at < NOW() - INTERVAL 30 DAY'
        );
        $statement->execute();

        return $statement->rowCount();
    }
}
