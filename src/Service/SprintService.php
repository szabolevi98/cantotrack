<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\SprintRepository;
use CantoTrack\Model\TicketRepository;
use PDO;

/**
 * Sprints: planning them, starting one, and closing it.
 *
 * A project has at most one sprint running. Starting it writes down what it
 * set out to do — the points and the number of tickets — because that is
 * what the burndown and the velocity measure against, and a sprint's
 * contents change while it runs. Closing it writes down what got done, and
 * sends whatever did not get done on: back to the backlog, or into the next
 * planned sprint.
 */
class SprintService
{
    /** A sprint's length when nobody says otherwise: two weeks. */
    public const DEFAULT_DAYS = 14;

    private SprintRepository $sprints;
    private TicketRepository $tickets;
    private ProjectRepository $projects;
    private Activity $activity;

    public function __construct(?PDO $db = null)
    {
        $db ??= DatabaseConnection::get();

        $this->sprints = new SprintRepository($db);
        $this->tickets = new TicketRepository($db);
        $this->projects = new ProjectRepository($db);
        $this->activity = new Activity($db);
    }

    /** @throws ValidationError */
    public function create(int $projectId, string $name, string $goal, string $startsOn, string $endsOn): int
    {
        $project = $this->projects->find($projectId);

        if ($project === null) {
            throw new ValidationError(__('There is no such project.'));
        }

        $name = trim($name) ?: $project['code'] . ' ' . __('Sprint {number}', ['number' => $this->sprints->countForProject($projectId) + 1]);
        [$start, $end] = $this->dates($startsOn, $endsOn);

        return $this->sprints->create($projectId, mb_substr($name, 0, 80), trim($goal) ?: null, $start, $end);
    }

    /** @throws ValidationError */
    public function update(array $sprint, string $name, string $goal, string $startsOn, string $endsOn): void
    {
        if (trim($name) === '') {
            throw new ValidationError(__('A sprint needs a name.'));
        }

        [$start, $end] = $this->dates($startsOn, $endsOn);

        $this->sprints->update((int) $sprint['id'], mb_substr(trim($name), 0, 80), trim($goal) ?: null, $start, $end);
    }

    /** @throws ValidationError */
    public function start(array $sprint): void
    {
        if ($sprint['state'] !== 'planned') {
            throw new ValidationError(__('Only a planned sprint can be started.'));
        }

        if ($this->sprints->active((int) $sprint['project_id']) !== null) {
            throw new ValidationError(__('Another sprint is already running in this project. Close it first.'));
        }

        $tickets = $this->sprints->tickets((int) $sprint['id']);

        if ($tickets === []) {
            throw new ValidationError(__('An empty sprint has nothing to start. Put some tickets in it first.'));
        }

        $this->sprints->start((int) $sprint['id'], self::points($tickets), count($tickets));
    }

    /**
     * Closes a running sprint. What is not finished goes to `$carryTo` — the
     * id of a planned sprint — or back to the backlog when that is null.
     *
     * @throws ValidationError
     */
    public function close(array $sprint, ?int $carryTo, ?int $actorId): void
    {
        if ($sprint['state'] !== 'active') {
            throw new ValidationError(__('Only a running sprint can be closed.'));
        }

        $next = $carryTo === null ? null : $this->sprints->find($carryTo);
        if ($carryTo !== null && ($next === null || (int) $next['project_id'] !== (int) $sprint['project_id'] || $next['state'] !== 'planned')) {
            throw new ValidationError(__('Unfinished work can only go on to a planned sprint of the same project.'));
        }

        $tickets = $this->sprints->tickets((int) $sprint['id']);
        $done = array_filter($tickets, static fn(array $t): bool => $t['category'] === 'done');
        $open = array_values(array_filter($tickets, static fn(array $t): bool => $t['category'] !== 'done'));

        $this->sprints->close((int) $sprint['id'], self::points($done), count($done));

        $openIds = array_map(static fn(array $t): int => (int) $t['id'], $open);
        $this->sprints->assign($openIds, $next === null ? null : (int) $next['id']);

        foreach ($openIds as $id) {
            $ticket = $this->tickets->find($id);
            if ($ticket !== null) {
                $this->activity->happened($ticket, $actorId, 'sprint', 'sprint', (string) $sprint['name'], $next['name'] ?? null);
            }
        }
    }

