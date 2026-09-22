<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Config;
use CantoTrack\Core\Session;
use CantoTrack\Core\View;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\TicketRepository;

/**
 * Epics: the middle level.
 *
 * They are edited by anyone signed in rather than by administrators only.
 * Grouping tickets is part of doing the work, and a grouping that needs somebody
 * else's permission is one people stop maintaining.
 */
class EpicController
{
    public function show(int $id): void
    {
        Auth::require();

        $epic = $this->epicOr404($id);

        View::render('epics/show.twig', [
            'epic' => $epic,
            'project' => (new ProjectRepository())->find((int) $epic['project_id']),
            'tickets' => (new TicketRepository())->search(['epic_id' => $id], 500),
        ]);
    }

    public function createForm(int $projectId): void
    {
        Auth::require();

        View::render('epics/form.twig', [
            'project' => $this->projectOr404($projectId),
            'epic' => null,
            'error' => null,
        ]);
    }

    public function create(int $projectId): void
    {
        Auth::require();

        $project = $this->projectOr404($projectId);
        $title = trim((string) ($_POST['title'] ?? ''));

        if ($title === '') {
            View::render('epics/form.twig', [
                'project' => $project,
                'epic' => ['title' => $title, 'description' => $_POST['description'] ?? '', 'is_done' => 0],
                'error' => 'An epic needs a title.',
            ]);

            return;
        }

        $id = (new EpicRepository())->create($projectId, $title, (string) ($_POST['description'] ?? ''));

        Session::flash('Epic created.');
        $this->redirect('/epics/' . $id);
    }

    public function editForm(int $id): void
    {
        Auth::require();

        $epic = $this->epicOr404($id);

        View::render('epics/form.twig', [
            'project' => (new ProjectRepository())->find((int) $epic['project_id']),
            'epic' => $epic,
            'error' => null,
        ]);
    }

    public function update(int $id): void
    {
        Auth::require();

        $epic = $this->epicOr404($id);
        $title = trim((string) ($_POST['title'] ?? ''));

        if ($title === '') {
            View::render('epics/form.twig', [
                'project' => (new ProjectRepository())->find((int) $epic['project_id']),
                'epic' => $epic,
                'error' => 'An epic needs a title.',
            ]);

            return;
        }

        (new EpicRepository())->update($id, $title, (string) ($_POST['description'] ?? ''), isset($_POST['is_done']));

        Session::flash('Epic saved.');
        $this->redirect('/epics/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::require();

        $epic = $this->epicOr404($id);
        (new EpicRepository())->delete($id);

        // Worth saying, because it is the opposite of what deleting a project
        // does: the work stays, only the grouping is gone.
        Session::flash('Epic deleted. Its tickets are still in the project, without an epic.', 'warning');
        $this->redirect('/projects/' . $epic['project_id']);
    }

    private function epicOr404(int $id): array
    {
        $epic = (new EpicRepository())->find($id);

        if ($epic === null) {
            http_response_code(404);
            exit('There is no such epic.');
        }

        return $epic;
    }

    private function projectOr404(int $id): array
    {
        $project = (new ProjectRepository())->find($id);

        if ($project === null) {
            http_response_code(404);
            exit('There is no such project.');
        }

        return $project;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((string) Config::get('app.base_url'), '/') . $path);
        exit;
    }
}
