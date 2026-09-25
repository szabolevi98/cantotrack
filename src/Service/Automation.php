<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\AutomationRepository;
use CantoTrack\Model\CustomFieldRepository;
use CantoTrack\Model\LabelRepository;
use CantoTrack\Model\SprintRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use PDO;
use Throwable;

/**
 * The automation rules at work.
 *
 * What happens to tickets is heard through Activity, like the webhooks hear
 * it, and kept until the request is over; then each rule whose trigger it was
 * is asked whether the ticket matches its condition, and if so does what it
 * says. Working after the request rather than in the middle of it means a
 * rule sees the ticket as the change left it, and never runs in the middle of
 * somebody else's save.
 *
 * What a rule does is not heard by the rules again: one rule's move does not
 * set off another's, so two rules cannot keep undoing each other.
 */
final class Automation
{
    public const TRIGGERS = ['created', 'moved', 'assigned', 'changed', 'commented', 'linked', 'logged', 'subtasks_done', 'daily'];
    public const ACTIONS = ['status', 'resolution', 'assign', 'priority', 'due', 'add_label', 'remove_label', 'field', 'watch', 'comment', 'subtask', 'sprint'];

    /** @var list<array{0: int, 1: string, 2: ?int}> ticket id, trigger, who set it off */
    private static array $queue = [];
    private static bool $running = false;

