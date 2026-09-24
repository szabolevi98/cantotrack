<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Format;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\CustomFieldRepository;
use PDO;

/**
 * The rules about a project's own fields: what each kind takes, which ones
 * must be filled in, and the line in the history when a value changes.
 */
class CustomFields
{
    public const KINDS = ['text', 'number', 'select', 'date', 'checkbox'];

    private CustomFieldRepository $fields;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
        $this->fields = new CustomFieldRepository($this->db);
    }

    /**
     * The values a form or the API sent, checked against the project's
     * fields: by field id or by name, each in the one form its kind keeps.
     * Fields that were not sent are not in the answer; with $creating, a
     * required field that was not sent is refused.
     *
     * @param array<int|string, mixed>|null $given
     * @return array<int, ?string> field id => the value to keep, null for none
     * @throws ValidationError
     */
    public function checked(int $projectId, ?array $given, bool $creating): array
    {
        $out = [];
        $given ??= [];

        foreach ($this->fields->forProject($projectId) as $field) {
            $id = (int) $field['id'];
            $sent = array_key_exists($id, $given) || array_key_exists((string) $id, $given);
            $raw = $sent ? $given[$id] : null;

            if (!$sent) {
                foreach ($given as $key => $value) {
                    if (is_string($key) && mb_strtolower($key) === mb_strtolower((string) $field['name'])) {
                        $sent = true;
                        $raw = $value;
                    }
                }
            }

            if (!$sent) {
                if ($creating && (int) $field['is_required'] === 1 && $field['kind'] !== 'checkbox') {
                    throw new ValidationError(__('“{field}” has to be filled in.', ['field' => $field['name']]));
                }
                continue;
            }

            $out[$id] = self::value($field, $raw);
        }

        return $out;
    }

    /**
     * One value in the form its kind keeps — or null for none.
     *
     * @param array<string, mixed> $field
     * @throws ValidationError
     */
    public static function value(array $field, mixed $raw): ?string
    {
        $name = (string) $field['name'];
        $given = is_bool($raw) ? ($raw ? '1' : '0') : trim((string) (is_scalar($raw) ? $raw : ''));

        if ($field['kind'] === 'checkbox') {
            return in_array(mb_strtolower($given), ['1', 'on', 'yes', 'true', 'igen'], true) ? '1' : null;
        }

        if ($given === '') {
            if ((int) $field['is_required'] === 1) {
                throw new ValidationError(__('“{field}” has to be filled in.', ['field' => $name]));
            }

            return null;
        }

        switch ($field['kind']) {
            case 'number':
                $number = str_replace([' ', ','], ['', '.'], $given);
                if (!is_numeric($number)) {
                    throw new ValidationError(__('“{field}” is a number.', ['field' => $name]));
                }

                return rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.');

            case 'date':
                $date = TicketImport::date($given);
                if ($date === null) {
                    throw new ValidationError(__('“{field}” is a day, like 2026-10-01.', ['field' => $name]));
                }

                return $date;

            case 'select':
                foreach ($field['choices'] as $choice) {
                    if (mb_strtolower($choice) === mb_strtolower($given)) {
                        return $choice;
                    }
                }

                throw new ValidationError(__('“{value}” is not one of the choices of “{field}”.', ['value' => $given, 'field' => $name]));

            default:
                return mb_substr($given, 0, 500);
        }
    }

    /**
     * Keeps checked values, with a line in the history for each that changed.
     *
     * @param array<int, ?string> $values from checked()
     */
    public function save(array $ticket, array $values, ?int $actorId, bool $quietly = false): void
    {
        if ($values === []) {
            return;
        }

        $before = $this->fields->valuesFor((int) $ticket['id']);
        $byId = [];
        foreach ($this->fields->forProject((int) $ticket['project_id']) as $field) {
            $byId[(int) $field['id']] = $field;
        }

        foreach ($values as $fieldId => $value) {
            $old = $before[$fieldId] ?? null;

            if ($old === $value || !isset($byId[$fieldId])) {
                continue;
            }

            $this->fields->setValue((int) $ticket['id'], $fieldId, $value);

            if (!$quietly) {
                (new Activity($this->db))->happened($ticket, $actorId, 'changed', (string) $byId[$fieldId]['name'], self::shown($byId[$fieldId], $old), self::shown($byId[$fieldId], $value));
            }
        }
    }

    /** A value the way a person reads it. */
    public static function shown(array $field, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field['kind']) {
            'checkbox' => __('yes'),
            'date' => Format::day($value),
            default => $value,
        };
    }

    /**
     * A new field, or a changed one: a name the project does not use yet,
     * one of the kinds, and for a list its choices.
     *
     * @throws ValidationError
     */
    public function define(int $projectId, string $name, string $kind, string $options, bool $required, ?array $existing = null): int
    {
        $name = trim($name);
        $choices = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R|,/', $options) ?: []), static fn(string $c): bool => $c !== '')));

        if ($name === '' || mb_strlen($name) > 60) {
            throw new ValidationError(__('A field needs a name of at most {count} characters.', ['count' => 60]));
        }

        if ($this->fields->nameTaken($projectId, $name, $existing === null ? null : (int) $existing['id'])) {
            throw new ValidationError(__('The project has a field called “{field}” already.', ['field' => $name]));
        }

        $kind = $existing === null ? $kind : (string) $existing['kind'];

        if (!in_array($kind, self::KINDS, true)) {
            throw new ValidationError(__('That is not one of the kinds of field.'));
        }

        if ($kind === 'select' && $choices === []) {
            throw new ValidationError(__('A list needs its choices, one a line.'));
        }

        $stored = $kind === 'select' ? implode("\n", $choices) : null;

        if ($existing !== null) {
            $this->fields->update((int) $existing['id'], $name, $stored, $required);

            return (int) $existing['id'];
        }

        return $this->fields->create($projectId, $name, $kind, $stored, $required);
    }
}
