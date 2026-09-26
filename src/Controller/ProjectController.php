<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\I18n;
use CantoTrack\Model\AttachmentRepository;
use CantoTrack\Model\BoardRepository;
use CantoTrack\Model\ClientRepository;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\LabelRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\SprintRepository;
use CantoTrack\Model\StatusRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\AttachmentService;
use CantoTrack\Service\BoardLanes;

/**
 * Projects: the list, the board, and the settings an administrator sets one up
 * with — its name, and the columns its tickets move through.
 *
 * Everyone signed in can see every project. A tracker where people cannot see
 * each other's work is one where the same thing gets built twice, and the
 * sharper the walls the more often that happens.
 */
class ProjectController extends Controller
{
    public function index(): void
    {
        Auth::require();

        $showArchived = ($_GET['archived'] ?? '') === '1';

        $this->render('projects/index.twig', [
            'projects' => (new ProjectRepository())->allWithCounts($showArchived),
            'show_archived' => $showArchived,
        ]);
    }

    /** The board: a project's tickets in the columns they are in. */
    public function show(int $id): void
    {
        Auth::require();

        $project = $this->projectOr404($id);
        $statuses = (new StatusRepository())->forProject($id);
        $epics = (new EpicRepository())->forProject($id);

        // The board's own filters, in the address like the list's, so a
        // narrowed board can be bookmarked and shared.
        $who = (string) ($_GET['who'] ?? '');
        $epic = (string) ($_GET['epic'] ?? '');
        $filters = [
            'assignee_id' => $who === 'me' ? Auth::id() : (ctype_digit($who) ? (int) $who : null),
            'unassigned' => $who === 'none',
            'epic_id' => ctype_digit($epic) ? (int) $epic : null,
            'no_epic' => $epic === 'none',
            'type' => $_GET['type'] ?? null,
            'label' => trim((string) ($_GET['label'] ?? '')) ?: null,
            'q' => trim((string) ($_GET['q'] ?? '')) ?: null,
        ];
        $lanesBy = in_array($_GET['lanes'] ?? '', ['epic', 'assignee'], true) ? $_GET['lanes'] : 'none';
        $narrowed = array_filter($filters) !== [];

        // With a sprint running on the project's own board, the board is that
        // sprint's — the work the team said it would do — unless somebody
        // asks for everything. The sprints of the shared boards the project
        // is on are theirs, and only named here.
        $sprints = new SprintRepository();
        $active = $sprints->active((int) (new BoardRepository())->ownOf($id)['id']);
        $elsewhere = array_values(array_filter(
            $sprints->activeForProject($id),
            static fn(array $s): bool => $s['board_project_id'] === null
        ));
        $scope = $active !== null && ($_GET['scope'] ?? '') !== 'all' ? 'sprint' : 'all';
        if ($scope === 'sprint') {
            $filters['sprint_id'] = (int) $active['id'];
        }

        $board = (new TicketRepository())->board($id, $statuses, $filters);

        $this->render('projects/show.twig', [
            'can_lead' => Auth::leads($id),
            'budget' => (new \CantoTrack\Service\Budget())->of($project),
            'project' => $project,
            'board' => $board['columns'],
            'more' => $board['more'],
            'counts' => $board['counts'],
            'statuses' => $statuses,
            'epics' => $epics,
            'lanes' => BoardLanes::split($board['columns'], $lanesBy, $epics),
            'lanes_by' => $lanesBy,
            'who' => $who,
            'epic' => $epic,
            'filters' => $filters,
            'narrowed' => $narrowed,
            'active_sprint' => $active,
            'sprint_progress' => $active === null ? null : $this->sprintProgress((int) $active['id']),
            'shared_sprints' => $elsewhere,
            'scope' => $scope,
            'people' => (new UserRepository())->active(),
            'labels' => (new LabelRepository())->all(),
            'types' => TicketRepository::TYPES,
        ]);
    }

