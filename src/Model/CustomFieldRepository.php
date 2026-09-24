<?php

namespace CantoTrack\Model;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * A project's own fields, and the values tickets have in them.
 */
class CustomFieldRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** @return list<array<string, mixed>> a project's fields, in their order */
    public function forProject(int $projectId): array
    {
        $statement = $this->db->prepare('SELECT * FROM custom_fields WHERE project_id = :project ORDER BY position, id');
        $statement->execute(['project' => $projectId]);

        return array_values(array_map([self::class, 'withChoices'], $statement->fetchAll()));
    }

    /** @return list<array<string, mixed>> every field of every project the person may see */
    public function visible(): array
    {
        $statement = $this->db->query('SELECT * FROM custom_fields WHERE 1 = 1' . Access::sql('project_id') . ' ORDER BY project_id, position, id');

        return $statement === false ? [] : array_values(array_map([self::class, 'withChoices'], $statement->fetchAll()));
    }

    /**
     * What each field name means to the query language — its kind — among
     * the fields the person may see. A name used by two projects takes the
     * kind it has in the first.
     *
     * @return array<string, string> lower-case name => kind
     */
    public function kindsByName(): array
    {
        $kinds = [];

        foreach ($this->visible() as $field) {
            $kinds[mb_strtolower((string) $field['name'])] ??= (string) $field['kind'];
        }

        return $kinds;
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM custom_fields WHERE id = :id' . Access::sql('project_id'));
        $statement->execute(['id' => $id]);
        $field = $statement->fetch();

        return $field === false ? null : self::withChoices($field);
    }

    public function nameTaken(int $projectId, string $name, ?int $exceptId = null): bool
    {
        $statement = $this->db->prepare('SELECT id FROM custom_fields WHERE project_id = :project AND name = :name');
        $statement->execute(['project' => $projectId, 'name' => $name]);
        $id = $statement->fetchColumn();

        return $id !== false && (int) $id !== $exceptId;
    }

    public function create(int $projectId, string $name, string $kind, ?string $options, bool $required): int
    {
        $last = $this->db->prepare('SELECT COALESCE(MAX(position), 0) FROM custom_fields WHERE project_id = :project');
        $last->execute(['project' => $projectId]);

        $this->db->prepare(
            'INSERT INTO custom_fields (project_id, name, kind, options, is_required, position) VALUES (:project, :name, :kind, :options, :required, :position)'
        )->execute(['project' => $projectId, 'name' => $name, 'kind' => $kind, 'options' => $options, 'required' => $required ? 1 : 0, 'position' => (int) $last->fetchColumn() + 1]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, string $name, ?string $options, bool $required): void
    {
        $this->db->prepare('UPDATE custom_fields SET name = :name, options = :options, is_required = :required WHERE id = :id')
            ->execute(['name' => $name, 'options' => $options, 'required' => $required ? 1 : 0, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM custom_fields WHERE id = :id')->execute(['id' => $id]);
    }

    /** One place up or down among the project's fields. */
    public function move(array $field, int $direction): void
    {
        $fields = $this->forProject((int) $field['project_id']);
        $ids = array_map(static fn(array $f): int => (int) $f['id'], $fields);
        $at = array_search((int) $field['id'], $ids, true);
        $to = $at === false ? false : $at + ($direction < 0 ? -1 : 1);

        if ($at === false || !isset($ids[$to])) {
            return;
        }

        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $update = $this->db->prepare('UPDATE custom_fields SET position = :position WHERE id = :id');

        foreach ($ids as $position => $id) {
            $update->execute(['position' => $position + 1, 'id' => $id]);
        }
    }

    /** @return array<int, string> a ticket's values, by field id */
    public function valuesFor(int $ticketId): array
    {
        $statement = $this->db->prepare('SELECT field_id, value FROM ticket_field_values WHERE ticket_id = :ticket');
        $statement->execute(['ticket' => $ticketId]);
        $values = [];

        foreach ($statement->fetchAll() as $row) {
            $values[(int) $row['field_id']] = (string) $row['value'];
        }

        return $values;
    }

    /** A value, or none: an empty value is no row at all. */
    public function setValue(int $ticketId, int $fieldId, ?string $value): void
    {
        if ($value === null || $value === '') {
            $this->db->prepare('DELETE FROM ticket_field_values WHERE ticket_id = :ticket AND field_id = :field')
                ->execute(['ticket' => $ticketId, 'field' => $fieldId]);

            return;
        }

        $this->db->prepare(
            'INSERT INTO ticket_field_values (ticket_id, field_id, value) VALUES (:ticket, :field, :value)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        )->execute(['ticket' => $ticketId, 'field' => $fieldId, 'value' => $value]);
    }

    /** @param array<string, mixed> $field */
    private static function withChoices(array $field): array
    {
        $field['choices'] = array_values(array_filter(array_map('trim', explode("\n", (string) ($field['options'] ?? ''))), static fn(string $c): bool => $c !== ''));

        return $field;
    }
}
