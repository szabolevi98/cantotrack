<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Config;
use CantoTrack\Core\Format;
use CantoTrack\Core\Session;
use CantoTrack\Core\View;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;

/**
 * Tickets: the list with its filters, one ticket's page, and the forms.
 *
 * Status is changed by its own small form rather than through the edit page.
 * Moving a ticket along is the thing that happens twenty times a day, and it
 * should not go through a form with eight other fields on it that a second
 * person might be editing at the same time.
 */
class TicketController
{
    public function index(): void
    {
        Auth::require();

        $filters = [
            'project_id' => $_GET['project'] ?? null,
            'status' => $_GET['status'] ?? null,
            'assignee_id' => $_GET['assignee'] ?? null,
            'q' => trim((string) ($_GET['q'] ?? '')) ?: null,
            'open_only' => ($_GET['open'] ?? '') === '1',
        ];

        View::render('tickets/index.twig', [
            'tickets' => (new TicketRepository())->search($filters),
            'filters' => $filters,
            'projects' => (new ProjectRepository())->allWithCounts(),
            'people' => (new UserRepository())->active(),
            'statuses' => TicketRepository::STATUSES,
        ]);
    }

    public function show(int $id): void
    {
        Auth::require();

        $ticket = $this->ticketOr404($id);

        View::render('tickets/show.twig', [
            'ticket' => $ticket,
            'people' => (new UserRepository())->active(),
            'statuses' => TicketRepository::STATUSES,
        ]);
    }

    public function createForm(): void
    {
        Auth::require();

        $projectId = (int) ($_GET['project'] ?? 0);

        View::render('tickets/form.twig', [
            'ticket' => null,
            'project_id' => $projectId,
            'projects' => (new ProjectRepository())->allWithCounts(),
            'epics' => $projectId > 0 ? (new EpicRepository())->openForProject($projectId) : [],
            'people' => (new UserRepository())->active(),
            'priorities' => TicketRepository::PRIORITIES,
            'statuses' => TicketRepository::STATUSES,
            'error' => null,
        ]);
    }

    public function create(): void
    {
        Auth::require();

        $projectId = (int) ($_POST['project_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $estimate = $this->estimateFromForm();

        $error = match (true) {
            $projectId <= 0 || (new ProjectRepository())->find($projectId) === null => 'Pick a project.',
            $title === '' => 'A ticket needs a title.',
            $estimate === false => 'The estimate should read like "3h", "90m" or "1h 30m".',
            default => null,
        };

        if ($error !== null) {
            $this->renderFormAgain($projectId, $error);

            return;
        }

        $id = (new TicketRepository())->create([
            'project_id' => $projectId,
            'epic_id' => (int) ($_POST['epic_id'] ?? 0) ?: null,
            'title' => $title,
            'description' => $_POST['description'] ?? '',
            'status' => $_POST['status'] ?? 'backlog',
            'priority' => $_POST['priority'] ?? 'normal',
            'assignee_id' => (int) ($_POST['assignee_id'] ?? 0) ?: null,
            // Whoever wrote it down. Not editable afterwards: it is a fact about
            // the past, not a field.
            'reporter_id' => Auth::id(),
            'estimate_minutes' => $estimate ?: null,
        ]);

        Session::flash('Ticket created.');
        $this->redirect('/tickets/' . $id);
    }

    public function editForm(int $id): void
    {
        Auth::require();

        $ticket = $this->ticketOr404($id);

        View::render('tickets/form.twig', [
            'ticket' => $ticket,
            'project_id' => (int) $ticket['project_id'],
            'projects' => (new ProjectRepository())->allWithCounts(),
            'epics' => (new EpicRepository())->openForProject((int) $ticket['project_id']),
            'people' => (new UserRepository())->active(),
            'priorities' => TicketRepository::PRIORITIES,
            'statuses' => TicketRepository::STATUSES,
            'error' => null,
        ]);
    }

    public function update(int $id): void
    {
        Auth::require();

        $ticket = $this->ticketOr404($id);
        $title = trim((string) ($_POST['title'] ?? ''));
        $estimate = $this->estimateFromForm();

        if ($title === '' || $estimate === false) {
            $this->renderFormAgain(
                (int) $ticket['project_id'],
                $title === '' ? 'A ticket needs a title.' : 'The estimate should read like "3h", "90m" or "1h 30m".',
                $ticket
            );

            return;
        }

        (new TicketRepository())->update($id, [
            'epic_id' => (int) ($_POST['epic_id'] ?? 0) ?: null,
            'title' => $title,
            'description' => $_POST['description'] ?? '',
            'priority' => $_POST['priority'] ?? 'normal',
            'assignee_id' => (int) ($_POST['assignee_id'] ?? 0) ?: null,
            'estimate_minutes' => $estimate ?: null,
        ]);

        Session::flash('Ticket saved.');
        $this->redirect('/tickets/' . $id);
    }

    /** The one-click move along the board, from the ticket page or the board. */
    public function changeStatus(int $id): void
    {
        Auth::require();

        $this->ticketOr404($id);
        (new TicketRepository())->changeStatus($id, (string) ($_POST['status'] ?? ''));

        // Back where it was clicked, so moving a ticket from the board does not
        // land somebody on the ticket's own page.
        $back = (string) ($_POST['back'] ?? '');
        $this->redirect(str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/tickets/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::requireAdmin();

        $ticket = $this->ticketOr404($id);
        (new TicketRepository())->delete($id);

        Session::flash(
            $ticket['project_code'] . '-' . $ticket['number'] . ' was deleted, with the hours logged against it.',
            'warning'
        );

        $this->redirect('/projects/' . $ticket['project_id']);
    }

    /**
     * What was typed into the estimate box, in minutes.
     *
     * Returns null for an empty field and false for something unreadable — the
     * two are different answers, and treating "3 apples" as "no estimate" is how
     * a typo turns into a silently empty field.
     */
    private function estimateFromForm(): int|false|null
    {
        $given = trim((string) ($_POST['estimate'] ?? ''));

        if ($given === '') {
            return null;
        }

        return Format::parseDuration($given) ?? false;
    }

    private function renderFormAgain(int $projectId, string $error, ?array $ticket = null): void
    {
        // What they typed is handed back, so a mistake in one field does not
        // cost the other seven.
        $typed = [
            'id' => $ticket['id'] ?? null,
            'project_id' => $projectId,
            'epic_id' => $_POST['epic_id'] ?? null,
            'title' => $_POST['title'] ?? '',
            'description' => $_POST['description'] ?? '',
            'status' => $_POST['status'] ?? 'backlog',
            'priority' => $_POST['priority'] ?? 'normal',
            'assignee_id' => $_POST['assignee_id'] ?? null,
            'estimate_minutes' => null,
            'estimate_text' => $_POST['estimate'] ?? '',
        ];

        View::render('tickets/form.twig', [
            'ticket' => $typed,
            'project_id' => $projectId,
            'projects' => (new ProjectRepository())->allWithCounts(),
            'epics' => $projectId > 0 ? (new EpicRepository())->openForProject($projectId) : [],
            'people' => (new UserRepository())->active(),
            'priorities' => TicketRepository::PRIORITIES,
            'statuses' => TicketRepository::STATUSES,
            'error' => $error,
        ]);
    }

    private function ticketOr404(int $id): array
    {
        $ticket = (new TicketRepository())->find($id);

        if ($ticket === null) {
            http_response_code(404);
            exit('There is no such ticket.');
        }

        return $ticket;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((string) Config::get('app.base_url'), '/') . $path);
        exit;
    }
}