    /** How far the running sprint has got: tickets and points, done and all. */
    private function sprintProgress(int $sprintId): array
    {
        $tickets = (new SprintRepository())->tickets($sprintId);
        $done = array_filter($tickets, static fn(array $t): bool => $t['category'] === 'done');

        return [
            'total' => count($tickets),
            'done' => count($done),
            'points' => array_sum(array_map(static fn(array $t): int => (int) $t['story_points'], $tickets)),
            'done_points' => array_sum(array_map(static fn(array $t): int => (int) $t['story_points'], $done)),
        ];
    }

    /**
     * What a ticket form offers once a project is chosen: its open epics and
     * its columns. Asked for by the form's script, so choosing a project does
     * not reload the page and lose what was already typed.
     */
    public function options(int $id): void
    {
        Auth::require();

        $this->projectOr404($id);

        $this->json([
            'epics' => array_map(
                static fn(array $epic): array => ['value' => (int) $epic['id'], 'label' => $epic['title']],
                (new EpicRepository())->openForProject($id)
            ),
            'releases' => array_map(
                static fn(array $release): array => ['value' => (int) $release['id'], 'label' => $release['name']],
                (new \CantoTrack\Model\ReleaseRepository())->unreleased($id)
            ),
            'statuses' => array_map(
                static fn(array $status): array => [
                    'value' => (int) $status['id'],
                    'label' => I18n::translate((string) $status['name']),
                ],
                (new StatusRepository())->forProject($id)
            ),
        ]);
    }

    public function createForm(): void
    {
        Auth::requireAdmin();

        $this->renderForm(['project' => null, 'error' => null, 'statuses' => []]);
    }

    public function create(): void
    {
        Auth::requireAdmin();

        $code = strtoupper($this->input('code'));
        $name = $this->input('name');
        $projects = new ProjectRepository();

        // The code ends up in every ticket's name, in commit messages and in
        // conversations, so it is held to a shape: letters and digits, short
        // enough to say.
        $error = match (true) {
            $name === '' => __('A project needs a name.'),
            preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $code) !== 1 =>
                __('The code is 2 to 10 characters, letters and digits, starting with a letter.'),
            $projects->findByCode($code) !== null => __('There is already a project with that code.'),
            default => null,
        };

        if ($error !== null) {
            $this->renderForm([
                'project' => [
                    'code' => $code,
                    'name' => $name,
                    'description' => $this->input('description'),
                    'client_name' => $this->input('client'),
                    'billable_default' => isset($_POST['billable_default']) ? 1 : 0,
                ],
                'error' => $error,
                'statuses' => [],
            ], 422);

            return;
        }

        $id = $projects->create($code, $name, $this->input('description'));
        $projects->setBilling($id, (new ClientRepository())->findOrCreate($this->input('client')), isset($_POST['billable_default']));
        $projects->setVisibility($id, $this->input('visibility'));
        \CantoTrack\Service\AuditLog::record('project_created', 'project', $id, $code . ' — ' . $name);