    /**
     * Puts tickets into a sprint, or back in the backlog with null. A closed
     * sprint takes nothing more: what it did is what it did.
     *
     * @param list<int> $ticketIds
     * @throws ValidationError
     */
    public function assign(array $ticketIds, ?int $sprintId, ?int $actorId): void
    {
        $sprint = $sprintId === null ? null : $this->sprints->find($sprintId);

        if ($sprintId !== null && ($sprint === null || $sprint['state'] === 'closed')) {
            throw new ValidationError(__('That sprint is closed, or does not exist.'));
        }

        foreach ($ticketIds as $id) {
            $ticket = $this->tickets->find($id);

            if ($ticket === null || ($sprint !== null && (int) $ticket['project_id'] !== (int) $sprint['project_id'])) {
                continue;
            }

            if ((int) $ticket['sprint_id'] === (int) $sprintId) {
                continue;
            }

            $this->sprints->assign([$id], $sprintId);
            $this->activity->happened($ticket, $actorId, 'sprint', 'sprint', $ticket['sprint_name'] ?? null, $sprint['name'] ?? null);
        }
    }

    /** A planned sprint can be deleted; its tickets go back to the backlog. */
    public function delete(array $sprint): void
    {
        if ($sprint['state'] !== 'planned') {
            throw new ValidationError(__('Only a sprint that has not started can be deleted.'));
        }

        $this->sprints->delete((int) $sprint['id']);
    }

    /**
     * The burndown of a sprint: for each day from its start to its end (or to
     * today, while it runs), how much of what it holds was still not finished
     * at the end of that day — beside the ideal straight line from the
     * committed amount down to nothing.
     *
     * In points when the sprint's tickets carry points, and in tickets when
     * none of them do: a burndown of zeros says nothing.
     *
     * @return array{unit: string, days: list<array{day: string, remaining: ?int, ideal: float}>, total: int}
     */
    public function burndown(array $sprint): array
    {
        // A closed sprint's unfinished tickets have moved on; they are counted
        // as still open to its last day, which is what they were.
        $tickets = array_merge($this->sprints->tickets((int) $sprint['id']), $this->sprints->carriedOut($sprint));
        $byPoints = self::points($tickets) > 0;
        $size = static fn(array $t): int => $byPoints ? (int) $t['story_points'] : 1;

        $start = new \DateTimeImmutable((string) ($sprint['starts_on'] ?: substr((string) $sprint['started_at'], 0, 10) ?: 'today'));
        $end = new \DateTimeImmutable((string) ($sprint['ends_on'] ?: $start->modify('+' . self::DEFAULT_DAYS . ' days')->format('Y-m-d')));
        $today = new \DateTimeImmutable('today');

        $committed = $byPoints ? (int) ($sprint['committed_points'] ?? 0) : (int) ($sprint['committed_count'] ?? 0);
        $total = max($committed, array_sum(array_map($size, $tickets)));
        $length = max(1, (int) $start->diff($end)->days);

        $days = [];
        for ($day = $start, $i = 0; $day <= $end; $day = $day->modify('+1 day'), $i++) {
            $date = $day->format('Y-m-d');
            $remaining = null;

            if ($day <= $today || $sprint['state'] === 'closed') {
                $remaining = 0;
                foreach ($tickets as $ticket) {
                    $closed = $ticket['closed_at'] === null ? null : substr((string) $ticket['closed_at'], 0, 10);

                    if ($closed === null || $closed > $date) {
                        $remaining += $size($ticket);
                    }
                }
            }

            $days[] = ['day' => $date, 'remaining' => $remaining, 'ideal' => round($total * (1 - $i / $length), 1)];
        }

        return ['unit' => $byPoints ? 'points' : 'tickets', 'days' => $days, 'total' => $total];
    }

    /** @return array{0: ?string, 1: ?string} */
    private function dates(string $startsOn, string $endsOn): array
    {
        $start = self::date($startsOn);
        $end = self::date($endsOn);

        if ($start !== null && $end === null) {
            $end = (new \DateTimeImmutable($start))->modify('+' . (self::DEFAULT_DAYS - 1) . ' days')->format('Y-m-d');
        }

        if ($start !== null && $end !== null && $end < $start) {
            throw new ValidationError(__('A sprint cannot end before it starts.'));
        }

        return [$start, $end];
    }

    private static function date(string $given): ?string
    {
        $given = trim($given);

        if ($given === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $given);

        if ($date === false || $date->format('Y-m-d') !== $given) {
            throw new ValidationError(__('That date does not look like a date.'));
        }

        return $given;
    }

    /** @param array<array> $tickets */
    private static function points(array $tickets): int
    {
        return array_sum(array_map(static fn(array $t): int => (int) $t['story_points'], $tickets));
    }
}