    private AutomationRepository $rules;
    private TicketRepository $tickets;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
        $this->rules = new AutomationRepository($this->db);
        $this->tickets = new TicketRepository($this->db);
    }

    /** Listens for the rest of the request, and runs the rules when it is over. */
    public static function register(): void
    {
        Activity::listen(static function (array $ticket, ?int $actorId, string $kind, ?string $field): void {
            self::collect($ticket, $actorId, $kind, $field);
        });

        register_shutdown_function(static function (): void {
            try {
                (new self())->runQueued();
            } catch (Throwable $e) {
                \CantoTrack\Core\Logger::error('Automation stopped: ' . $e->getMessage());
            }
        });
    }

    public static function collect(array $ticket, ?int $actorId, string $kind, ?string $field): void
    {
        if (self::$running) {
            return;
        }

        $trigger = match (true) {
            $kind === 'created' => 'created',
            $kind === 'status' => 'moved',
            $kind === 'changed' && $field === 'assignee' => 'assigned',
            // Any other of its fields — the priority, the due date, a field
            // of the project's own — but not its history's own bookkeeping.
            $kind === 'changed' && $field !== null && $field !== 'description' => 'changed',
            $kind === 'commented' => 'commented',
            $kind === 'linked' => 'linked',
            $kind === 'logged' => 'logged',
            default => null,
        };

        if ($trigger !== null) {
            self::$queue[] = [(int) $ticket['id'], $trigger, $actorId];
        }

        // A subtask moved: its parent may have just had its last one done.
        if ($kind === 'status' && !empty($ticket['parent_id'])) {
            self::$queue[] = [(int) $ticket['parent_id'], 'subtasks_check', $actorId];
        }
    }

    /** The rules for everything heard so far, each ticket and trigger once. */
    public function runQueued(): int
    {
        $queue = self::$queue;
        self::$queue = [];
        $seen = [];
        $done = 0;

        foreach ($queue as [$ticketId, $trigger, $actorId]) {
            if (isset($seen[$ticketId . '|' . $trigger])) {
                continue;
            }
            $seen[$ticketId . '|' . $trigger] = true;

            if ($trigger === 'subtasks_check') {
                if (!$this->subtasksAllDone($ticketId) || isset($seen[$ticketId . '|subtasks_done'])) {
                    continue;
                }
                $trigger = 'subtasks_done';
                $seen[$ticketId . '|subtasks_done'] = true;
            }

            $done += $this->fire($ticketId, $trigger, $actorId);
        }

        return $done;
    }

    /**
     * One trigger on one ticket: every rule for it whose condition the
     * ticket matches does what it says. Returns how many did.
     */
    public function fire(int $ticketId, string $trigger, ?int $actorId): int
    {
        $ticket = $this->ticket($ticketId);

        if ($ticket === null) {
            return 0;
        }

        // Every rule is asked about the ticket as the change left it, before
        // any of them acts: the rules answer what happened, not each other.
        $matching = array_filter(
            $this->rules->forTrigger($trigger, (int) $ticket['project_id']),
            fn(array $rule): bool => $this->matches($rule, $ticketId, $actorId)
        );
        $done = 0;

        foreach ($matching as $rule) {
            if ($this->run($rule, $ticketId, $actorId)) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * The daily rules, for the cron job: every ticket that matches, once a
     * day each.
     */
    public function daily(): int
    {
        $done = 0;

        foreach ($this->rules->forTrigger('daily', null) as $rule) {
            $done += $this->dailyRule($rule);
        }

        // The ones kept for one project are not in the list above.
        $statement = $this->db->query('SELECT id FROM automation_rules WHERE is_active = 1 AND `trigger` = \'daily\' AND project_id IS NOT NULL');
        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $rule = $this->rules->find((int) $id);
            if ($rule !== null) {
                $done += $this->dailyRule($rule);
            }
        }

        return $done;
    }

    private function dailyRule(array $rule): int
    {
        $done = 0;

        try {
            $ids = $this->matching($rule, null);
        } catch (ValidationError $e) {
            $this->rules->log((int) $rule['id'], null, 'failed', $e->getMessage());

            return 0;
        }

        foreach ($ids as $ticketId) {
            if (!$this->rules->ranToday((int) $rule['id'], $ticketId) && $this->run($rule, $ticketId, null)) {
                $done++;
            }
        }

        return $done;
    }

    /** Whether a ticket matches a rule's condition — an empty one matches every ticket. */
    public function matches(array $rule, int $ticketId, ?int $actorId): bool
    {
        try {
            return in_array($ticketId, $this->matching($rule, $actorId, $ticketId), true);
        } catch (ValidationError $e) {
            $this->rules->log((int) $rule['id'], $ticketId, 'failed', $e->getMessage());

            return false;
        }
    }

    /**
     * The tickets a rule's condition finds — in its project, and not held
     * back by who is looking: a rule works for the whole team.
     *
     * @return list<int>
     * @throws ValidationError
     */
    private function matching(array $rule, ?int $actorId, ?int $ticketId = null): array
    {
        $where = ['1 = 1'];
        $params = [];

        if (trim((string) $rule['condition']) !== '') {
            $compiled = TicketQuery::compile((string) $rule['condition'], $actorId, null, (new CustomFieldRepository($this->db))->kindsByName());
            $where[] = $compiled['where'] === '' ? '1 = 1' : $compiled['where'];
            $params = $compiled['params'];
        }

        if ($rule['project_id'] !== null) {
            $where[] = 't.project_id = :auto_project';
            $params['auto_project'] = (int) $rule['project_id'];
        }

        if ($ticketId !== null) {
            $where[] = 't.id = :auto_ticket';
            $params['auto_ticket'] = $ticketId;
        }

        $statement = $this->db->prepare(
            'SELECT t.id FROM tickets t
             JOIN projects p ON p.id = t.project_id
             JOIN statuses s ON s.id = t.status_id
             LEFT JOIN sprints sp ON sp.id = t.sprint_id
             LEFT JOIN releases rl ON rl.id = t.release_id
             LEFT JOIN epics e ON e.id = t.epic_id
             LEFT JOIN users a ON a.id = t.assignee_id
             WHERE p.is_archived = 0 AND ' . implode(' AND ', $where) . ' LIMIT 500'
        );
        $statement->execute($params);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Does what a rule says to a ticket, in the name of whoever made the rule,
     * with each line of the history marked as the rule's. A failed action
     * stops the rule there, and the log says why.
     */
    private function run(array $rule, int $ticketId, ?int $actorId): bool
    {
        $actor = $rule['created_by'] === null ? $actorId : (int) $rule['created_by'];
        self::$running = true;
        Activity::$via = (string) $rule['name'];
        $said = [];

        try {
            foreach ($rule['actions'] as $action) {
                $ticket = $this->ticket($ticketId);
                if ($ticket === null) {
                    break;
                }
                $said[] = $this->act($ticket, (string) ($action['type'] ?? ''), trim((string) ($action['value'] ?? '')), $actor, $actorId);
            }

            $this->rules->log((int) $rule['id'], $ticketId, 'done', implode(' · ', array_filter($said)));

            return true;
        } catch (Throwable $e) {
            $this->rules->log((int) $rule['id'], $ticketId, 'failed', $e->getMessage());

            return false;
        } finally {
            self::$running = false;
            Activity::$via = null;
        }
    }

    /**
     * One action on one ticket; what it did, in a few words, for the log.
     *
     * @throws ValidationError
     */
    private function act(array $ticket, string $type, string $value, ?int $actor, ?int $triggeredBy): string
    {
        $service = new TicketService($this->db);
        $id = (int) $ticket['id'];

        switch ($type) {
            case 'status':
                $service->changeStatus($id, $value, $actor, null, true);

                return __('moved to {value}', ['value' => $value]);

            case 'assign':
                $person = $this->person($value, $ticket, $triggeredBy);
                $service->update($id, ['assignee_id' => $person], $actor);

                return $person === null ? __('unassigned') : __('assigned to {value}', ['value' => (string) ((new UserRepository($this->db))->find($person)['name'] ?? $value)]);

            case 'resolution':
                $service->resolve($id, $value, $actor);

                return __('resolved as {value}', ['value' => $value]);

            case 'due':
                $due = self::due($value, new \DateTimeImmutable('today'));
                $service->update($id, ['due_on' => $due ?? ''], $actor);

                return $due === null ? __('no due date') : __('due {value}', ['value' => $due]);

            case 'field':
                [$name, $given] = array_map('trim', explode('=', $value, 2) + [1 => '']);
                $known = array_filter(
                    (new CustomFieldRepository($this->db))->forProject((int) $ticket['project_id']),
                    static fn(array $f): bool => mb_strtolower((string) $f['name']) === mb_strtolower($name)
                );
                if ($known === []) {
                    throw new ValidationError(__('The project has no field called “{value}”.', ['value' => $name]));
                }
                $service->update($id, ['fields' => [$name => $given]], $actor);

                return $name . ' = ' . $given;

            case 'watch':
                $person = $this->person($value, $ticket, $triggeredBy);
                if ($person === null) {
                    throw new ValidationError(__('Nobody to follow it: name somebody.'));
                }
                (new \CantoTrack\Model\NotificationRepository($this->db))->watch($id, $person);

                return __('followed by {value}', ['value' => (string) ((new UserRepository($this->db))->find($person)['name'] ?? $value)]);

            case 'subtask':
                if ($actor === null) {
                    throw new ValidationError(__('A subtask needs somebody to make it: the rule has no maker any more.'));
                }
                $service->addSubtask($ticket, $this->filled($value, $ticket), null, $actor);

                return __('added a subtask');

            case 'priority':
                if (!in_array(strtolower($value), TicketRepository::PRIORITIES, true)) {
                    throw new ValidationError(__('“{value}” is not a priority: low, normal, high or urgent.', ['value' => $value]));
                }
                $service->update($id, ['priority' => strtolower($value)], $actor);

                return __('priority {value}', ['value' => $value]);

            case 'add_label':
            case 'remove_label':
                $labels = (new LabelRepository($this->db))->forTicket($id);
                $labels = $type === 'add_label'
                    ? array_values(array_unique(array_merge($labels, [$value])))
                    : array_values(array_filter($labels, static fn(string $l): bool => mb_strtolower($l) !== mb_strtolower($value)));
                $service->update($id, ['labels' => $labels], $actor);

                return ($type === 'add_label' ? '+' : '−') . $value;

            case 'comment':
                if ($actor === null) {
                    throw new ValidationError(__('A comment needs somebody to write it: the rule has no maker any more.'));
                }
                (new CommentService($this->db))->add($id, $actor, $this->filled($value, $ticket));

                return __('commented');

            case 'sprint':
                $sprint = strtolower(trim($value)) === 'backlog' ? null : $this->runningSprint($ticket, $value);
                (new SprintService($this->db))->assign([$id], $sprint === null ? null : (int) $sprint['id'], $actor);

                return $sprint === null ? __('to the backlog') : __('into {value}', ['value' => (string) $sprint['name']]);
        }

        throw new ValidationError(__('The rule has an action it does not know: “{value}”.', ['value' => $type]));
    }

    /**
     * The running sprint a rule means: "active" is the one on the project's
     * own board, or — when that has none — the one running on the only
     * shared board of the project that has one; "active: Board name" the one
     * on that board.
     *
     * @throws ValidationError
     */
    private function runningSprint(array $ticket, string $value): array
    {
        $running = (new SprintRepository($this->db))->activeForProject((int) $ticket['project_id']);
        $named = trim((string) (explode(':', $value, 2)[1] ?? ''));

        if ($named !== '') {
            foreach ($running as $sprint) {
                if (mb_strtolower((string) $sprint['board_name']) === mb_strtolower($named)) {
                    return $sprint;
                }
            }

            throw new ValidationError(__('No sprint is running on {board} for this project.', ['board' => $named]));
        }

        foreach ($running as $sprint) {
            if ($sprint['board_project_id'] !== null) {
                return $sprint;
            }
        }

        if (count($running) === 1) {
            return $running[0];
        }

        throw new ValidationError($running === []
            ? __('The project has no sprint running.')
            : __('Sprints are running on several boards of the project; name one: “active: {board}”.', ['board' => $running[0]['board_name']]));
    }

    /**
     * Whom "assign to" means: "reporter", "actor" (who set the rule off),
     * "nobody", or somebody by name, e-mail or handle.
     *
     * @throws ValidationError
     */
    private function person(string $value, array $ticket, ?int $triggeredBy): ?int
    {
        $word = mb_strtolower($value);

        if (in_array($word, ['nobody', 'none', 'unassigned', ''], true)) {
            return null;
        }
        if ($word === 'reporter') {
            return $ticket['reporter_id'] === null ? null : (int) $ticket['reporter_id'];
        }
        if ($word === 'actor') {
            return $triggeredBy;
        }

        foreach ((new UserRepository($this->db))->active() as $user) {
            if (in_array($word, [mb_strtolower((string) $user['name']), mb_strtolower((string) $user['email']), mb_strtolower((string) ($user['handle'] ?? ''))], true)) {
                return (int) $user['id'];
            }
        }

        throw new ValidationError(__('Nobody active is called “{value}”.', ['value' => $value]));
    }

    /** An action's words with the ticket's own filled in: {key}, {title}, {assignee}, {due}. */
    private function filled(string $value, array $ticket): string
    {
        return strtr($value, [
            '{key}' => $ticket['project_code'] . '-' . $ticket['number'],
            '{title}' => (string) $ticket['title'],
            '{assignee}' => (string) ($ticket['assignee_name'] ?? ''),
            '{due}' => (string) ($ticket['due_on'] ?? ''),
        ]);
    }

    /**
     * A due date as a rule gives it: "today", "+3d", "+2w", "-1d", a date,
     * or "none" for no date at all.
     *
     * @throws ValidationError
     */
    public static function due(string $value, \DateTimeImmutable $today): ?string
    {
        $value = strtolower(trim($value));

        if (in_array($value, ['', 'none', 'nothing'], true)) {
            return null;
        }
        if ($value === 'today') {
            return $today->format('Y-m-d');
        }
        if (preg_match('/^([+-]\d{1,3})\s*([dw])$/', $value, $m) === 1) {
            return $today->modify(((int) $m[1] * ($m[2] === 'w' ? 7 : 1)) . ' days')->format('Y-m-d');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false) {
            return $value;
        }

        throw new ValidationError(__('“{value}” is not a due date: today, +3d, +2w, a date, or none.', ['value' => $value]));
    }

    private function subtasksAllDone(int $parentId): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) AS total, SUM(s.category = \'done\') AS done FROM tickets t JOIN statuses s ON s.id = t.status_id WHERE t.parent_id = :parent'
        );
        $statement->execute(['parent' => $parentId]);
        $row = $statement->fetch();

        return $row !== false && (int) $row['total'] > 0 && (int) $row['total'] === (int) $row['done'];
    }

    /** A ticket whoever is looking, for a rule that works for the whole team. */
    private function ticket(int $id): ?array
    {
        $row = $this->tickets->find($id);

        return $row;
    }

    /** For the tests: whatever is waiting is dropped. */
    public static function forget(): void
    {
        self::$queue = [];
        self::$running = false;
    }
}
