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
 * Projects: the list, the board, and the form an administrator sets one up
 * with.
 *
 * Everyone signed in can see every project. A tracker where people cannot see
 * each other's work is one where the same thing gets built twice, and the
 * sharper the walls the more often that happens.
 */
class ProjectController
{
    public function index(): void
    {
        Auth::require();

        $showArchived = ($_GET['archived'] ?? '') === '1';

        View::render('projects/index.twig', [
            'projects' => (new ProjectRepository())->allWithCounts($showArchived),
            'show_archived' => $showArchived,
        ]);
    }

    /** The board: a project's tickets in the columns they are in. */
    public function show(int $id): void
    {
        Auth::require();

        $project = $this->projectOr404($id);
        $tickets = new TicketRepository();

        View::render('projects/show.twig', [
            'project' => $project,
            'board' => $tickets->board($id),
            'counts' => $tickets->countsByStatus($id),
            'statuses' => TicketRepository::STATUSES,
            'epics' => (new EpicRepository())->forProject($id),
        ]);
    }

    public function createForm(): void
    {
        Auth::requireAdmin();

        View::render('projects/form.twig', ['project' => null, 'error' => null]);
    }

    public function create(): void
    {
        Auth::requireAdmin();

        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $projects = new ProjectRepository();

        // The code ends up in every ticket's name, in commit messages and in
        // conversations, so it is held to a shape: letters and digits, short
        // enough to say.
        $error = match (true) {
            $name === '' => 'A project needs a name.',
            preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $code) !== 1 =>
                'The code is 2 to 10 characters, letters and digits, starting with a letter.',
            $projects->findByCode($code) !== null => 'There is already a project with that code.',
            default => null,
        };

        if ($error !== null) {
            View::render('projects/form.twig', [
                'project' => ['code' => $code, 'name' => $name, 'description' => $_POST['description'] ?? ''],
                'error' => $error,
            ]);

            return;
        }

        $id = $projects->create($code, $name, (string) ($_POST['description'] ?? ''));

        Session::flash('Project ' . $code . ' created.');
        $this->redirect('/projects/' . $id);
    }

    public function editForm(int $id): void
    {
        Auth::requireAdmin();

        View::render('projects/form.twig', ['project' => $this->projectOr404($id), 'error' => null]);
    }

    public function update(int $id): void
    {
        Auth::requireAdmin();

        $project = $this->projectOr404($id);
        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '') {
            View::render('projects/form.twig', [
                'project' => $project,
                'error' => 'A project needs a name.',
            ]);

            return;
        }

        (new ProjectRepository())->update(
            $id,
            $name,
            (string) ($_POST['description'] ?? ''),
            isset($_POST['is_archived'])
        );

        Session::flash('Project saved.');
        $this->redirect('/projects/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::requireAdmin();

        $project = $this->projectOr404($id);
        (new ProjectRepository())->delete($id);

        // Said plainly, because this took the tickets and their hours with it.
        Session::flash('Project ' . $project['code'] . ' and everything in it was deleted.', 'warning');
        $this->redirect('/projects');
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
