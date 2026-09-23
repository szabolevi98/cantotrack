<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\SprintRepository;
use CantoTrack\Model\StatusRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\SprintService;

/**
 * Planning: a project's backlog and its sprints, and the report of each
 * sprint — its burndown and what it did.
 */
class SprintController extends Controller
{
    /** The backlog: every sprint that is not closed, and what waits outside them. */
    public function backlog(int $projectId): void
    {
        Auth::require();

        $project = $this->projectOr404($projectId);
        $sprints = new SprintRepository();
        $all = $sprints->forProject($projectId);

        $this->render('sprints/backlog.twig', [
            'project' => $project,
            'sprints' => array_values(array_filter($all, static fn(array $s): bool => $s['state'] !== 'closed')),
            'closed' => array_values(array_filter($all, static fn(array $s): bool => $s['state'] === 'closed')),
            'tickets' => (new TicketRepository())->planning($projectId),
            'statuses' => (new StatusRepository())->forProject($projectId),
            'velocity' => $sprints->closed($projectId),
            'today' => date('Y-m-d'),
            'in_two_weeks' => date('Y-m-d', strtotime('+' . (SprintService::DEFAULT_DAYS - 1) . ' days')),
        ]);
    }

    public function create(int $projectId): void
    {
        Auth::require();

        $this->projectOr404($projectId);

        try {
            (new SprintService())->create(
                $projectId,
                $this->input('name'),
                $this->input('goal'),
                $this->input('starts_on'),
                $this->input('ends_on')
            );
            $this->flash(__('Sprint planned.'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/projects/' . $projectId . '/backlog');
    }

    /** A sprint's report: the burndown, and its tickets as they stand. */
    public function show(int $id): void
    {
        Auth::require();

        $sprint = $this->sprintOr404($id);
        $project = $this->projectOr404((int) $sprint['project_id']);
        $tickets = (new TicketRepository())->search(['sprint_id' => $id], 500);

        $this->render('sprints/show.twig', [
            'sprint' => $sprint,
            'project' => $project,
            'tickets' => $tickets,
            'burndown' => (new SprintService())->burndown($sprint),
            'planned' => array_values(array_filter(
                (new SprintRepository())->forProject((int) $project['id']),
                static fn(array $s): bool => $s['state'] === 'planned' && (int) $s['id'] !== $id
            )),
        ]);
    }

    public function update(int $id): void
    {
        Auth::require();

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
        Auth::require();

        $sprint = $this->sprintOr404($id);

        try {
            (new SprintService())->start($sprint);
            $this->flash(__('{name} is running.', ['name' => $sprint['name']]));
            $this->redirect('/projects/' . $sprint['project_id']);
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->redirect('/projects/' . $sprint['project_id'] . '/backlog');
        }
    }

    public function close(int $id): void
    {
        Auth::require();

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
        Auth::require();

        $sprint = $this->sprintOr404($id);

        try {
            (new SprintService())->delete($sprint);
            $this->flash(__('Sprint deleted; its tickets are back in the backlog.'), 'warning');
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/projects/' . $sprint['project_id'] . '/backlog');
    }

    /** One ticket into a sprint, or back to the backlog. */
    public function assign(int $ticketId): void
    {
        Auth::require();

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

    private function sprintOr404(int $id): array
    {
        $sprint = (new SprintRepository())->find($id);

        if ($sprint === null) {
            $this->notFound(__('There is no such sprint.'));
        }

        return $sprint;
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
