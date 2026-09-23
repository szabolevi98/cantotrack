<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\ValidationError;
use PDO;

/** The kinds of work an hour can be: the administrators' short list. */
class WorkTypeRepository
{
    /** @var list<array<string, mixed>>|null the active ones, read once per request */
    private static ?array $active = null;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** Every type, the retired ones last, with how many hours each has. */
    public function all(): array
    {
        $statement = $this->db->prepare(
            'SELECT wt.*, (SELECT COALESCE(SUM(w.minutes), 0) FROM worklogs w WHERE w.work_type_id = wt.id) AS minutes
             FROM work_types wt ORDER BY wt.is_active DESC, wt.name'
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> the ones a form offers */
    public static function active(): array
    {
        if (self::$active === null) {
            $statement = DatabaseConnection::get()->prepare('SELECT id, name FROM work_types WHERE is_active = 1 ORDER BY name');
            $statement->execute();
            self::$active = array_values($statement->fetchAll());
        }

        return self::$active;
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM work_types WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** A type by its id or its name, as a form or the API gives it — only an active one. */
    public function resolve(mixed $given): ?array
    {
        $given = trim((string) $given);

        if ($given === '') {
            return null;
        }

        $statement = $this->db->prepare(
            ctype_digit($given)
                ? 'SELECT * FROM work_types WHERE id = :v AND is_active = 1'
                : 'SELECT * FROM work_types WHERE name = :v AND is_active = 1'
        );
        $statement->execute(['v' => $given]);

        return $statement->fetch() ?: null;
    }

    /** @throws ValidationError */
    public function create(string $name): int
    {
        $name = $this->name($name);
        $this->db->prepare('INSERT INTO work_types (name) VALUES (:name)')->execute(['name' => $name]);
        self::$active = null;

        return (int) $this->db->lastInsertId();
    }

    /** @throws ValidationError */
    public function update(int $id, string $name, bool $active): void
    {
        $this->db->prepare('UPDATE work_types SET name = :name, is_active = :active WHERE id = :id')
            ->execute(['name' => $this->name($name, $id), 'active' => $active ? 1 : 0, 'id' => $id]);
        self::$active = null;
    }

    /** @throws ValidationError */
    private function name(string $name, ?int $exceptId = null): string
    {
        $name = mb_substr(trim($name), 0, 60);

        if ($name === '') {
            throw new ValidationError(__('A work type needs a name.'));
        }

        $statement = $this->db->prepare('SELECT id FROM work_types WHERE name = :name');
        $statement->execute(['name' => $name]);
        $taken = $statement->fetchColumn();

        if ($taken !== false && (int) $taken !== $exceptId) {
            throw new ValidationError(__('There is a work type called {name} already.', ['name' => $name]));
        }

        return $name;
    }
}
