<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * Personal access tokens, for the API.
 *
 * A token is "ct_" and forty hex characters — long enough that guessing one
 * is not a plan, and recognisable in a leaked file or a commit, which is
 * where tokens end up. Only its SHA-256 is stored: a token is random enough
 * that a slow hash would buy nothing, and a fast one lets every API request
 * be one indexed lookup.
 */
class ApiTokenRepository
{
    public const PREFIX = 'ct_';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** Makes a token and returns it — the only time it can be read. */
    public function create(int $userId, string $name, ?string $expiresOn = null): string
    {
        $token = self::PREFIX . bin2hex(random_bytes(20));

        $this->db->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, prefix, expires_on) VALUES (:user, :name, :hash, :prefix, :expires)'
        )->execute([
            'user' => $userId,
            'name' => mb_substr(trim($name), 0, 80),
            'hash' => hash('sha256', $token),
            'prefix' => substr($token, 0, 10),
            'expires' => $expiresOn ?: null,
        ]);

        return $token;
    }

    /** A person's tokens, newest first — never the tokens themselves. */
    public function forUser(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, name, prefix, last_used_at, expires_on, created_at FROM api_tokens WHERE user_id = :user ORDER BY id DESC'
        );
        $statement->execute(['user' => $userId]);

        return $statement->fetchAll();
    }

    /** Revokes one of a person's own tokens. False when there was none. */
    public function revoke(int $id, int $userId): bool
    {
        $statement = $this->db->prepare('DELETE FROM api_tokens WHERE id = :id AND user_id = :user');
        $statement->execute(['id' => $id, 'user' => $userId]);

        return $statement->rowCount() > 0;
    }

    /**
     * The active person a token belongs to, or null for a token that is
     * unknown, expired, or belongs to somebody deactivated.
     */
    public function userFor(string $token): ?array
    {
        if (!str_starts_with($token, self::PREFIX) || strlen($token) !== strlen(self::PREFIX) + 40) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT t.id AS token_id, t.last_used_at AS token_used_at, u.*
             FROM api_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :hash AND (t.expires_on IS NULL OR t.expires_on >= CURDATE()) AND u.is_active = 1'
        );
        $statement->execute(['hash' => hash('sha256', $token)]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        // Written at most once a minute: a script polling every second does
        // not need a write for each request to say when it was last here.
        if ($row['token_used_at'] === null || strtotime((string) $row['token_used_at']) < time() - 60) {
            $this->db->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = :id')->execute(['id' => $row['token_id']]);
        }

        unset($row['token_id'], $row['token_used_at']);

        return $row;
    }
}
