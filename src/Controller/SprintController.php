<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\BoardRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\SprintRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\BoardService;
use CantoTrack\Service\SprintService;

/**
 * Planning: a board's backlog and its sprints, and the report of each
 * sprint — its burndown and what it did.
 *
 * A project's own board is planned under the project, as its Backlog tab; a
 * shared board under /boards — see the 0047 migration.
 */
class SprintController extends Controller
{
    /** A project's backlog: its own board's. */
    public function backlog(int $projectId): void
    {
        Auth::require();

        $project = $this->projectOr404($projectId);

        $this->renderBacklog((new BoardRepository())->ownOf($projectId), $project);
    }

    /** A shared board's backlog. */
    public function boardBacklog(int $boardId): void
    {
        Auth::require();

        $board = $this->boardOr404($boardId);

        if ($board['project_id'] !== null) {
            $this->redirect('/projects/' . $board['project_id'] . '/backlog');
        }

        $this->renderBacklog($board, null);
    }

    /** A sprint planned on a project's own board. */
    public function create(int $projectId): void
    {
        Auth::requireMember();

        $this->projectOr404($projectId);

        $this->plan((new BoardRepository())->ownOf($projectId));
    }

    /** A sprint planned on a shared board. */
    public function createOnBoard(int $boardId): void
    {
        Auth::requireMember();

        $this->plan($this->boardOr404($boardId));
    }

    /** A sprint's report: the burndown, and its tickets as they stand. */
    public function show(int $id): void
    {
        Auth::require();

        $sprint = $this->sprintOr404($id);
        $board = $this->boardOr404((int) $sprint['board_id']);
        $tickets = (new TicketRepository())->search(['sprint_id' => $id], 500);
        $project = $board['project_id'] === null ? null : (new ProjectRepository())->find((int) $board['project_id']);

        $this->render('sprints/show.twig', [
            'sprint' => $sprint,
            'board' => $board,
            'home_name' => $project['name'] ?? $board['name'],
            'board_url' => BoardService::url($board),
            'backlog_url' => BoardService::url($board, '/backlog'),
            'tickets' => $tickets,
            'burndown' => (new SprintService())->burndown($sprint),
            'planned' => array_values(array_filter(
                (new SprintRepository())->forBoard((int) $board['id']),
                static fn(array $s): bool => $s['state'] === 'planned' && (int) $s['id'] !== $id
            )),
        ]);
    }

    public function update(int $id): void
    {
        Auth::requireMember();

        $sprint = $this->sprintOr404($id);

        try {
            (new SprintService())->update($sprint, $this->input('name'), $this->input('goal'), $this->input('starts_on'), $this->input('ends_on'));
            $this->flash(__('Sprint saved.'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->back('/sprints/' . $id);
    }

    public function start(int $id): void
    {
        Auth::requireMember();

        $sprint = $this->sprintOr404($id);
        $board = $this->boardOr404((int) $sprint['board_id']);

        try {
            (new SprintService())->start($sprint);
            $this->flash(__('{name} is running.', ['name' => $sprint['name']]));
            $this->redirect(BoardService::url($board));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->redirect(BoardService::url($board, '/backlog'));
        }
    }

    public function close(int $id): void
    {
        Auth::requireMember();

        $sprint = $this->sprintOr404($id);

        try {
            (new SprintService())->close($sprint, $this->idInput('carry_to'), Auth::id());
            $this->flash(__('{name} is closed.', ['name' => $sprint['name']]));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/sprints/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::requireMember();

        $sprint = $this->sprintOr404($id);
        $board = $this->boardOr404((int) $sprint['board_id']);

        try {
            (new SprintService())->delete($sprint);
            $this->flash(__('Sprint deleted; its tickets are back in the backlog.'), 'warning');
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect(BoardService::url($board, '/backlog'));
    }

    /** One ticket into a sprint, or back to the backlog. */
    public function assign(int $ticketId): void
    {
        Auth::requireMember();

        $ticket = (new TicketRepository())->find($ticketId);
        if ($ticket === null) {
            $this->notFound(__('There is no such ticket.'));
        }

        try {
            (new SprintService())->assign([$ticketId], $this->idInput('sprint_id'), Auth::id());
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->back('/projects/' . $ticket['project_id'] . '/backlog');
    }

    /** The backlog of a board: every sprint on it that is not closed, and what waits outside them. */
    private function renderBacklog(array $board, ?array $project): void
    {
        $sprints = new SprintRepository();
        $all = $sprints->forBoard((int) $board['id']);
        $boards = new BoardService();
        $scope = $boards->scope($board);
        $tickets = new TicketRepository();

        $this->render('sprints/backlog.twig', [
            'board' => $board,
            'project' => $project,
            'board_projects' => $project === null ? (new BoardRepository())->projects((int) $board['id']) : [$project],
            'backlog_url' => BoardService::url($board, '/backlog'),
            'plan_url' => $project === null ? '/boards/' . $board['id'] . '/sprints' : '/projects/' . $project['id'] . '/sprints',
            'name_prefix' => SprintService::prefix($board),
            'sprints' => array_values(array_filter($all, static fn(array $s): bool => $s['state'] !== 'closed')),
            'closed' => array_values(array_filter($all, static fn(array $s): bool => $s['state'] === 'closed')),
            'tickets' => $tickets->planning((int) $board['id'], $scope),
            'elsewhere' => $tickets->plannedElsewhere((int) $board['id'], $scope),
            'velocity' => $sprints->closed((int) $board['id']),
            'today' => date('Y-m-d'),
            'in_two_weeks' => date('Y-m-d', strtotime('+' . (SprintService::DEFAULT_DAYS - 1) . ' days')),
        ]);
    }

    private function plan(array $board): never
    {
        try {
            (new SprintService())->create(
                (int) $board['id'],
                $this->input('name'),
                $this->input('goal'),
                $this->input('starts_on'),
                $this->input('ends_on')
            );
            $this->flash(__('Sprint planned.'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect(BoardService::url($board, '/backlog'));
    }

    private function sprintOr404(int $id): array
    {
        $sprint = (new SprintRepository())->find($id);

        if ($sprint === null) {
            $this->notFound(__('There is no such sprint.'));
        }

        return $sprint;
    }

    private function boardOr404(int $id): array
    {
        $board = (new BoardRepository())->find($id);

        if ($board === null) {
            $this->notFound(__('There is no such board.'));
        }

        return $board;
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
