<?php

namespace CantoTrack\Service;

use CantoTrack\Core\ConflictError;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Format;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\ProjectRepository;
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

    public function __construct(?PDO $db = null)
    {
        $db ??= DatabaseConnection::get();

        $this->tickets = new TicketRepository($db);
        $this->projects = new ProjectRepository($db);
        $this->epics = new EpicRepository($db);
        $this->users = new UserRepository($db);
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

        return $this->tickets->create([
            'project_id' => $projectId,
            'epic_id' => $this->epic($input['epic_id'] ?? null, $projectId, null),
            'title' => $this->title($input['title'] ?? ''),
            'description' => (string) ($input['description'] ?? ''),
            'status' => $this->status($input['status'] ?? 'backlog'),
            'priority' => $this->priority($input['priority'] ?? 'normal'),
            'assignee_id' => $this->assignee($input['assignee_id'] ?? null, null),
            // Whoever wrote it down. Not editable afterwards: it is a fact about
            // the past, not a field.
            'reporter_id' => $reporterId,
            'estimate_minutes' => $this->estimate($input),
        ]);
    }

    /**
     * Saves an edit made against a particular version of the ticket.
     *
     * @param array<string, mixed> $input
     * @throws ValidationError when something in it does not make sense
     * @throws ConflictError when somebody else saved the ticket in between
     */
    public function update(int $id, array $input): void
    {
        $ticket = $this->tickets->find($id);

        if ($ticket === null) {
            throw new ValidationError(__('There is no such ticket.'));
        }

        $projectId = (int) $ticket['project_id'];
        $data = [
            'epic_id' => $this->epic($input['epic_id'] ?? null, $projectId, $ticket['epic_id']),
            'title' => $this->title($input['title'] ?? ''),
            'description' => (string) ($input['description'] ?? ''),
            'priority' => $this->priority($input['priority'] ?? 'normal'),
            'assignee_id' => $this->assignee($input['assignee_id'] ?? null, $ticket['assignee_id']),
            'estimate_minutes' => $this->estimate($input),
        ];

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
    }

    /** @throws ValidationError */
    public function changeStatus(int $id, string $status): void
    {
        $this->tickets->changeStatus($id, $this->status($status));
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
        if ($this->tickets->hasWorklogs($id)) {
            throw new ValidationError(
                __('This ticket has hours logged against it, so it cannot be deleted. Move it to done instead — or delete the hours first if they really are a mistake.')
            );
        }

        $this->tickets->delete($id);
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

    private function status(mixed $given): string
    {
        $status = (string) $given;

        if (!in_array($status, TicketRepository::STATUSES, true)) {
            throw new ValidationError(__('That is not one of the board’s columns.'));
        }

        return $status;
    }

    private function priority(mixed $given): string
    {
        $priority = (string) $given;

        return in_array($priority, TicketRepository::PRIORITIES, true) ? $priority : 'normal';
    }
}
