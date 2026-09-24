<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Format;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\StatusRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use PDO;

/**
 * Tickets from a spreadsheet: a CSV with a header row, read, shown, and then
 * made into tickets one by one through the same service the form uses.
 *
 * Whatever a spreadsheet program saves is read: a comma or a semicolon
 * between the fields (Excel in a Hungarian locale writes semicolons), UTF-8
 * with or without its byte-order mark, or the Windows-1250 an older Excel
 * still writes Hungarian letters in.
 *
 * The columns are recognised by their names — in English or in Hungarian —
 * so an export from another tracker usually needs no editing. Only a title is
 * required; a row whose assignee, column or epic cannot be found is shown
 * with the problem beside it before anything is made.
 */
class TicketImport
{
    public const MAX_ROWS = 2000;

    /** Header names (lower case, without accents or punctuation) => field. */
    private const COLUMNS = [
        'title' => 'title', 'summary' => 'title', 'name' => 'title', 'cim' => 'title', 'targy' => 'title', 'osszefoglalo' => 'title',
        'description' => 'description', 'body' => 'description', 'details' => 'description', 'leiras' => 'description',
        'type' => 'type', 'issuetype' => 'type', 'tipus' => 'type',
        'priority' => 'priority', 'prioritas' => 'priority',
        'status' => 'status', 'column' => 'status', 'allapot' => 'status', 'oszlop' => 'status',
        'assignee' => 'assignee', 'owner' => 'assignee', 'assignedto' => 'assignee', 'felelos' => 'assignee',
        'labels' => 'labels', 'label' => 'labels', 'tags' => 'labels', 'cimkek' => 'labels', 'cimke' => 'labels',
        'estimate' => 'estimate', 'originalestimate' => 'estimate', 'becsles' => 'estimate',
        'due' => 'due_on', 'duedate' => 'due_on', 'dueon' => 'due_on', 'hatarido' => 'due_on',
        'storypoints' => 'story_points', 'points' => 'story_points', 'sztoripont' => 'story_points', 'pont' => 'story_points',
        'epic' => 'epic', 'epicname' => 'epic',
        'parent' => 'parent', 'parentkey' => 'parent', 'parentissue' => 'parent', 'szulo' => 'parent', 'szuloticket' => 'parent',
    ];

    /** Words for the types and priorities, in the languages people write them in. */
    private const TYPES = ['task' => 'task', 'feladat' => 'task', 'bug' => 'bug', 'hiba' => 'bug', 'defect' => 'bug', 'story' => 'story', 'sztori' => 'story', 'userstory' => 'story'];
    private const PRIORITIES = [
        'low' => 'low', 'alacsony' => 'low', 'lowest' => 'low', 'minor' => 'low',
        'normal' => 'normal', 'medium' => 'normal', 'kozepes' => 'normal',
        'high' => 'high', 'magas' => 'high', 'major' => 'high',
        'urgent' => 'urgent', 'surgos' => 'urgent', 'highest' => 'urgent', 'critical' => 'urgent', 'blocker' => 'urgent',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * The rows of a CSV file, the header first.
     *
     * @return list<list<string>>
     * @throws ValidationError
     */
    public static function read(string $path): array
    {
        $text = (string) file_get_contents($path);

        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        } elseif (!mb_check_encoding($text, 'UTF-8')) {
            $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $text);
            $text = $converted === false ? '' : $converted;
        }

        $firstLine = strtok($text, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new ValidationError(__('The file could not be read.'));
        }

        fwrite($stream, $text);
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $rows[] = array_map(static fn($cell): string => trim((string) $cell), $row);

