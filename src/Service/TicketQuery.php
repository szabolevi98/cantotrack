<?php

namespace CantoTrack\Service;

use CantoTrack\Core\Format;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\TicketRepository;
use DateTimeImmutable;

/**
 * The query language of the ticket list:
 *
 *     project = BIKE AND assignee = me AND status != Done ORDER BY priority DESC
 *     type IN (bug, story) AND created >= -14d AND text ~ "checkout"
 *     sprint IN openSprints() AND (labels = api OR labels IS EMPTY)
 *
 * A query is compiled into a condition for the same statement every ticket
 * list reads with, so it goes through the same visibility filter: a query can
 * narrow what somebody sees, never widen it. Field names are looked up in a
 * fixed list and every value is a bound parameter — nothing typed here is ever
 * written into the SQL itself.
 *
 * The grammar, from the outside in:
 *
 *     query   = [ or ] [ "ORDER BY" order { "," order } ]
 *     or      = and { "OR" and }
 *     and     = not { "AND" not }
 *     not     = "NOT" not | "(" or ")" | clause
 *     clause  = field op value | field ["NOT"] "IN" "(" value { "," value } ")"
 *             | field "IS" ["NOT"] "EMPTY" | field ("~" | "!~") value
 *     value   = word | "quoted words" | name "(" [ value ] ")"
 */
final class TicketQuery
{
    /** Every field there is, by the names people use for them, lower case. */
    private const FIELDS = [
        'project' => 'project',
        'key' => 'key', 'issuekey' => 'key', 'ticket' => 'key', 'id' => 'key',
        'parent' => 'parent',
        'type' => 'type', 'issuetype' => 'type',
        'status' => 'status',
        'category' => 'category', 'statuscategory' => 'category',
        'priority' => 'priority',
        'assignee' => 'assignee',
        'reporter' => 'reporter',
        'watcher' => 'watcher',
        'epic' => 'epic',
        'sprint' => 'sprint',
        'release' => 'release', 'fixversion' => 'release', 'version' => 'release',
        'label' => 'label', 'labels' => 'label',
        'points' => 'points', 'storypoints' => 'points',
        'estimate' => 'estimate',
        'logged' => 'logged', 'timespent' => 'logged',
        'created' => 'created',
        'updated' => 'updated',
        'resolved' => 'resolved', 'finished' => 'resolved',
        'due' => 'due', 'duedate' => 'due',
        'title' => 'title', 'summary' => 'title',
        'description' => 'description',
        'comment' => 'comment',
        'text' => 'text',
    ];

    /** What each field is, which decides the operators it takes. */
    private const KINDS = [
        'project' => 'ref', 'key' => 'ref', 'parent' => 'ref', 'type' => 'ref', 'status' => 'ref', 'category' => 'ref',
        'priority' => 'priority', 'assignee' => 'person', 'reporter' => 'person', 'watcher' => 'person',
        'epic' => 'ref', 'sprint' => 'ref', 'release' => 'ref', 'label' => 'ref',
        'points' => 'number', 'estimate' => 'duration', 'logged' => 'duration',
        'created' => 'date', 'updated' => 'date', 'resolved' => 'date', 'due' => 'date',
        'title' => 'text', 'description' => 'text', 'comment' => 'text', 'text' => 'text',
    ];

    /** What a list can be put in order by, and the column that orders it. */
    private const ORDERS = [
        'key' => ['t.project_id', 't.number'], 'created' => ['t.created_at'], 'updated' => ['t.updated_at'],
        'resolved' => ['t.closed_at'], 'due' => ['t.due_on'], 'priority' => ['FIELD(t.priority, \'low\', \'normal\', \'high\', \'urgent\')'],
        'points' => ['t.story_points'], 'estimate' => ['t.estimate_minutes'], 'title' => ['t.title'],
        'status' => ['FIELD(s.category, \'todo\', \'in_progress\', \'done\')', 's.position'], 'assignee' => ['a.name'],
        'rank' => ['t.`rank`'], 'logged' => ['logged_minutes'],
    ];

