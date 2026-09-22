<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * The people who can sign in, and who tickets are assigned to.
 *
 * Users are never deleted, only deactivated: a ticket carries who reported it
 * and who it is assigned to, and a worklog carries whose hours those were.
 * Deleting the row would either take that history with it or leave it pointing
 * at nothing.
 */
class UserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::get();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE email = :email');
        $statement->execute(['email' => mb_strtolower(trim($email))]);

        return $statement->fetch() ?: null;
    }

    /** Everyone, for the assignee lists. Inactive people are kept out of those. */
    public function active(): array
    {
        return $this->db->query(
            'SELECT * FROM users WHERE is_active = 1 ORDER BY name'
        )->fetchAll();
    }

    public function all(): array
    {
        return $this->db->query('SELECT * FROM users ORDER BY is_active DESC, name')->fetchAll();
    }

    public function create(string $name, string $email, string $password, string $role = 'member'): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO users (name, email, password_hash, role, created_at, updated_at)
             VALUES (:name, :email, :hash, :role, NOW(), NOW())'
        );

        $statement->execute([
            'name' => trim($name),
            'email' => mb_strtolower(trim($email)),
            // PASSWORD_DEFAULT rather than a named algorithm, so a PHP upgrade
            // that brings a better one is picked up without an edit here.
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Whether an address is already somebody's, ignoring one id — which is what
     * an edit form needs, since a person keeping their own address is not a
     * clash.
     */
    public function emailTaken(string $email, ?int $exceptId = null): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM users WHERE email = :email AND (:except IS NULL OR id <> :except2)'
        );

        $statement->execute([
            'email' => mb_strtolower(trim($email)),
            'except' => $exceptId,
            'except2' => $exceptId,
        ]);

        return ((int) $statement->fetchColumn()) > 0;
    }

    public function update(int $id, string $name, string $email, string $role, bool $isActive): void
    {
        $statement = $this->db->prepare(
            'UPDATE users SET name = :name, email = :email, role = :role, is_active = :active WHERE id = :id'
        );

        $statement->execute([
            'name' => trim($name),
            'email' => mb_strtolower(trim($email)),
            'role' => $role === 'admin' ? 'admin' : 'member',
            'active' => $isActive ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * Sets a new password.
     *
     * Every session of that person's is left alone deliberately: this is used to
     * hand somebody a password they have lost, not to lock them out of the
     * browser they are sitting at.
     */
    public function setPassword(int $id, string $password): void
    {
        $statement = $this->db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $statement->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $id]);
    }

    /**
     * A password to hand over: readable, from an alphabet with no characters
     * that can be misread, and long enough that it does not matter which twelve
     * they are.
     */
    public static function newPassword(int $length = 12): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    /** How much each person has open and logged, for the people page. */
    public function withActivity(): array
    {
        return $this->db->query(
            'SELECT u.*,
                    (SELECT COUNT(*) FROM tickets t WHERE t.assignee_id = u.id AND t.status <> \'done\') AS open_tickets,
                    (SELECT COALESCE(SUM(w.minutes), 0) FROM worklogs w
                     WHERE w.user_id = u.id AND w.work_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS minutes_30d
             FROM users u
             ORDER BY u.is_active DESC, u.name'
        )->fetchAll();
    }

    public function touchLastLogin(int $id): void
    {
        $statement = $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
