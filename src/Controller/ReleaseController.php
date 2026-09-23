<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\ReleaseRepository;
use CantoTrack\Model\StatusRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\ReleaseService;

/**
 * Releases: a project's list of them, one release with what is in it and its
 * notes, and the button that sends it out.
 *
 * Kept by the team rather than by administrators: deciding what goes out
 * together is part of planning the work, like the sprints are.
 */
class ReleaseController extends Controller
{
    public function index(int $projectId): void
    {
        Auth::require();

        $project = $this->projectOr404($projectId);

        $this->render('releases/index.twig', [
            'project' => $project,
            'releases' => (new ReleaseRepository())->forProject($projectId),
            'values' => [],
        ]);
    }

    public function create(int $projectId): void
    {
        Auth::requireMember();

        $project = $this->projectOr404($projectId);

        try {
            $id = (new ReleaseService())->create($projectId, $this->input('name'), $this->input('description'), $this->input('starts_on'), $this->input('release_on'));
        } catch (ValidationError $e) {
            $this->render('releases/index.twig', [
                'project' => $project,
                'releases' => (new ReleaseRepository())->forProject($projectId),
                'values' => $_POST,
                'error' => $e->getMessage(),
            ], 422);

            return;
        }

        $this->flash(__('Release created. Put tickets in it from their forms, or from the ticket list in bulk.'));
        $this->redirect('/releases/' . $id);
    }

    public function show(int $id): void
    {
        Auth::require();

        $release = $this->releaseOr404($id);
        $tickets = (new TicketRepository())->search(['release_id' => $id, 'top_level' => true], 500);

        $this->render('releases/show.twig', [
            'release' => $release,
            'project' => (new ProjectRepository())->find((int) $release['project_id']),
            'tickets' => $tickets,
            'unfinished' => count(array_filter($tickets, static fn(array $t): bool => $t['status_category'] !== 'done')),
            'others' => array_values(array_filter(
                (new ReleaseRepository())->unreleased((int) $release['project_id']),
                static fn(array $r): bool => (int) $r['id'] !== $id
            )),
            'notes' => (new ReleaseService())->notes($release),
            'statuses' => (new StatusRepository())->forProject((int) $release['project_id']),
        ]);
    }

    public function editForm(int $id): void
    {
        Auth::requireMember();

        $release = $this->releaseOr404($id);

        $this->render('releases/form.twig', ['release' => $release, 'values' => $release]);
    }

    public function update(int $id): void
    {
        Auth::requireMember();

        $release = $this->releaseOr404($id);

        try {
            (new ReleaseService())->update($release, $this->input('name'), $this->input('description'), $this->input('starts_on'), $this->input('release_on'));
        } catch (ValidationError $e) {
            $this->render('releases/form.twig', ['release' => $release, 'values' => $_POST, 'error' => $e->getMessage()], 422);

            return;
        }

        $this->flash(__('Release saved.'));
        $this->redirect('/releases/' . $id);
    }

    /** Out it goes, with what is unfinished moved on to another release or out of any. */
    public function release(int $id): void
    {
        Auth::requireMember();

        $release = $this->releaseOr404($id);

        try {
            (new ReleaseService())->release($release, $this->idInput('move_to'), Auth::id());
            $this->flash(__('{name} is out.', ['name' => $release['name']]));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/releases/' . $id);
    }

    public function unrelease(int $id): void
    {
        Auth::requireMember();

        $release = $this->releaseOr404($id);
        (new ReleaseService())->unrelease($release);

        $this->flash(__('{name} is marked as still coming again.', ['name' => $release['name']]), 'warning');
        $this->redirect('/releases/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::requireMember();

        $release = $this->releaseOr404($id);
        (new ReleaseRepository())->delete($id);

        $this->flash(__('Release deleted. Its tickets are still in the project, in no release.'), 'warning');
        $this->redirect('/projects/' . $release['project_id'] . '/releases');
    }

    private function releaseOr404(int $id): array
    {
        $release = (new ReleaseRepository())->find($id);

        if ($release === null) {
            $this->notFound(__('There is no such release.'));
        }

        return $release;
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
