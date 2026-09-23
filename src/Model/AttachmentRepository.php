<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/** The files on tickets — the rows about them; the bytes are in var/uploads. */
class AttachmentRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public function forTicket(int $ticketId): array
    {
        $statement = $this->db->prepare(
            'SELECT a.*, u.name AS user_name FROM attachments a JOIN users u ON u.id = a.user_id
             WHERE a.ticket_id = :ticket ORDER BY a.created_at, a.id'
        );
        $statement->execute(['ticket' => $ticketId]);

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM attachments WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $this->db->prepare(
            'INSERT INTO attachments (ticket_id, user_id, original_name, stored_path, mime, size, width, height)
             VALUES (:ticket, :user, :name, :path, :mime, :size, :width, :height)'
        )->execute([
            'ticket' => $data['ticket_id'],
            'user' => $data['user_id'],
            'name' => $data['original_name'],
            'path' => $data['stored_path'],
            'mime' => $data['mime'],
            'size' => $data['size'],
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Where the files of some tickets are stored — read before the tickets are
     * deleted, because the rows go with them and the files would stay behind
     * with nothing pointing at them.
     *
     * @return list<string>
     */
    public function pathsForTickets(string $where, array $parameters): array
    {
        $statement = $this->db->prepare(
            'SELECT a.stored_path FROM attachments a JOIN tickets t ON t.id = a.ticket_id WHERE ' . $where
        );
        $statement->execute($parameters);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }
}
