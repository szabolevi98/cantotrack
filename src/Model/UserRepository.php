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

    public function touchLastLogin(int $id): void
    {
        $statement = $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