    private const CATEGORY_WORDS = ['todo' => 'todo', 'to do' => 'todo', 'in progress' => 'in_progress', 'in_progress' => 'in_progress', 'done' => 'done'];

    /** @var list<array{type: string, value: string, at: int}> */
    private array $tokens = [];
    private int $position = 0;

    /** @var array<string, mixed> */
    private array $params = [];

    private function __construct(private readonly ?int $userId, private readonly DateTimeImmutable $now)
    {
    }

    /**
     * A query, as a condition and an order for the ticket list.
     *
     * @return array{where: string, params: array<string, mixed>, order: ?string}
     * @throws ValidationError when it cannot be read, with where it went wrong
     */
    public static function compile(string $query, ?int $userId, ?DateTimeImmutable $now = null): array
    {
        $compiler = new self($userId, $now ?? new DateTimeImmutable());
        $compiler->tokens = self::tokenize($query);

        $where = '';
        if (!$compiler->isKeyword('ORDER') && !$compiler->done()) {
            $where = $compiler->orExpression();
        }

        $order = null;
        if ($compiler->isKeyword('ORDER')) {
            $compiler->position++;
            $compiler->expectKeyword('BY');
            $order = $compiler->orderList();
        }

        if (!$compiler->done()) {
            $compiler->fail(__('Did not expect “{word}” here.', ['word' => $compiler->peek()['value']]));
        }

        return ['where' => $where, 'params' => $compiler->params, 'order' => $order];
    }

    /**
     * The words a query can use, for the help beside the box and for the
     * suggestions under it.
     *
     * @return array{fields: list<string>, functions: list<string>, keywords: list<string>}
     */
    public static function vocabulary(): array
    {
        return [
            'fields' => array_values(array_unique(array_values(self::FIELDS))),
            'functions' => ['currentUser()', 'now()', 'startOfDay()', 'startOfWeek()', 'endOfWeek()', 'startOfMonth()', 'endOfMonth()', 'openSprints()', 'closedSprints()', 'futureSprints()', 'unreleased()', 'released()'],
            'keywords' => ['AND', 'OR', 'NOT', 'IN', 'IS', 'EMPTY', 'ORDER BY', 'ASC', 'DESC'],
        ];
    }

    /**
     * The list's simple filters written as a query — what the box starts
     * with when somebody switches to it from the filters.
     *
     * @param array<string, mixed> $filters
     * @param array<string, array<int, string>> $names project codes, people, columns and releases by id
     */
    public static function fromFilters(array $filters, array $names): string
    {
        $parts = [];
        $quote = static fn(string $v): string => preg_match('/^[\pL\pN_.@-]+$/u', $v) === 1 ? $v : '"' . addcslashes($v, '"\\') . '"';

        if (!empty($filters['project_id']) && isset($names['project'][$filters['project_id']])) {
            $parts[] = 'project = ' . $quote($names['project'][$filters['project_id']]);
        }
        if (!empty($filters['status'])) {
            $status = (string) $filters['status'];
            $parts[] = ctype_digit($status) && isset($names['status'][(int) $status])
                ? 'status = ' . $quote($names['status'][(int) $status])
                : 'category = ' . $quote(str_replace('_', ' ', $status));
        }
        if (!empty($filters['assignee_id']) && isset($names['person'][$filters['assignee_id']])) {
            $parts[] = 'assignee = ' . $quote($names['person'][$filters['assignee_id']]);
        }
        if (!empty($filters['type'])) {
            $parts[] = 'type = ' . $quote((string) $filters['type']);
        }
        if (!empty($filters['label'])) {
            $parts[] = 'labels = ' . $quote((string) $filters['label']);
        }
        if (($filters['due'] ?? '') === 'overdue') {
            $parts[] = 'due < startOfDay() AND category != done';
        } elseif (($filters['due'] ?? '') === 'week') {
            $parts[] = 'due >= startOfDay() AND due <= 7d AND category != done';
        }
        if (!empty($filters['release_id']) && isset($names['release'][$filters['release_id']])) {
            $parts[] = 'release = ' . $quote($names['release'][$filters['release_id']]);
        }
        if (!empty($filters['open_only'])) {
            $parts[] = 'category != done';
        }
        if (!empty($filters['q'])) {
            $parts[] = 'text ~ ' . $quote((string) $filters['q']);
        }

        return implode(' AND ', $parts);
    }

