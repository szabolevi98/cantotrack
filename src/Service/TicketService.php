<?php

namespace CantoTrack\Service;

use CantoTrack\Core\ConflictError;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Format;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\AttachmentRepository;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\LabelRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\ReleaseRepository;
use CantoTrack\Model\SprintRepository;
use CantoTrack\Model\StatusRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use PDO;

/**
 * The rules about tickets, in one place: what a ticket may be created with,
 * what an edit may change, and when one may be deleted.
 *
 * They used to live in the controller, which read the form and wrote whatever
 * it said. That is how a ticket could be given an assignee that did not exist
 * (and the page answered 500), an epic from another project, or a place in an
 * archived project that "takes no new tickets". The controller, the API and the
 * bulk edit all come through here, so a rule is kept the same way whichever
 * door the change came in by.
 */
class TicketService
{
    private TicketRepository $tickets;
    private ProjectRepository $projects;
    private EpicRepository $epics;
    private UserRepository $users;
    private StatusRepository $statuses;
    private LabelRepository $labels;
    private Activity $activity;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $db ??= DatabaseConnection::get();
        $this->db = $db;

        $this->tickets = new TicketRepository($db);
        $this->projects = new ProjectRepository($db);
        $this->epics = new EpicRepository($db);
        $this->users = new UserRepository($db);
        $this->statuses = new StatusRepository($db);
        $this->labels = new LabelRepository($db);
        $this->activity = new Activity($db);
    }

    /**
     * @param array<string, mixed> $input what the form or the API sent
     * @throws ValidationError
     */
    public function create(array $input, int $reporterId): int
    {
        $project = $this->projects->find((int) ($input['project_id'] ?? 0));

        if ($project === null) {
            throw new ValidationError(__('Pick a project.'));
        }

        if ((int) $project['is_archived'] === 1) {
            throw new ValidationError(__('{code} is archived and takes no new tickets.', ['code' => $project['code']]));
        }

        $projectId = (int) $project['id'];
        $status = $this->status($projectId, $input['status'] ?? '');
        $labels = LabelRepository::parse($input['labels'] ?? '');
        $parent = $this->parent($input, $projectId, null);
        $fields = new CustomFields($this->db);
        $values = $fields->checked($projectId, is_array($input['fields'] ?? null) ? $input['fields'] : null, true);

        $id = $this->tickets->create([
            'project_id' => $projectId,
            'type' => $this->type($input['type'] ?? 'task'),
            // A subtask is part of its parent's work, and so of its epic.
            'epic_id' => $parent !== null ? $parent['epic_id'] : $this->epic($input['epic_id'] ?? null, $projectId, null),
            'parent_id' => $parent !== null ? (int) $parent['id'] : null,
            // A subtask ships with its parent.
            'release_id' => $parent !== null ? $parent['release_id'] : $this->release($input, $projectId, null),
            'title' => $this->title($input['title'] ?? ''),
            'description' => (string) ($input['description'] ?? ''),
            'status_id' => (int) $status['id'],
            'status_category' => (string) $status['category'],
            'priority' => $this->priority($input['priority'] ?? 'normal'),
            'assignee_id' => $this->assignee($input['assignee_id'] ?? null, null),
            // Whoever wrote it down. Not editable afterwards: it is a fact about
            // the past, not a field.
            'reporter_id' => $reporterId,
            'estimate_minutes' => $this->estimate($input),
            'due_on' => $this->due($input['due_on'] ?? ''),
            'story_points' => $this->points($input['story_points'] ?? ''),
        ]);

        if ($labels !== []) {
            $this->labels->sync($id, $labels);
        }

        // And of its parent's sprint: a step of the work is planned with it.
        if ($parent !== null && $parent['sprint_id'] !== null) {
            (new SprintRepository($this->db))->assign([$id], (int) $parent['sprint_id']);
        }

        // The project's own fields, without a line each in the history: the
        // ticket was created with them.
        $fields->save((array) $this->tickets->find($id), $values, $reporterId, true);

        $this->activity->happened((array) $this->tickets->find($id), $reporterId, 'created');

        return $id;
    }

    /**
     * A subtask of a ticket, from the ticket's own page: a title and, if
     * given, whose it is — the rest comes from the parent.
     *
     * @throws ValidationError
     */
    public function addSubtask(array $parent, string $title, mixed $assigneeId, int $reporterId): int
    {
        return $this->create([
            'project_id' => (int) $parent['project_id'],
            'parent_id' => (int) $parent['id'],
            'title' => $title,
            'assignee_id' => $assigneeId,
            'type' => $parent['type'] === 'bug' ? 'bug' : 'task',
        ], $reporterId);
    }

    /**
     * Saves an edit made against a particular version of the ticket.
     *
     * A field that is not in the input keeps the value it has: the form sends
     * every field, and an API client changing only the priority sends only the
     * priority.
     *
     * @param array<string, mixed> $input
     * @throws ValidationError when something in it does not make sense
     * @throws ConflictError when somebody else saved the ticket in between
     */
    public function update(int $id, array $input, ?int $actorId = null): void
    {
        $ticket = $this->tickets->find($id);

        if ($ticket === null) {
            throw new ValidationError(__('There is no such ticket.'));
        }

        $has = static fn(string $key): bool => array_key_exists($key, $input);
        $projectId = (int) $ticket['project_id'];
        $parent = $has('parent') || $has('parent_id')
            ? $this->parent($input, $projectId, $ticket)
            : ($ticket['parent_id'] === null ? null : $this->tickets->find((int) $ticket['parent_id']));

        $data = [
            'type' => $has('type') ? $this->type($input['type']) : $ticket['type'],
            'epic_id' => $parent !== null
                ? $parent['epic_id']
                : ($has('epic_id') ? $this->epic($input['epic_id'], $projectId, $ticket['epic_id']) : $ticket['epic_id']),
            'parent_id' => $parent === null ? null : (int) $parent['id'],
            'release_id' => $parent !== null
                ? $parent['release_id']
                : ($has('release') || $has('release_id') ? $this->release($input, $projectId, $ticket['release_id']) : $ticket['release_id']),
            'title' => $has('title') ? $this->title($input['title']) : $ticket['title'],
            'description' => $has('description') ? (string) $input['description'] : (string) $ticket['description'],
            'priority' => $has('priority') ? $this->priority($input['priority']) : $ticket['priority'],
            'assignee_id' => $has('assignee_id') ? $this->assignee($input['assignee_id'], $ticket['assignee_id']) : $ticket['assignee_id'],
            'estimate_minutes' => $has('estimate') || $has('estimate_minutes') ? $this->estimate($input) : $ticket['estimate_minutes'],
            'due_on' => $has('due_on') ? $this->due($input['due_on']) : $ticket['due_on'],
            'story_points' => $has('story_points') ? $this->points($input['story_points']) : $ticket['story_points'],
        ];

        $oldLabels = $this->labels->forTicket($id);
        $newLabels = $has('labels') ? LabelRepository::parse($input['labels']) : $oldLabels;
        $fields = new CustomFields($this->db);
        $values = $has('fields') && is_array($input['fields']) ? $fields->checked($projectId, $input['fields'], false) : [];

        // No version given (an API client that does not care) means "whatever
        // is there now" — the last-write-wins it asked for by leaving it out.
        $version = isset($input['version']) && $input['version'] !== ''
            ? (int) $input['version']
            : (int) $ticket['version'];

        if (!$this->tickets->update($id, $data, $version)) {
            throw new ConflictError(
                __('Somebody else saved this ticket while you were editing it. Their version is shown below; what you typed is still in the form.'),
                (array) $this->tickets->find($id)
            );
        }

        if ($newLabels !== $oldLabels) {
            $this->labels->sync($id, $newLabels);
        }

        // A parent's epic is its subtasks' too; a ticket that has just become
        // a subtask goes into its parent's sprint.
        if ((int) $data['epic_id'] !== (int) $ticket['epic_id']) {
            $this->tickets->setSubtasksEpic($id, $data['epic_id'] === null ? null : (int) $data['epic_id']);
        }

        if ((int) $data['release_id'] !== (int) $ticket['release_id']) {
            $this->tickets->setSubtasksRelease($id, $data['release_id'] === null ? null : (int) $data['release_id']);
        }

        if ($parent !== null && (int) $parent['id'] !== (int) $ticket['parent_id'] && $parent['sprint_id'] !== null
            && (int) $parent['sprint_id'] !== (int) $ticket['sprint_id']) {
            (new SprintService($this->db))->assign([$id], (int) $parent['sprint_id'], $actorId);
        }

        $this->recordChanges($ticket, (array) $this->tickets->find($id), $oldLabels, $newLabels, $actorId);
        $fields->save((array) $this->tickets->find($id), $values, $actorId);
    }

    /**
     * The release a ticket ships in — by its id or its name — or null for
     * none. One of the project's own that is still coming; the one it is in
     * already is kept even after it went out, so that editing a title does
     * not take a shipped ticket out of what it shipped in.
     *
     * @param array<string, mixed> $input
     * @throws ValidationError
     */
    private function release(array $input, int $projectId, mixed $current): ?int
    {
        $given = trim((string) ($input['release_id'] ?? $input['release'] ?? ''));

        if ($given === '' || $given === '0') {
            return null;
        }

        $release = (new ReleaseRepository($this->db))->resolve($projectId, $given);

        if ($release === null) {
            throw new ValidationError(__('The project has no release “{value}”.', ['value' => $given]));
        }

        if ($current !== null && (int) $release['id'] === (int) $current) {
            return (int) $current;
        }

        if ($release['released_at'] !== null) {
            throw new ValidationError(__('{name} is out already and takes no new tickets.', ['name' => $release['name']]));
        }

        return (int) $release['id'];
    }

    /**
     * The ticket a ticket is a subtask of — by its key ("CT-14", from a form
     * or the API) or its id — or null for none.
     *
     * One level only: the parent cannot itself be a subtask, a ticket that
     * has subtasks cannot become one, and both are in the same project.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $ticket the ticket being changed, if it exists already
     * @throws ValidationError
     */
    private function parent(array $input, int $projectId, ?array $ticket): ?array
    {
        $given = trim((string) ($input['parent'] ?? $input['parent_id'] ?? ''));

        if ($given === '' || $given === '0') {
            return null;
        }

        $parent = ctype_digit($given) ? $this->tickets->find((int) $given) : $this->tickets->findByKey($given);

        if ($parent === null) {
            throw new ValidationError(__('There is no ticket called {key}.', ['key' => strtoupper($given)]));
        }

        if ($ticket !== null && (int) $parent['id'] === (int) $ticket['id']) {
            throw new ValidationError(__('A ticket cannot be its own subtask.'));
        }

        if ((int) $parent['project_id'] !== $projectId) {
            throw new ValidationError(__('A subtask is in the same project as its parent.'));
        }

        if ($parent['parent_id'] !== null) {
            throw new ValidationError(__('{key} is a subtask itself; subtasks go one level deep.', ['key' => $parent['project_code'] . '-' . $parent['number']]));
        }

        if ($ticket !== null && (int) ($ticket['subtask_count'] ?? 0) > 0) {
            throw new ValidationError(__('This ticket has subtasks of its own, so it cannot become one.'));
        }

        return $parent;
    }

    /**
     * Moves a ticket to another column — one the column it is in lets it go
     * to (see the 0031 migration). Into a done column it goes with a
     * resolution, "done" unless somebody said otherwise.
     *
     * `$anyMove` is for the changes nobody drags: an automation rule, a
     * commit that says "fixes CT-14". Whoever set those up decided where
     * the ticket goes, and the columns' moves are for people.
     *
     * @throws ValidationError
     */
    public function changeStatus(int $id, string $status, ?int $actorId = null, ?string $resolution = null, bool $anyMove = false): void
    {
        $ticket = $this->tickets->find($id);

        if ($ticket === null) {
            throw new ValidationError(__('There is no such ticket.'));
        }

        $column = $this->status((int) $ticket['project_id'], $status);
        $resolution = $resolution === null || $resolution === '' ? null : $this->resolution($resolution);

        if ((int) $column['id'] === (int) $ticket['status_id']) {
            if ($resolution !== null) {
                $this->resolve($id, $resolution, $actorId);
            }

            return;
        }

        $from = $this->statuses->find((int) $ticket['status_id']);

        if (!$anyMove && $from !== null && !StatusRepository::allows($from, (int) $column['id'])) {
            throw new ValidationError(__('{key} cannot go from {from} to {to}: the project’s workflow does not allow that move.', [
                'key' => $ticket['project_code'] . '-' . $ticket['number'],
                'from' => __((string) $from['name']),
                'to' => __((string) $column['name']),
            ]));
        }

        $this->tickets->changeStatus($id, (int) $column['id'], (string) $column['category'], $resolution);
        $after = (array) $this->tickets->find($id);

        $this->activity->happened(
            $after,
            $actorId,
            'status',
            'status',
            (string) $ticket['status_name'],
            (string) $column['name']
        );

        // "Done" goes without saying; anything else is worth a line.
        if ($after['resolution'] !== null && $after['resolution'] !== 'done') {
            $this->activity->happened($after, $actorId, 'changed', 'resolution', null, TicketRepository::RESOLUTIONS[$after['resolution']]);
        }
    }

    /**
     * Why a finished ticket is finished, changed after it got there — "won't
     * do" rather than "done", say.
     *
     * @throws ValidationError
     */
    public function resolve(int $id, string $resolution, ?int $actorId = null): void
    {
        $ticket = $this->tickets->find($id);

        if ($ticket === null) {
            throw new ValidationError(__('There is no such ticket.'));
        }

        if ($ticket['status_category'] !== 'done') {
            throw new ValidationError(__('Only a finished ticket has a resolution.'));
        }

        $resolution = $this->resolution($resolution);

        if ($resolution === $ticket['resolution']) {
            return;
        }

        $this->tickets->setResolution($id, $resolution);

        $this->activity->happened(
            (array) $this->tickets->find($id),
            $actorId,
            'changed',
            'resolution',
            $ticket['resolution'] === null ? null : TicketRepository::RESOLUTIONS[$ticket['resolution']] ?? null,
            TicketRepository::RESOLUTIONS[$resolution]
        );
    }

    /**
     * A resolution by its key or by what it is called ("Won't do", "wont
     * do", "wont_do" all read the same).
     *
     * @throws ValidationError
     */
    private function resolution(string $given): string
    {
        $plain = (string) preg_replace('/[^a-z]/', '', mb_strtolower(str_replace('’', "'", $given)));

        foreach (TicketRepository::RESOLUTIONS as $key => $name) {
            if ($plain === str_replace('_', '', $key) || $plain === (string) preg_replace('/[^a-z]/', '', mb_strtolower(str_replace('’', "'", $name)))) {
                return $key;
            }
        }

        throw new ValidationError(__('There is no resolution “{value}”.', ['value' => $given]));
    }

    /**
     * A card dragged on the board: into a column, between two others, and —
     * dropped into another swimlane — to another person or epic.
     *
     * Every part of it goes through the same rules as the forms: the column
     * has to be the project's, the person active, the epic the project's.
     *
     * @param array{assignee_id?: mixed, epic_id?: mixed} $lane
     * @throws ValidationError
     */
    public function move(int $id, string $status, ?int $aboveId, ?int $belowId, array $lane, ?int $actorId, array $columnStatusIds = []): void
    {
        $ticket = $this->tickets->find($id);

        if ($ticket === null) {
            throw new ValidationError(__('There is no such ticket.'));
        }

        // A move the workflow refuses is refused before the lane changes
        // anything, so a refused drop leaves the ticket as it was.
        $column = $this->status((int) $ticket['project_id'], $status);
        $from = $this->statuses->find((int) $ticket['status_id']);
        if ($from !== null && !StatusRepository::allows($from, (int) $column['id'])) {
            $this->changeStatus($id, $status, $actorId);
        }

        // Neighbours from another project are not neighbours; the card goes
        // to the end of the column instead of somewhere meaningless. On a
        // shared board they are, when they are in the same column.
        foreach ([&$aboveId, &$belowId] as &$neighbour) {
            if ($neighbour !== null) {
                $other = $this->tickets->find($neighbour);
                $sameColumn = $other !== null && in_array((int) $other['status_id'], $columnStatusIds, true);
                if ($other === null || $neighbour === $id || ((int) $other['project_id'] !== (int) $ticket['project_id'] && !$sameColumn)) {
                    $neighbour = null;
                }
            }
        }
        unset($neighbour);

        if ($lane !== []) {
            $this->update($id, $lane, $actorId);
        }

        $this->changeStatus($id, $status, $actorId);

        $column = $this->status((int) $ticket['project_id'], $status);
        $this->tickets->place($id, (int) $column['id'], $aboveId, $belowId, $columnStatusIds);
    }

    /**
     * One history row per field that actually changed, with the values in the
     * words people saw — see the 0007 migration for why.
     *
     * @param list<string> $oldLabels
     * @param list<string> $newLabels
     */
    private function recordChanges(array $before, array $after, array $oldLabels, array $newLabels, ?int $actorId): void
    {
        $shown = [
            'title' => static fn(array $t): string => (string) $t['title'],
            'type' => static fn(array $t): string => ucfirst((string) $t['type']),
            'priority' => static fn(array $t): string => ucfirst((string) $t['priority']),
            'assignee' => static fn(array $t): string => (string) ($t['assignee_name'] ?? ''),
            'epic' => static fn(array $t): string => (string) ($t['epic_title'] ?? ''),
            'parent' => static fn(array $t): string => $t['parent_id'] === null ? '' : $t['project_code'] . '-' . $t['parent_number'],
            'release' => static fn(array $t): string => (string) ($t['release_name'] ?? ''),
            'estimate' => static fn(array $t): string => $t['estimate_minutes'] ? Format::duration((int) $t['estimate_minutes']) : '',
            'due' => static fn(array $t): string => (string) ($t['due_on'] ?? ''),
            'points' => static fn(array $t): string => $t['story_points'] === null ? '' : (string) $t['story_points'],
        ];

        foreach ($shown as $field => $value) {
            $old = $value($before);
            $new = $value($after);

            if ($old !== $new) {
                $this->activity->happened($after, $actorId, 'changed', $field, $old ?: null, $new ?: null);
            }
        }

        // The description is long; the history says that it changed, not what
        // it said — the ticket itself shows what it says now.
        if (trim((string) $before['description']) !== trim((string) $after['description'])) {
            $this->activity->happened($after, $actorId, 'changed', 'description');
        }

        if ($oldLabels !== $newLabels) {
            $this->activity->happened($after, $actorId, 'changed', 'labels', implode(', ', $oldLabels) ?: null, implode(', ', $newLabels) ?: null);
        }
    }

    /**
     * Deletes a ticket that has no hours on it.
     *
     * One with hours cannot be deleted: those hours are what gets reported and
     * invoiced, and a ticket delete used to take them along without a word.
     * Somebody who really means it deletes the hours first, one by one, which
     * is slow on purpose.
     *
     * @throws ValidationError
     */
    public function delete(int $id): void
    {
        // Its subtasks would be left behind as tickets of their own, which
        // is a decision for a person, one by one, not a side effect.
        if ($this->tickets->subtaskIds($id) !== []) {
            throw new ValidationError(__('This ticket has subtasks. Delete them first, or make them tickets of their own.'));
        }

        if ($this->tickets->hasWorklogs($id)) {
            throw new ValidationError(
                __('This ticket has hours logged against it, so it cannot be deleted. Move it to done instead — or delete the hours first if they really are a mistake.')
            );
        }

        // The files first: the rows about them go with the ticket, and the
        // bytes would stay on disk with nothing pointing at them.
        $files = (new AttachmentRepository($this->db))->pathsForTickets('t.id = :id', ['id' => $id]);

        $this->tickets->delete($id);

        AttachmentService::unlinkAll($files);
    }

    private function title(mixed $given): string
    {
        $title = trim((string) $given);

        if ($title === '') {
            throw new ValidationError(__('A ticket needs a title.'));
        }

        if (mb_strlen($title) > 250) {
            throw new ValidationError(__('A title can be at most {count} characters.', ['count' => 250]));
        }

        return $title;
    }

    /**
     * The estimate, in minutes, from either what a person typed ("1h 30m") or
     * the number an API client sent. Empty means no estimate; something that
     * cannot be read is refused rather than taken as "none", which is how a
     * typo turns into a silently empty field.
     */
    private function estimate(array $input): ?int
    {
        if (array_key_exists('estimate_minutes', $input) && !array_key_exists('estimate', $input)) {
            $minutes = $input['estimate_minutes'];

            return $minutes === null || $minutes === '' ? null : (max(0, (int) $minutes) ?: null);
        }

        $given = trim((string) ($input['estimate'] ?? ''));

        if ($given === '') {
            return null;
        }

        $minutes = Format::parseDuration($given);

        if ($minutes === null) {
            throw new ValidationError(__('The estimate should read like "3h", "90m" or "1h 30m".'));
        }

        return $minutes ?: null;
    }

    /**
     * An epic, if it belongs to the ticket's own project. The one the ticket
     * already has is kept even if it has since been marked done — an edit that
     * changes the title should not throw the ticket out of its epic.
     */
    private function epic(mixed $given, int $projectId, mixed $current): ?int
    {
        $id = (int) $given;

        if ($id <= 0) {
            return null;
        }

        if ($current !== null && $id === (int) $current) {
            return $id;
        }

        $epic = $this->epics->find($id);

        if ($epic === null || (int) $epic['project_id'] !== $projectId) {
            throw new ValidationError(__('That epic is not in this project.'));
        }

        if ((int) $epic['is_done'] === 1) {
            throw new ValidationError(__('That epic is done and takes no new tickets.'));
        }

        return $id;
    }

    /**
     * Somebody who can actually do the work: an account that exists and is
     * active. The assignee a ticket already has is kept even if they have been
     * deactivated since, so that editing the description does not quietly
     * unassign it.
     */
    private function assignee(mixed $given, mixed $current): ?int
    {
        $id = (int) $given;

        if ($id <= 0) {
            return null;
        }

        if ($current !== null && $id === (int) $current) {
            return $id;
        }

        if ($this->users->findActive($id) === null) {
            throw new ValidationError(__('That person cannot be given work: there is no such active account.'));
        }

        return $id;
    }

    /**
     * One of the project's own columns — by id, by name, or by category — and
     * never another project's: the board a ticket is on is its project's.
     */
    private function status(int $projectId, mixed $given): array
    {
        $status = $this->statuses->resolve($projectId, $given);

        if ($status === null) {
            throw new ValidationError(__('That is not one of the board’s columns.'));
        }

        return $status;
    }

    private function type(mixed $given): string
    {
        $type = (string) $given;

        return in_array($type, TicketRepository::TYPES, true) ? $type : 'task';
    }

    /** A due day: a real date, or none. */
    private function due(mixed $given): ?string
    {
        $given = trim((string) $given);

        if ($given === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $given);

        if ($date === false || $date->format('Y-m-d') !== $given) {
            throw new ValidationError(__('The due date does not look like a date.'));
        }

        return $given;
    }

    /**
     * Story points: a whole number, and a small one. Anything over 100 is a
     * ticket that should be several.
     */
    private function points(mixed $given): ?int
    {
        $given = trim((string) $given);

        if ($given === '') {
            return null;
        }

        if (!ctype_digit($given) || (int) $given > 100) {
            throw new ValidationError(__('Story points are a whole number from 0 to 100.'));
        }

        return (int) $given;
    }

    private function priority(mixed $given): string
    {
        $priority = (string) $given;

        return in_array($priority, TicketRepository::PRIORITIES, true) ? $priority : 'normal';
    }
}