        $this->flash(__('Project {code} created.', ['code' => $code]));
        $this->redirect('/projects/' . $id);
    }

    public function editForm(int $id): void
    {
        Auth::requireProjectLead($id);

        $this->renderForm([
            'project' => $this->projectOr404($id),
            'error' => null,
            'statuses' => (new StatusRepository())->forProject($id),
        ]);
    }

    /** Adds somebody to a project: for a private one, or for a guest. */
    public function addMember(int $id): void
    {
        Auth::requireProjectLead($id);

        $this->projectOr404($id);
        $person = (new UserRepository())->findActive((int) $this->idInput('user_id'));

        if ($person === null) {
            $this->flash(__('There is no such active account.'), 'danger');
            $this->redirect('/projects/' . $id . '/edit#members');
        }

        (new ProjectRepository())->addMember($id, (int) $person['id']);
        \CantoTrack\Service\AuditLog::record('member_added', 'project', $id, $this->label($id), (string) $person['name']);

        $this->flash(__('{name} is a member of the project now.', ['name' => $person['name']]));
        $this->redirect('/projects/' . $id . '/edit#members');
    }

    public function removeMember(int $id, int $userId): void
    {
        Auth::requireProjectLead($id);

        $this->projectOr404($id);
        (new ProjectRepository())->removeMember($id, $userId);
        \CantoTrack\Service\AuditLog::record('member_removed', 'project', $id, $this->label($id), (string) ((new UserRepository())->find($userId)['name'] ?? '#' . $userId));

        $this->flash(__('Taken off the project.'), 'warning');
        $this->redirect('/projects/' . $id . '/edit#members');
    }

    /**
     * A member's role in the project: its lead runs its settings. Guests
     * read and comment, and lead nothing.
     */
    public function memberRole(int $id, int $userId): void
    {
        Auth::requireProjectLead($id);

        $this->projectOr404($id);
        $person = (new UserRepository())->find($userId);
        $role = $this->input('role') === 'lead' ? 'lead' : 'member';

        if ($person === null || ($role === 'lead' && $person['role'] === 'guest')) {
            $this->flash(__('A guest cannot lead a project.'), 'danger');
            $this->redirect('/projects/' . $id . '/edit#members');
        }

        (new ProjectRepository())->setMemberRole($id, $userId, $role);
        \CantoTrack\Service\AuditLog::record('member_role', 'project', $id, $this->label($id), $person['name'] . ': ' . $role);

        $this->flash($role === 'lead'
            ? __('{name} leads the project now.', ['name' => $person['name']])
            : __('{name} is a member of the project, not its lead.', ['name' => $person['name']]));
        $this->redirect('/projects/' . $id . '/edit#members');
    }

    /** What the record calls a project: its code and name. */
    private function label(int $id): string
    {
        $project = (new ProjectRepository())->find($id);

        return $project === null ? '#' . $id : $project['code'] . ' — ' . $project['name'];
    }

    /** The settings form, with the clients it offers. */
    private function renderForm(array $context, int $status = 200): void
    {
        $projectId = (int) ($context['project']['id'] ?? 0);

        $this->render('projects/form.twig', $context + [
            'clients' => (new ClientRepository())->all(),
            'members' => $projectId > 0 ? (new ProjectRepository())->members($projectId) : [],
            // Service accounts too: a private project is added to like a person.
            'people' => (new UserRepository())->active(true),
        ], $status);
    }

    public function update(int $id): void
    {
        Auth::requireProjectLead($id);

        $project = $this->projectOr404($id);
        $name = $this->input('name');

        if ($name === '') {
            $this->renderForm([
                'project' => $project,
                'error' => __('A project needs a name.'),
                'statuses' => (new StatusRepository())->forProject($id),
            ], 422);

            return;
        }

        $projects = new ProjectRepository();
        $projects->update($id, $name, $this->input('description'), isset($_POST['is_archived']));
        $projects->setBilling($id, (new ClientRepository())->findOrCreate($this->input('client')), isset($_POST['billable_default']));

        if (isset($_POST['card_fields_shown'])) {
            $projects->setCardFields($id, array_values(array_map('strval', (array) ($_POST['card_fields'] ?? []))));
        }

        // Who sees it, and what its hours are worth, stay the administrators'.
        if (Auth::isAdmin()) {
            if (in_array($this->input('visibility'), ['team', 'private'], true)) {
                $projects->setVisibility($id, $this->input('visibility'));
            }

            if (isset($_POST['budget_shown'])) {
                try {
                    $projects->setBudget(
                        $id,
                        \CantoTrack\Service\Money::parse($this->input('hourly_rate')),
                        \CantoTrack\Service\Money::parse($this->input('budget_hours')),
                        \CantoTrack\Service\Money::parse($this->input('budget_amount')),
                        (int) ($this->input('budget_alert') ?: 80)
                    );
                } catch (\CantoTrack\Core\ValidationError $e) {
                    $this->flash($e->getMessage(), 'danger');
                    $this->redirect('/projects/' . $id . '/edit#budget');
                }
            }
        }

        $after = (array) $projects->find($id);
        \CantoTrack\Service\AuditLog::record('project_updated', 'project', $id, $project['code'] . ' — ' . $name, \CantoTrack\Service\AuditLog::changes(
            array_intersect_key($project, array_flip(['name', 'visibility', 'is_archived', 'hourly_rate', 'budget_hours', 'budget_amount', 'card_fields'])),
            array_intersect_key($after, array_flip(['name', 'visibility', 'is_archived', 'hourly_rate', 'budget_hours', 'budget_amount', 'card_fields']))
        ));

        $this->flash(__('Project saved.'));
        $this->redirect('/projects/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::requireAdmin();

        $project = $this->projectOr404($id);
        $projects = new ProjectRepository();

        // A project with hours in it is archived, not deleted: the hours are
        // what was reported and invoiced, and a delete used to take them along.
        if ($projects->hasWorklogs($id)) {
            $this->flash(
                __('{code} has hours logged in it, so it cannot be deleted. Archive it instead: it keeps its history and gets out of the way.', ['code' => $project['code']]),
                'danger'
            );
            $this->redirect('/projects/' . $id . '/edit');
        }

        $files = array_merge(
            (new AttachmentRepository())->pathsForTickets('t.project_id = :id', ['id' => $id]),
            (new AttachmentRepository())->pathsForPages('pg.project_id = :id', ['id' => $id]),
            (new AttachmentRepository())->pathsForEpics('ep.project_id = :id', ['id' => $id])
        );
        $projects->delete($id);
        AttachmentService::unlinkAll($files);
        \CantoTrack\Service\AuditLog::record('project_deleted', 'project', $id, $project['code'] . ' — ' . $project['name']);

        $this->flash(__('Project {code} and its tickets were deleted.', ['code' => $project['code']]), 'warning');
        $this->redirect('/projects');
    }

    // -----------------------------------------------------------------------
    // The columns
    // -----------------------------------------------------------------------

    public function createStatus(int $id): void
    {
        Auth::requireProjectLead($id);

        $this->projectOr404($id);
        $name = $this->input('name');

        if ($name === '') {
            $this->flash(__('A column needs a name.'), 'danger');
            $this->redirect('/projects/' . $id . '/edit#workflow');
        }

        (new StatusRepository())->create(
            $id,
            mb_substr($name, 0, 60),
            $this->input('category', 'todo'),
            $this->input('colour', 'slate'),
            $this->idInput('wip_limit')
        );

        \CantoTrack\Service\AuditLog::record('column_created', 'project', $id, $this->label($id), $name);
        $this->flash(__('Column {name} added.', ['name' => $name]));
        $this->redirect('/projects/' . $id . '/edit#workflow');
    }

    public function updateStatus(int $statusId): void
    {
        Auth::require();

        $status = $this->statusOr404($statusId);
        Auth::requireProjectLead((int) $status['project_id']);
        $name = $this->input('name');
        $statuses = new StatusRepository();

        if ($name === '') {
            $this->flash(__('A column needs a name.'), 'danger');
            $this->redirect('/projects/' . $status['project_id'] . '/edit#workflow');
        }

        // A board with no done column has nowhere for work to finish, and
        // nothing would ever count as finished.
        $category = StatusRepository::category($this->input('category', 'todo'));
        if ($status['category'] === 'done' && $category !== 'done' && $this->doneColumns((int) $status['project_id']) === 1) {
            $this->flash(__('A project needs at least one done column.'), 'danger');
            $this->redirect('/projects/' . $status['project_id'] . '/edit#workflow');
        }

        $statuses->update($statusId, mb_substr($name, 0, 60), $category, $this->input('colour', 'slate'), $this->idInput('wip_limit'));

        \CantoTrack\Service\AuditLog::record('column_updated', 'project', (int) $status['project_id'], $this->label((int) $status['project_id']), $status['name'] . ($status['name'] !== $name ? ' → ' . $name : '') . ', ' . $category);
        $this->flash(__('Column {name} saved.', ['name' => $name]));
        $this->redirect('/projects/' . $status['project_id'] . '/edit#workflow');
    }

    /** Which column a ticket may go to from which — the grid on the settings page. */
    public function saveMoves(int $id): void
    {
        Auth::requireProjectLead($id);

        $this->projectOr404($id);
        $statuses = new StatusRepository();

        if ($this->input('reset') !== '') {
            $every = [];
            foreach ($statuses->forProject($id) as $status) {
                $every[(int) $status['id']] = array_map(static fn(array $s): int => (int) $s['id'], $statuses->forProject($id));
            }
            $statuses->setMoves($id, $every);
            $this->flash(__('Every move is allowed again.'));
        } else {
            $statuses->setMoves($id, is_array($_POST['moves'] ?? null) ? $_POST['moves'] : []);
            $this->flash(__('The moves are saved.'));
        }
        \CantoTrack\Service\AuditLog::record('moves_saved', 'project', $id, $this->label($id), $this->input('reset') !== '' ? 'every move allowed' : '');

        $this->redirect('/projects/' . $id . '/edit#moves');
    }

    public function moveStatus(int $statusId): void
    {
        Auth::require();

        $status = $this->statusOr404($statusId);
        Auth::requireProjectLead((int) $status['project_id']);
        (new StatusRepository())->move($statusId, $this->input('direction') === 'left' ? -1 : 1);

        $this->redirect('/projects/' . $status['project_id'] . '/edit#workflow');
    }

    public function deleteStatus(int $statusId): void
    {
        Auth::require();

        $status = $this->statusOr404($statusId);
        Auth::requireProjectLead((int) $status['project_id']);
        $projectId = (int) $status['project_id'];
        $statuses = new StatusRepository();
        $columns = $statuses->forProject($projectId);
        $moveTo = $this->idInput('move_to');
        $target = $moveTo === null ? null : $statuses->find($moveTo);

        $error = match (true) {
            count($columns) <= 1 => __('A board needs at least one column.'),
            $status['category'] === 'done' && $this->doneColumns($projectId) === 1 => __('A project needs at least one done column.'),
            // Its tickets go somewhere of the same project first; a column is
            // never deleted out from under them.
            (int) ($this->ticketCount($columns, $statusId)) > 0
                && ($target === null || (int) $target['project_id'] !== $projectId || $moveTo === $statusId) =>
                __('Choose where its tickets go first.'),
            default => null,
        };

        if ($error !== null) {
            $this->flash($error, 'danger');
            $this->redirect('/projects/' . $projectId . '/edit#workflow');
        }

        $statuses->delete($statusId, $target === null ? null : (int) $target['id']);

        $this->flash(__('Column {name} deleted.', ['name' => $status['name']]), 'warning');
        $this->redirect('/projects/' . $projectId . '/edit#workflow');
    }

    private function doneColumns(int $projectId): int
    {
        return count(array_filter(
            (new StatusRepository())->forProject($projectId),
            static fn(array $s): bool => $s['category'] === 'done'
        ));
    }

    /** @param array<array> $columns */
    private function ticketCount(array $columns, int $statusId): int
    {
        foreach ($columns as $column) {
            if ((int) $column['id'] === $statusId) {
                return (int) $column['ticket_count'];
            }
        }

        return 0;
    }

    private function statusOr404(int $id): array
    {
        $status = (new StatusRepository())->find($id);

        if ($status === null) {
            $this->notFound(__('There is no such column.'));
        }

        return $status;
    }

    private function projectOr404(int $id): array
    {
        $project = (new ProjectRepository())->find($id);

        if ($project === null) {
            $this->notFound(__('There is no such project.'));
        }

        return $project;
    }
}
