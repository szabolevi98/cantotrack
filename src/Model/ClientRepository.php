<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/** Who the work is for. Made the first time a project names them. */
class ClientRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public function all(): array
    {
        $statement = $this->db->prepare(
            'SELECT c.*, COUNT(p.id) AS project_count FROM clients c LEFT JOIN projects p ON p.client_id = c.id
             GROUP BY c.id ORDER BY c.name'
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    /** The client of that name, made if there is none — or null for an empty name. */
    public function findOrCreate(string $name): ?int
    {
        $name = mb_substr(trim($name), 0, 120);

        if ($name === '') {
            return null;
        }

        $statement = $this->db->prepare('SELECT id FROM clients WHERE name = :name');
        $statement->execute(['name' => $name]);
        $id = $statement->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $this->db->prepare('INSERT INTO clients (name) VALUES (:name)')->execute(['name' => $name]);

        return (int) $this->db->lastInsertId();
    }
}