    // -----------------------------------------------------------------------
    // Reading the words
    // -----------------------------------------------------------------------

    /** @return list<array{type: string, value: string, at: int}> */
    private static function tokenize(string $query): array
    {
        $tokens = [];
        $length = strlen($query);
        $i = 0;

        while ($i < $length) {
            $char = $query[$i];

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $end = $i + 1;
                $value = '';
                while ($end < $length && $query[$end] !== $char) {
                    if ($query[$end] === '\\' && $end + 1 < $length) {
                        $end++;
                    }
                    $value .= $query[$end];
                    $end++;
                }
                if ($end >= $length) {
                    throw new ValidationError(__('The quotes opened at {at} are never closed.', ['at' => $i + 1]));
                }
                $tokens[] = ['type' => 'string', 'value' => $value, 'at' => $i + 1];
                $i = $end + 1;
                continue;
            }

            if (in_array($char, ['(', ')', ','], true)) {
                $tokens[] = ['type' => $char, 'value' => $char, 'at' => $i + 1];
                $i++;
                continue;
            }

            $two = substr($query, $i, 2);
            if (in_array($two, ['!=', '<=', '>=', '!~'], true)) {
                $tokens[] = ['type' => 'op', 'value' => $two, 'at' => $i + 1];
                $i += 2;
                continue;
            }

            if (in_array($char, ['=', '<', '>', '~'], true)) {
                $tokens[] = ['type' => 'op', 'value' => $char, 'at' => $i + 1];
                $i++;
                continue;
            }

            // A word: anything up to a space, a bracket, a comma, a quote or
            // an operator — CT-14, 2026-10-01, -7d and anna@example.com alike.
            $start = $i;
            while ($i < $length && !ctype_space($query[$i]) && !in_array($query[$i], ['(', ')', ',', '"', "'", '=', '<', '>', '~', '!'], true)) {
                $i++;
            }
            if ($i === $start) {
                throw new ValidationError(__('Did not expect “{word}” at {at}.', ['word' => $char, 'at' => $i + 1]));
            }
            $tokens[] = ['type' => 'word', 'value' => substr($query, $start, $i - $start), 'at' => $start + 1];
        }

