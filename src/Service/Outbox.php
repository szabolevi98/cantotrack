<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Mailer;
use PDO;

/**
 * The email waiting to go out — see the 0044 migration.
 *
 * A message is put here by Mailer::send and sent by bin/outbox.php, from
 * cron, within the minute. One the mail server would not take is tried
 * again, each time a while later — a minute, five, a quarter of an hour, an
 * hour, four — and after that it stays failed, with the reason, on the
 * administrators' Email page, where it can be sent again by hand.
 */
final class Outbox
{
    /** Minutes to wait after each failed try; one more than there are and it has failed. */
    public const BACKOFF = [1, 5, 15, 60, 240];

    /** A run that took a message and never said how it went has died; its messages go back. */
    private const STALE_MINUTES = 10;

    private PDO $db;

    /** @var callable(string, string, string, string): ?string */
    private $deliver;

    /**
     * @param (callable(string, string, string, string): ?string)|null $deliver how a message is
     *        sent — Mailer::deliver unless a test says otherwise; null when it went, else why not
     */
    public function __construct(?PDO $db = null, ?callable $deliver = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
        $this->deliver = $deliver ?? Mailer::deliver(...);
    }

    public function add(string $toAddress, string $toName, string $subject, string $body, string $kind = 'mail', ?int $notificationId = null): int
    {
        $this->db->prepare(
            'INSERT INTO outbox (to_address, to_name, subject, body, kind, notification_id)
             VALUES (:address, :name, :subject, :body, :kind, :notification)'
        )->execute([
            'address' => mb_substr($toAddress, 0, 255),
            'name' => mb_substr($toName, 0, 160),
            'subject' => mb_substr($subject, 0, 255),
            'body' => $body,
            'kind' => mb_substr($kind, 0, 20),
            'notification' => $notificationId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Sends what is due, the oldest first, at most `$limit` of it. Each
     * message is taken before it is sent, so two runs at once never send
     * the same one twice. Returns how many went.
     */
    public function sendDue(int $limit = 100): int
    {
        $this->db->exec(
            "UPDATE outbox SET state = 'waiting' WHERE state = 'sending' AND taken_at < NOW() - INTERVAL " . self::STALE_MINUTES . ' MINUTE'
        );

        $due = $this->db->query(
            "SELECT id FROM outbox WHERE state = 'waiting' AND next_attempt_at <= NOW() ORDER BY next_attempt_at, id LIMIT " . max(1, $limit)
        );
        $ids = $due === false ? [] : array_map('intval', $due->fetchAll(PDO::FETCH_COLUMN));
        $sent = 0;

        foreach ($ids as $id) {
            $sent += $this->sendOne($id) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * Sends one waiting message now. It is taken first, so that a run of
     * the sender at the same moment leaves it alone. Returns whether it went.
     */
    public function sendOne(int $id): bool
    {
        $take = $this->db->prepare("UPDATE outbox SET state = 'sending', taken_at = NOW() WHERE id = :id AND state = 'waiting'");
        $take->execute(['id' => $id]);

        if ($take->rowCount() === 0) {
            return false;
        }

        $read = $this->db->prepare('SELECT * FROM outbox WHERE id = :id');
        $read->execute(['id' => $id]);
        $message = (array) $read->fetch();
        $error = ($this->deliver)((string) $message['to_address'], (string) $message['to_name'], (string) $message['subject'], (string) $message['body']);

        if ($error !== null) {
            $this->failed($id, (int) $message['attempts'], $error);

            return false;
        }

        $this->sent($message);

        return true;
    }

    /** A failed try: when the next one is, or that there will be none. */
    public function failed(int $id, int $attemptsBefore, string $error): void
    {
        $attempts = $attemptsBefore + 1;
        $wait = self::BACKOFF[$attempts - 1] ?? null;

        $this->db->prepare(
            'UPDATE outbox SET state = :state, attempts = :attempts, last_error = :error,
                    next_attempt_at = NOW() + INTERVAL :wait MINUTE, taken_at = NULL WHERE id = :id'
        )->execute([
            'state' => $wait === null ? 'failed' : 'waiting',
            'attempts' => $attempts,
            'error' => mb_substr($error, 0, 500),
            'wait' => $wait ?? 0,
            'id' => $id,
        ]);
    }

    /** A failed one sent again by hand: due now, with its tries counted afresh. */
    public function retry(int $id): bool
    {
        $statement = $this->db->prepare(
            "UPDATE outbox SET state = 'waiting', attempts = 0, next_attempt_at = NOW() WHERE id = :id AND state IN ('failed', 'waiting')"
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    /** @return array{waiting: int, failed: int, sent_today: int} */
    public function counts(): array
    {
        $row = $this->db->query(
            "SELECT COALESCE(SUM(state IN ('waiting', 'sending')), 0) AS waiting, COALESCE(SUM(state = 'failed'), 0) AS failed,
                    COALESCE(SUM(state = 'sent' AND sent_at >= CURDATE()), 0) AS sent_today FROM outbox"
        );
        $counts = $row === false ? [] : (array) $row->fetch();

        return ['waiting' => (int) ($counts['waiting'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0), 'sent_today' => (int) ($counts['sent_today'] ?? 0)];
    }

    /**
     * The messages in one state, the latest first.
     *
     * @return list<array<string, mixed>>
     */
    public function messages(string $state, int $limit = 50): array
    {
        $states = $state === 'waiting' ? "'waiting', 'sending'" : $this->db->quote($state);
        $order = $state === 'sent' ? 'sent_at DESC' : 'created_at DESC';
        $statement = $this->db->query(
            'SELECT id, to_address, to_name, subject, kind, state, attempts, next_attempt_at, last_error, created_at, sent_at
             FROM outbox WHERE state IN (' . $states . ') ORDER BY ' . $order . ', id DESC LIMIT ' . max(1, $limit)
        );

        return $statement === false ? [] : array_values($statement->fetchAll());
    }

    /** Sent messages older than a month, cleared out. Returns how many. */
    public function prune(): int
    {
        return (int) $this->db->exec("DELETE FROM outbox WHERE state = 'sent' AND sent_at < NOW() - INTERVAL 30 DAY");
    }

    private function sent(array $message): void
    {
        $this->db->prepare("UPDATE outbox SET state = 'sent', sent_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = :id")
            ->execute(['id' => $message['id']]);

        // The notification it was sent for, and every other one it carried.
        if ($message['notification_id'] !== null) {
            (new \CantoTrack\Model\NotificationRepository($this->db))->markEmailed((int) $message['notification_id']);
        }
    }
}
