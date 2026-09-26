<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * The API's Idempotency-Key: a request written down under the key it came
 * with, and its answer, so that the same request sent again is answered
 * again rather than done again. See the 0051 migration.
 */
class IdempotencyRepository
{
    /** How long a key is remembered. */
    public const KEEP_HOURS = 24;

    /**
     * How long a request may be "still being worked on" before its key is
     * taken to be abandoned — a process that died without letting it go.
     * No request of this API takes anywhere near a minute.
     */
    private const ABANDONED_SECONDS = 60;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * Claims a key for a request.
     *
     * - `new`: nobody used it; it is this request's now, as `id`.
     * - `replay`: the same request was answered already; `row` has the answer.
     * - `busy`: the same request is being worked on right now.
     * - `mismatch`: the key was used for a different request.
     *
     * @return array{state: 'new'|'replay'|'busy'|'mismatch', id?: int, row?: array<string, mixed>}
     */
    public function claim(int $userId, string $key, string $fingerprint, bool $retry = true): array
    {
        // A key older than a day is forgotten: the same one can start over.
        $this->db->prepare(
            'DELETE FROM api_idempotency_keys WHERE user_id = :user AND idem_key = :key AND created_at < NOW() - INTERVAL ' . self::KEEP_HOURS . ' HOUR'
        )->execute(['user' => $userId, 'key' => $key]);

        $insert = $this->db->prepare(
            'INSERT IGNORE INTO api_idempotency_keys (user_id, idem_key, fingerprint) VALUES (:user, :key, :fingerprint)'
        );
        $insert->execute(['user' => $userId, 'key' => $key, 'fingerprint' => $fingerprint]);

        if ($insert->rowCount() === 1) {
            return ['state' => 'new', 'id' => (int) $this->db->lastInsertId()];
        }

        $statement = $this->db->prepare(
            'SELECT *, created_at < NOW() - INTERVAL ' . self::ABANDONED_SECONDS . ' SECOND AS abandoned
             FROM api_idempotency_keys WHERE user_id = :user AND idem_key = :key'
        );
        $statement->execute(['user' => $userId, 'key' => $key]);
        $row = $statement->fetch();

        // Gone between the two statements: its request failed and let it go.
        if ($row === false) {
            return $retry ? $this->claim($userId, $key, $fingerprint, false) : ['state' => 'busy'];
        }

        if (!hash_equals((string) $row['fingerprint'], $fingerprint)) {
            return ['state' => 'mismatch', 'row' => $row];
        }

        if ($row['status'] !== null) {
            return ['state' => 'replay', 'row' => $row];
        }

        if ((int) $row['abandoned'] === 1) {
            $take = $this->db->prepare(
                'UPDATE api_idempotency_keys SET created_at = NOW() WHERE id = :id AND status IS NULL AND created_at < NOW() - INTERVAL ' . self::ABANDONED_SECONDS . ' SECOND'
            );
            $take->execute(['id' => $row['id']]);

            if ($take->rowCount() === 1) {
                return ['state' => 'new', 'id' => (int) $row['id']];
            }
        }

        return ['state' => 'busy', 'row' => $row];
    }

    /** Writes down the answer a claimed request got. */
    public function complete(int $id, int $status, string $body, ?string $location): void
    {
        $this->db->prepare(
            'UPDATE api_idempotency_keys SET status = :status, body = :body, location = :location WHERE id = :id'
        )->execute(['id' => $id, 'status' => $status, 'body' => $body, 'location' => $location === null ? null : mb_substr($location, 0, 500)]);
    }

    /** Lets a key go again: its request failed, and did nothing. */
    public function release(int $id): void
    {
        $this->db->prepare('DELETE FROM api_idempotency_keys WHERE id = :id AND status IS NULL')->execute(['id' => $id]);
    }

    /** Forgets the keys older than a day. */
    public function prune(): int
    {
        $statement = $this->db->prepare('DELETE FROM api_idempotency_keys WHERE created_at < NOW() - INTERVAL ' . self::KEEP_HOURS . ' HOUR');
        $statement->execute();

        return $statement->rowCount();
    }
}