        return $tokens;
    }

    /** @return array{type: string, value: string, at: int} */
    private function peek(): array
    {
        return $this->tokens[$this->position] ?? ['type' => 'end', 'value' => '', 'at' => 0];
    }

    private function advance(): void
    {
        $this->position++;
    }

    private function done(): bool
    {
        return $this->position >= count($this->tokens);
    }

    private function isKeyword(string $word): bool
    {
        $token = $this->peek();

        return $token['type'] === 'word' && strtoupper($token['value']) === $word;
    }

    private function expectKeyword(string $word): void
    {
        if (!$this->isKeyword($word)) {
            $this->fail(__('Expected “{word}” here.', ['word' => $word]));
        }
        $this->position++;
    }

    private function expect(string $type, string $shown): void
    {
        if ($this->peek()['type'] !== $type) {
            $this->fail(__('Expected “{word}” here.', ['word' => $shown]));
        }
        $this->position++;
    }

    /** @throws ValidationError */
    private function fail(string $message): never
    {
        $token = $this->peek();

        throw new ValidationError($token['type'] === 'end'
            ? __('{message} (at the end of the query)', ['message' => $message])
            : __('{message} (at {at})', ['message' => $message, 'at' => $token['at']]));
    }

    // -----------------------------------------------------------------------
    // The grammar
    // -----------------------------------------------------------------------

    private function orExpression(): string
    {
        $parts = [$this->andExpression()];

        while ($this->isKeyword('OR')) {
            $this->position++;
            $parts[] = $this->andExpression();
        }

        return count($parts) === 1 ? $parts[0] : '(' . implode(' OR ', $parts) . ')';
    }

    private function andExpression(): string
    {
        $parts = [$this->notExpression()];

        while ($this->isKeyword('AND')) {
            $this->position++;
            $parts[] = $this->notExpression();
        }

        return count($parts) === 1 ? $parts[0] : '(' . implode(' AND ', $parts) . ')';
    }

    private function notExpression(): string
    {
        if ($this->isKeyword('NOT')) {
            $this->position++;

            return '(NOT ' . $this->notExpression() . ')';
        }

        if ($this->peek()['type'] === '(') {
            $this->position++;
            $inner = $this->orExpression();
            $this->expect(')', ')');

            return $inner;
        }

        return $this->clause();
    }

    private function clause(): string
    {
        $token = $this->peek();

        if ($token['type'] !== 'word') {
            $this->fail(__('Expected a field, such as project, status or assignee.'));
        }

        $field = self::FIELDS[strtolower($token['value'])] ?? null;

        if ($field === null) {
            $this->fail(__('There is no field called “{field}”.', ['field' => $token['value']]));
        }

        $this->position++;
        $kind = self::KINDS[$field];

        // IS [NOT] EMPTY
        if ($this->isKeyword('IS')) {
            $this->position++;
            $negated = false;
            if ($this->isKeyword('NOT')) {
                $negated = true;
                $this->position++;
            }
            if (!$this->isKeyword('EMPTY') && !$this->isKeyword('NULL')) {
                $this->fail(__('Expected “EMPTY” after “IS”.'));
            }
            $this->position++;
            $empty = $this->empty($field);

            return $negated ? '(NOT ' . $empty . ')' : $empty;
        }

        // [NOT] IN ( … )
        $negated = false;
        if ($this->isKeyword('NOT')) {
            $this->position++;
            $negated = true;
            if (!$this->isKeyword('IN')) {
                $this->fail(__('Expected “IN” after “NOT”.'));
            }
        }

        if ($this->isKeyword('IN')) {
            $this->position++;
            // Either a list in brackets, or a function that stands for one:
            // sprint IN openSprints().
            $values = $this->peek()['type'] === 'word' ? [$this->value()] : $this->valueList();
            $parts = array_map(fn(array $value): string => $this->compare($field, '=', $value), $values);
            $any = '(' . implode(' OR ', $parts) . ')';

            return $negated ? '(NOT ' . $any . ')' : $any;
        }

        $op = $this->peek();
        if ($op['type'] !== 'op') {
            $this->fail(__('Expected an operator after “{field}”: =, !=, <, >, ~, IN or IS.', ['field' => $token['value']]));
        }
        $this->position++;

        $allowed = match ($kind) {
            'text' => ['~', '!~'],
            'ref', 'person' => ['=', '!='],
            default => ['=', '!=', '<', '<=', '>', '>='],
        };
        if ($field === 'text' || $field === 'title' || $field === 'description' || $field === 'comment') {
            $allowed = ['~', '!~'];
        }
        if (!in_array($op['value'], $allowed, true)) {
            $this->position--;
            $this->fail(__('“{field}” does not take “{op}”; it takes {allowed}.', ['field' => $token['value'], 'op' => $op['value'], 'allowed' => implode(' ', $allowed)]));
        }

        return $this->compare($field, $op['value'], $this->value());
    }

    /** @return list<array{value: string, function: ?string, at: int}> */
    private function valueList(): array
    {
        $this->expect('(', '(');
        $values = [$this->value()];

        while ($this->peek()['type'] === ',') {
            $this->position++;
            $values[] = $this->value();
        }

        $this->expect(')', ')');

        return $values;
    }

    /** @return array{value: string, function: ?string, at: int} */
    private function value(): array
    {
        $token = $this->peek();

        if ($token['type'] === 'string') {
            $this->position++;

            return ['value' => $token['value'], 'function' => null, 'at' => $token['at']];
        }

        if ($token['type'] !== 'word') {
            $this->fail(__('Expected a value here.'));
        }

        $this->position++;

        // A function: its name, and a value in the brackets if it takes one.
        if ($this->peek()['type'] === '(') {
            $this->advance();
            $argument = '';
            if ($this->peek()['type'] !== ')') {
                $argument = $this->value()['value'];
            }
            $this->expect(')', ')');

            return ['value' => $argument, 'function' => strtolower($token['value']), 'at' => $token['at']];
        }

        return ['value' => $token['value'], 'function' => null, 'at' => $token['at']];
    }

    /** @return string the whole ORDER BY list, as SQL */
    private function orderList(): string
    {
        $parts = [];

        do {
            if ($parts !== []) {
                $this->position++;
            }
            $token = $this->peek();
            $field = $token['type'] === 'word' ? (self::FIELDS[strtolower($token['value'])] ?? strtolower($token['value'])) : '';

            if (!isset(self::ORDERS[$field])) {
                $this->fail(__('A list cannot be put in order by “{field}”.', ['field' => $token['value']]));
            }
            $this->position++;

            $direction = 'ASC';
            if ($this->isKeyword('ASC') || $this->isKeyword('DESC')) {
                $direction = strtoupper($this->peek()['value']);
                $this->position++;
            }

            foreach (self::ORDERS[$field] as $column) {
                $parts[] = $column . ' ' . $direction;
            }
        } while ($this->peek()['type'] === ',');

        return implode(', ', $parts);
    }

    // -----------------------------------------------------------------------
    // Into SQL
    // -----------------------------------------------------------------------

    private function bind(mixed $value): string
    {
        $name = 'tq' . count($this->params);
        $this->params[$name] = $value;

        return ':' . $name;
    }

    private function empty(string $field): string
    {
        return match ($field) {
            'assignee' => 't.assignee_id IS NULL',
            'reporter' => 't.reporter_id IS NULL',
            'epic' => 't.epic_id IS NULL',
            'sprint' => 't.sprint_id IS NULL',
            'release' => 't.release_id IS NULL',
            'parent' => 't.parent_id IS NULL',
            'label' => 'NOT EXISTS (SELECT 1 FROM ticket_labels tqe WHERE tqe.ticket_id = t.id)',
            'watcher' => 'NOT EXISTS (SELECT 1 FROM ticket_watchers tqe WHERE tqe.ticket_id = t.id)',
            'points' => 't.story_points IS NULL',
            'estimate' => 't.estimate_minutes IS NULL',
            'logged' => 'NOT EXISTS (SELECT 1 FROM worklogs tqe WHERE tqe.ticket_id = t.id)',
            'resolved' => 't.closed_at IS NULL',
            'due' => 't.due_on IS NULL',
            'description' => '(t.description IS NULL OR t.description = \'\')',
            'comment' => 'NOT EXISTS (SELECT 1 FROM comments tqe WHERE tqe.ticket_id = t.id)',
            default => $this->fail(__('“{field}” is never empty.', ['field' => $field])),
        };
    }

    /** @param array{value: string, function: ?string, at: int} $value */
    private function compare(string $field, string $op, array $value): string
    {
        $v = trim($value['value']);
        $not = $op === '!=' || $op === '!~';

        $sql = match ($field) {
            'project' => '(p.code = ' . $this->bind(strtoupper($v)) . ' OR p.name = ' . $this->bind($v) . ')',
            'key' => $this->key('t', $v),
            'parent' => 't.parent_id IN (SELECT tqp.id FROM tickets tqp JOIN projects tqpp ON tqpp.id = tqp.project_id WHERE ' . $this->keyOf('tqp', 'tqpp', $v) . ')',
            'type' => $this->type($v),
            'status' => 's.name = ' . $this->bind($v),
            'category' => 's.category = ' . $this->bind(self::CATEGORY_WORDS[strtolower($v)] ?? $this->fail(__('“{value}” is not a category: to do, in progress or done.', ['value' => $v]))),
            'priority' => $this->priority($op, $v),
            'assignee' => $this->person('t.assignee_id', $value),
            'reporter' => $this->person('t.reporter_id', $value),
            'watcher' => 'EXISTS (SELECT 1 FROM ticket_watchers tqw WHERE tqw.ticket_id = t.id AND ' . $this->person('tqw.user_id', $value) . ')',
            'epic' => ctype_digit($v) ? 't.epic_id = ' . $this->bind((int) $v) : 'e.title = ' . $this->bind($v),
            'sprint' => $this->sprint($value),
            'release' => $this->release($value),
            'label' => 'EXISTS (SELECT 1 FROM ticket_labels tql JOIN labels tqll ON tqll.id = tql.label_id WHERE tql.ticket_id = t.id AND tqll.name = ' . $this->bind($v) . ')',
            'points' => $this->number('t.story_points', $op, $v),
            'estimate' => $this->number('t.estimate_minutes', $op, $this->minutes($v)),
            'logged' => $this->number('(SELECT COALESCE(SUM(tqm.minutes), 0) FROM worklogs tqm WHERE tqm.ticket_id = t.id)', $op, $this->minutes($v)),
            'created' => $this->date('t.created_at', $op, $value, true),
            'updated' => $this->date('t.updated_at', $op, $value, true),
            'resolved' => $this->date('t.closed_at', $op, $value, true),
            'due' => $this->date('t.due_on', $op, $value, false),
            'title' => 't.title LIKE ' . $this->like($v),
            'description' => 't.description LIKE ' . $this->like($v),
            'comment' => 'EXISTS (SELECT 1 FROM comments tqc WHERE tqc.ticket_id = t.id AND tqc.body LIKE ' . $this->like($v) . ')',
            'text' => '(t.title LIKE ' . $this->like($v) . ' OR t.description LIKE ' . $this->like($v)
                . ' OR EXISTS (SELECT 1 FROM comments tqc WHERE tqc.ticket_id = t.id AND tqc.body LIKE ' . $this->like($v) . '))',
            default => $this->fail(__('There is no field called “{field}”.', ['field' => $field])),
        };

        // The operators that compare an order compiled themselves; != and !~
        // are the other way round of = and ~.
        return $not && !in_array(self::KINDS[$field], ['number', 'duration', 'date', 'priority'], true) ? '(NOT ' . $sql . ')' : $sql;
    }

    private function key(string $alias, string $given): string
    {
        return '(' . $this->keyOf($alias, 'p', $given) . ')';
    }

    private function keyOf(string $ticket, string $project, string $given): string
    {
        if (preg_match(TicketRepository::KEY_PATTERN, strtoupper($given), $m) !== 1) {
            $this->fail(__('“{value}” is not a ticket key, such as CT-14.', ['value' => $given]));
        }

        return $project . '.code = ' . $this->bind($m[1]) . ' AND ' . $ticket . '.number = ' . $this->bind((int) $m[2]);
    }

    private function type(string $given): string
    {
        $type = strtolower($given);

        if ($type === 'subtask' || $type === 'sub-task') {
            return 't.parent_id IS NOT NULL';
        }

        if (!in_array($type, TicketRepository::TYPES, true)) {
            $this->fail(__('“{value}” is not a type: task, bug, story or subtask.', ['value' => $given]));
        }

        return 't.type = ' . $this->bind($type);
    }

    private function priority(string $op, string $given): string
    {
        $rank = array_search(strtolower($given), TicketRepository::PRIORITIES, true);

        if ($rank === false) {
            $this->fail(__('“{value}” is not a priority: low, normal, high or urgent.', ['value' => $given]));
        }

        $sqlOp = $op === '!=' ? '<>' : $op;

        return 'FIELD(t.priority, \'low\', \'normal\', \'high\', \'urgent\') ' . $sqlOp . ' ' . $this->bind((int) $rank + 1);
    }

    /** @param array{value: string, function: ?string, at: int} $value */
    private function person(string $column, array $value): string
    {
        $given = trim($value['value']);

        if ($value['function'] === 'currentuser' || strtolower($given) === 'me') {
            return $column . ' = ' . $this->bind((int) $this->userId);
        }

        if ($value['function'] !== null) {
            $this->fail(__('There is no function called “{name}” for a person.', ['name' => $value['function']]));
        }

        return $column . ' IN (SELECT tqu.id FROM users tqu WHERE tqu.name = ' . $this->bind($given)
            . ' OR tqu.email = ' . $this->bind($given) . ' OR tqu.handle = ' . $this->bind(ltrim($given, '@')) . ')';
    }

    /** @param array{value: string, function: ?string, at: int} $value */
    private function sprint(array $value): string
    {
        return match ($value['function']) {
            null => 'sp.name = ' . $this->bind($value['value']),
            'opensprints' => 'sp.state IN (\'active\', \'planned\')',
            'closedsprints' => 'sp.state = \'closed\'',
            'futuresprints' => 'sp.state = \'planned\'',
            default => $this->fail(__('There is no function called “{name}” for a sprint.', ['name' => $value['function']])),
        };
    }

    /** @param array{value: string, function: ?string, at: int} $value */
    private function release(array $value): string
    {
        return match ($value['function']) {
            null => 'rl.name = ' . $this->bind($value['value']),
            'unreleased' => '(t.release_id IS NOT NULL AND rl.released_at IS NULL)',
            'released' => 'rl.released_at IS NOT NULL',
            default => $this->fail(__('There is no function called “{name}” for a release.', ['name' => $value['function']])),
        };
    }

    private function number(string $column, string $op, string|int $given): string
    {
        if (!is_int($given) && !is_numeric($given)) {
            $this->fail(__('“{value}” is not a number.', ['value' => $given]));
        }

        return $column . ' ' . ($op === '!=' ? '<>' : $op) . ' ' . $this->bind((int) $given);
    }

    private function minutes(string $given): int
    {
        $minutes = ctype_digit($given) ? (int) $given * 60 : Format::parseDuration($given);

        if ($minutes === null) {
            $this->fail(__('“{value}” is not a length of time, such as 3h or 1d 4h.', ['value' => $given]));
        }

        return $minutes;
    }

    private function like(string $given): string
    {
        return $this->bind('%' . addcslashes($given, '%_\\') . '%');
    }

    /**
     * A comparison with a day: a date (2026-10-01), a distance from today
     * (-7d, 2w, -1m), or one of the functions for the start or end of this
     * day, week or month. Comparisons are by whole days, so "created = -1d"
     * is everything created yesterday.
     *
     * @param array{value: string, function: ?string, at: int} $value
     */
    private function date(string $column, string $op, array $value, bool $withTime): string
    {
        $day = $this->day($value);
        $next = $day->modify('+1 day');
        $at = static fn(DateTimeImmutable $d): string => $d->format('Y-m-d') . ($withTime ? ' 00:00:00' : '');

        return match ($op) {
            '=' => '(' . $column . ' >= ' . $this->bind($at($day)) . ' AND ' . $column . ' < ' . $this->bind($at($next)) . ')',
            '!=' => '(' . $column . ' < ' . $this->bind($at($day)) . ' OR ' . $column . ' >= ' . $this->bind($at($next)) . ')',
            '<' => $column . ' < ' . $this->bind($at($day)),
            '<=' => $column . ' < ' . $this->bind($at($next)),
            '>' => $column . ' >= ' . $this->bind($at($next)),
            default => $column . ' >= ' . $this->bind($at($day)),
        };
    }

    /** @param array{value: string, function: ?string, at: int} $value */
    private function day(array $value): DateTimeImmutable
    {
        $today = $this->now->setTime(0, 0);

        if ($value['function'] !== null) {
            return match ($value['function']) {
                'now', 'startofday' => $today,
                'startofweek' => $today->modify('monday this week'),
                'endofweek' => $today->modify('sunday this week'),
                'startofmonth' => $today->modify('first day of this month'),
                'endofmonth' => $today->modify('last day of this month'),
                default => $this->fail(__('There is no function called “{name}” for a day.', ['name' => $value['function']])),
            };
        }

        $given = strtolower(trim($value['value']));

        if (preg_match('/^([+-]?)(\d+)([dwmy])$/', $given, $m) === 1) {
            $unit = ['d' => 'days', 'w' => 'weeks', 'm' => 'months', 'y' => 'years'][$m[3]];

            return $today->modify(($m[1] === '-' ? '-' : '+') . $m[2] . ' ' . $unit);
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $given);

        if ($date === false || $date->format('Y-m-d') !== $given) {
            $this->fail(__('“{value}” is not a day: write 2026-10-01, -7d or startOfWeek().', ['value' => $value['value']]));
        }

        return $date;
    }
}
