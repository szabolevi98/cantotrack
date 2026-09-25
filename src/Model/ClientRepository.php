<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Sort;
use PDO;

/** Who the work is for. Made the first time a project names them. */
class ClientRepository
{
    /** The clients page's columns that sort it, and the SQL for each. */
    public const SORTS = [
        'name' => 'c.name',
        'contact' => 'c.contact_name',
        'projects' => 'project_count',
        'month' => 'month_minutes',
        'worth' => 'month_amount',
        'last_billed' => 'last_issued',
    ];

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

    /**
     * Every client with what the administrators' list says about each: its
     * projects, its billable hours this month and what they are worth, and
     * its last statement.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(string $monthFrom, string $monthTo): array
    {
        $statement = $this->db->prepare(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.id) AS project_count,
                    (SELECT COALESCE(SUM(w.minutes), 0) FROM worklogs w JOIN tickets t ON t.id = w.ticket_id JOIN projects p ON p.id = t.project_id
                      WHERE p.client_id = c.id AND w.billable = 1 AND w.work_date BETWEEN :from AND :to) AS month_minutes,
                    (SELECT COALESCE(SUM(' . \CantoTrack\Service\Budget::WORTH . '), 0) FROM worklogs w JOIN tickets t ON t.id = w.ticket_id
                      JOIN projects p ON p.id = t.project_id JOIN users u ON u.id = w.user_id
                      WHERE p.client_id = c.id AND w.work_date BETWEEN :from2 AND :to2) AS month_amount,
                    (SELECT MAX(s.issued_at) FROM statements s WHERE s.client_id = c.id AND s.state = \'issued\') AS last_issued
             FROM clients c ORDER BY ' . Sort::orderBy(self::SORTS, 'c.name')
        );
        $statement->execute(['from' => $monthFrom, 'to' => $monthTo, 'from2' => $monthFrom, 'to2' => $monthTo]);

        return array_values($statement->fetchAll());
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM clients WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** @param array{name: string, billing_address: ?string, tax_number: ?string, contact_name: ?string, contact_email: ?string, note: ?string} $data */
    public function update(int $id, array $data): void
    {
        $this->db->prepare(
            'UPDATE clients SET name = :name, billing_address = :billing_address, tax_number = :tax_number,
                    contact_name = :contact_name, contact_email = :contact_email, note = :note WHERE id = :id'
        )->execute($data + ['id' => $id]);
    }

    public function nameTaken(string $name, int $exceptId): bool
    {
        $statement = $this->db->prepare('SELECT EXISTS (SELECT 1 FROM clients WHERE name = :name AND id <> :id)');
        $statement->execute(['name' => $name, 'id' => $exceptId]);

        return (bool) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> its projects, the open ones first */
    public function projects(int $id): array
    {
        $statement = $this->db->prepare('SELECT id, code, name, is_archived, hourly_rate FROM projects WHERE client_id = :id ORDER BY is_archived, name');
        $statement->execute(['id' => $id]);

        return array_values($statement->fetchAll());
    }

    /**
     * Its billable hours and their worth in a span, and how much of that is
     * on no statement yet.
     *
     * @return array{minutes: int, amount: float, unbilled: int}
     */
    public function hours(int $id, string $from, string $to): array
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(w.minutes), 0) AS minutes, COALESCE(SUM(' . \CantoTrack\Service\Budget::WORTH . '), 0) AS amount,
                    COALESCE(SUM(CASE WHEN w.statement_id IS NULL THEN w.minutes ELSE 0 END), 0) AS unbilled
             FROM worklogs w JOIN tickets t ON t.id = w.ticket_id JOIN projects p ON p.id = t.project_id JOIN users u ON u.id = w.user_id
             WHERE p.client_id = :id AND w.billable = 1 AND w.work_date BETWEEN :from AND :to'
        );
        $statement->execute(['id' => $id, 'from' => $from, 'to' => $to]);
        $row = (array) $statement->fetch();

        return ['minutes' => (int) $row['minutes'], 'amount' => round((float) $row['amount'], 2), 'unbilled' => (int) $row['unbilled']];
    }

    /** Whether anything still names it: a project, or a statement. */
    public function inUse(int $id): bool
    {
        $statement = $this->db->prepare(
            'SELECT EXISTS (SELECT 1 FROM projects WHERE client_id = :a) OR EXISTS (SELECT 1 FROM statements WHERE client_id = :b)'
        );
        $statement->execute(['a' => $id, 'b' => $id]);

        return (bool) $statement->fetchColumn();
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM clients WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * What a statement is addressed to: the name, the address and the tax
     * number, one a line — or null when there is no address to add to the name.
     */
    public static function billTo(array $client): ?string
    {
        if (trim((string) ($client['billing_address'] ?? '')) === '' && trim((string) ($client['tax_number'] ?? '')) === '') {
            return null;
        }

        return implode("\n", array_filter([
            (string) $client['name'],
            trim((string) ($client['billing_address'] ?? '')),
            trim((string) ($client['tax_number'] ?? '')) === '' ? '' : __('Tax number: {number}', ['number' => trim((string) $client['tax_number'])]),
        ], static fn(string $line): bool => $line !== ''));
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