            if (count($rows) > self::MAX_ROWS + 1) {
                throw new ValidationError(__('At most {count} tickets in one go.', ['count' => self::MAX_ROWS]));
            }
        }

        fclose($stream);

        if (count($rows) < 2) {
            throw new ValidationError(__('The file needs a header row and at least one ticket under it.'));
        }

        return $rows;
    }

    /**
     * Which field each column is, by its header — null for a column this
     * does not know, which is left out.
     *
     * @param list<string> $header
     * @return array<int, string>
     */
    public static function columns(array $header): array
    {
        $fields = [];

        foreach ($header as $index => $name) {
            $key = self::plain($name);

            if (isset(self::COLUMNS[$key]) && !in_array(self::COLUMNS[$key], $fields, true)) {
                $fields[$index] = self::COLUMNS[$key];
            }
        }

        return $fields;
    }

    /**
     * The columns, with the project's own fields among them: a column named
     * after one of them is "field:" and its id.
     *
     * @param list<string> $header
     * @return array<int, string>
     */
    public function projectColumns(int $projectId, array $header): array
    {
        $columns = self::columns($header);

        foreach ((new \CantoTrack\Model\CustomFieldRepository($this->db))->forProject($projectId) as $field) {
            foreach ($header as $index => $name) {
                if (!isset($columns[$index]) && mb_strtolower(trim($name)) === mb_strtolower((string) $field['name'])) {
                    $columns[$index] = 'field:' . $field['id'];
                }
            }
        }

        return $columns;
    }

    /**
     * Every row as it would become a ticket, with what is wrong with it.
     *
     * @param list<list<string>> $rows the header first
     * @return list<array{line: int, input: array<string, mixed>, shown: array<string, string>, problems: list<string>}>
     */
    public function prepare(int $projectId, array $rows): array
    {
        $columns = $this->projectColumns($projectId, $rows[0]);
        $custom = [];
        foreach ((new \CantoTrack\Model\CustomFieldRepository($this->db))->forProject($projectId) as $field) {
            if (in_array('field:' . $field['id'], $columns, true)) {
                $custom[(int) $field['id']] = $field;
            }
        }

        $statuses = new StatusRepository($this->db);
        $epics = [];
        foreach ((new EpicRepository($this->db))->forProject($projectId) as $epic) {
            $epics[mb_strtolower(trim((string) $epic['title']))] = (int) $epic['id'];
        }

        $people = [];
        foreach ((new UserRepository($this->db))->active() as $person) {
            $people[mb_strtolower((string) $person['email'])] = (int) $person['id'];
            $people[mb_strtolower((string) $person['name'])] ??= (int) $person['id'];
        }

        $prepared = [];

        foreach (array_slice($rows, 1) as $number => $row) {
            $value = [];
            foreach ($columns as $index => $field) {
                $value[$field] = $row[$index] ?? '';
            }

            if (implode('', $value) === '') {
                continue;
            }

            $problems = [];
            $input = ['project_id' => $projectId, 'title' => $value['title'] ?? '', 'description' => $value['description'] ?? ''];

            if (trim($input['title']) === '') {
                $problems[] = __('No title.');
            }

            if (($value['type'] ?? '') !== '') {
                $input['type'] = self::TYPES[self::plain($value['type'])] ?? 'task';
            }

            if (($value['priority'] ?? '') !== '') {
                $priority = self::PRIORITIES[self::plain($value['priority'])] ?? null;

                if ($priority === null) {
                    $problems[] = __('“{value}” is not a priority.', ['value' => $value['priority']]);
                } else {
                    $input['priority'] = $priority;
                }
            }

            if (($value['status'] ?? '') !== '') {
                $status = $statuses->resolve($projectId, $value['status']);

                if ($status === null) {
                    $problems[] = __('“{value}” is not one of the board’s columns.', ['value' => $value['status']]);
                } else {
                    $input['status'] = (string) $status['id'];
                }
            }

            if (($value['assignee'] ?? '') !== '') {
                $who = $people[mb_strtolower($value['assignee'])] ?? null;

                if ($who === null) {
                    $problems[] = __('Nobody active is called “{value}”.', ['value' => $value['assignee']]);
                } else {
                    $input['assignee_id'] = $who;
                }
            }

            if (($value['epic'] ?? '') !== '') {
                $epic = $epics[mb_strtolower($value['epic'])] ?? null;

                if ($epic === null) {
                    $problems[] = __('The project has no epic “{value}”.', ['value' => $value['epic']]);
                } else {
                    $input['epic_id'] = $epic;
                }
            }

            // A subtask of a ticket that is already here, by its key.
            if (($value['parent'] ?? '') !== '') {
                $parent = (new TicketRepository($this->db))->findByKey($value['parent']);

                if ($parent === null || (int) $parent['project_id'] !== $projectId) {
                    $problems[] = __('The project has no ticket {key} to be a subtask of.', ['key' => strtoupper(trim($value['parent']))]);
                } elseif ($parent['parent_id'] !== null) {
                    $problems[] = __('{key} is a subtask itself; subtasks go one level deep.', ['key' => strtoupper(trim($value['parent']))]);
                } else {
                    $input['parent_id'] = (int) $parent['id'];
                }
            }

            foreach ($custom as $fieldId => $field) {
                $given = $value['field:' . $fieldId] ?? '';

                if ($given === '') {
                    continue;
                }

                try {
                    $input['fields'][$fieldId] = \CantoTrack\Service\CustomFields::value($field, $given);
                } catch (ValidationError $e) {
                    $problems[] = $e->getMessage();
                }
            }

            if (($value['labels'] ?? '') !== '') {
                $input['labels'] = str_replace(';', ',', $value['labels']);
            }

            if (($value['estimate'] ?? '') !== '') {
                if (Format::parseDuration($value['estimate']) === null) {
                    $problems[] = __('“{value}” is not an estimate.', ['value' => $value['estimate']]);
                } else {
                    $input['estimate'] = $value['estimate'];
                }
            }

            if (($value['due_on'] ?? '') !== '') {
                $due = self::date($value['due_on']);

                if ($due === null) {
                    $problems[] = __('“{value}” is not a date.', ['value' => $value['due_on']]);
                } else {
                    $input['due_on'] = $due;
                }
            }

            if (($value['story_points'] ?? '') !== '') {
                if (ctype_digit($value['story_points']) && (int) $value['story_points'] <= 100) {
                    $input['story_points'] = $value['story_points'];
                } else {
                    $problems[] = __('“{value}” is not a number of story points.', ['value' => $value['story_points']]);
                }
            }

            $prepared[] = ['line' => $number + 2, 'input' => $input, 'shown' => $value, 'problems' => $problems];
        }

        return $prepared;
    }

    /**
     * Makes the tickets of the rows without problems.
     *
     * @param list<array{line: int, input: array<string, mixed>, shown: array<string, string>, problems: list<string>}> $prepared
     * @return array{created: list<string>, failed: array<int, string>}
     */
    public function import(array $prepared, int $reporterId): array
    {
        $service = new TicketService($this->db);
        $tickets = new TicketRepository($this->db);
        $created = [];
        $failed = [];

        foreach ($prepared as $row) {
            if ($row['problems'] !== []) {
                continue;
            }

            try {
                $ticket = $tickets->find($service->create($row['input'], $reporterId));
                $created[] = $ticket === null ? '' : $ticket['project_code'] . '-' . $ticket['number'];
            } catch (ValidationError $e) {
                $failed[$row['line']] = $e->getMessage();
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /** 2026-10-01, 2026.10.01., 2026. 10. 01. or 01.10.2026 — as a Y-m-d, or null. */
    public static function date(string $given): ?string
    {
        $given = trim($given);

        if (preg_match('/^(\d{4})[-.\/]\s?(\d{1,2})[-.\/]\s?(\d{1,2})\.?$/', $given, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $given, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /** "Due date", "due_date", "Határidő" → "duedate", "hatarido". */
    private static function plain(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u']);

        return (string) preg_replace('/[^a-z0-9]/', '', $text);
    }
}
