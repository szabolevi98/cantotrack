<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\ConflictError;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Format;
use CantoTrack\Core\ValidationError;
use CantoTrack\Core\View;
use CantoTrack\Model\AttachmentRepository;
use CantoTrack\Model\CommentRepository;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\EventRepository;
use CantoTrack\Model\LabelRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\StatusRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\AttachmentService;
use CantoTrack\Service\TicketService;

/**
 * Tickets: the list with its filters, one ticket's page, and the forms.
 *
 * Status is changed by its own small form rather than through the edit page.
 * Moving a ticket along is the thing that happens twenty times a day, and it
 * should not go through a form with eight other fields on it that a second
 * person might be editing at the same time.
 *
 * What a ticket may be created or changed with is decided in TicketService;
 * this class reads the request, asks, and draws the answer.
 */
class TicketController extends Controller
{
    /** Rows per page of the list. */
    private const PER_PAGE = 50;

    public function index(): void
    {
        Auth::require();

        $filters = $this->filters();

        $tickets = new TicketRepository();
        $total = $tickets->count($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));

        View::render('tickets/index.twig', [
            'tickets' => $tickets->search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => self::PER_PAGE,
            // The query string without the page, so the page links keep the
            // filters they were drawn under.
            'query' => http_build_query(array_diff_key($_GET, ['page' => true])),
            'projects' => (new ProjectRepository())->allWithCounts(),
            'people' => (new UserRepository())->active(),
            // A project's own columns once one project is picked; across
            // projects only the three kinds of column mean the same thing.
            'statuses' => $filters['project_id'] ? (new StatusRepository())->forProject($filters['project_id']) : [],
            'labels' => (new LabelRepository())->all(),
            'types' => TicketRepository::TYPES,
        ]);
    }

    /**
     * The list's filters, from the query string. One place, because the list,
     * the bulk edit and a saved filter all have to read them the same way.
     */
    private function filters(): array
    {
        return [
            'project_id' => $this->idQuery('project'),
            'status' => $_GET['status'] ?? null,
            'assignee_id' => $this->idQuery('assignee'),
            'type' => $_GET['type'] ?? null,
            'label' => trim((string) ($_GET['label'] ?? '')) ?: null,
            'due' => $_GET['due'] ?? null,
            'q' => trim((string) ($_GET['q'] ?? '')) ?: null,
            'open_only' => ($_GET['open'] ?? '') === '1',
        ];
    }

    public function show(int $id): void
    {
        Auth::require();

        $ticket = $this->ticketOr404($id);
        $showing = in_array($_GET['activity'] ?? '', ['comments', 'history'], true) ? $_GET['activity'] : 'all';

        $this->render('tickets/show.twig', [
            'ticket' => $ticket,
            'labels' => (new LabelRepository())->forTicket($id),
            'attachments' => (new AttachmentRepository())->forTicket($id),
            'max_upload_mb' => intdiv(AttachmentService::maxBytes(), 1048576),
            'people' => (new UserRepository())->active(),
            'statuses' => (new StatusRepository())->forProject((int) $ticket['project_id']),
            'worklogs' => (new WorklogRepository())->forTicket($id),
            'timeline' => $this->timeline($id, $showing),
            'showing' => $showing,
            'today' => date('Y-m-d'),
        ]);
    }

    /** "CT-14" as an address: what the links in comments point at. */
    public function byKey(string $key): void
    {
        Auth::require();

        $ticket = (new TicketRepository())->findByKey($key);

        if ($ticket === null) {
            $this->notFound(__('There is no ticket called {key}.', ['key' => strtoupper($key)]));
        }

        $this->redirect('/tickets/' . $ticket['id']);
    }

    /**
     * What was said and what happened, in the order it happened.
     *
     * Two tables, one stream: the comments, and the history rows that are not
     * themselves comments. Reading them side by side is the point — "moved to
     * review" means something different right after "this is broken again".
     */
    private function timeline(int $ticketId, string $showing): array
    {
        $items = [];

        if ($showing !== 'history') {
            foreach ((new CommentRepository())->forTicket($ticketId) as $comment) {
                $items[] = ['kind' => 'comment', 'at' => $comment['created_at'], 'order' => (int) $comment['id'], 'comment' => $comment];
            }
        }

        if ($showing !== 'comments') {
            foreach ((new EventRepository())->forTicket($ticketId) as $event) {
                if ($event['kind'] !== 'commented') {
                    $items[] = ['kind' => 'event', 'at' => $event['created_at'], 'order' => (int) $event['id'], 'event' => $event];
                }
            }
        }

        usort($items, static fn(array $a, array $b): int => [$a['at'], $a['order']] <=> [$b['at'], $b['order']]);

        return $items;
    }

    public function createForm(): void
    {
        Auth::require();

        $projectId = (int) $this->idQuery('project');

        $this->renderForm(null, $projectId, [
            'epic_id' => $this->idQuery('epic'),
        ]);
    }

    public function create(): void
    {
        Auth::require();

        try {
            $id = (new TicketService())->create($_POST, (int) Auth::id());
        } catch (ValidationError $e) {
            $this->renderForm(null, (int) $this->idInput('project_id'), $this->typed(), $e->getMessage(), 422);

            return;
        }

        $this->flash(__('Ticket created.'));
        $this->redirect('/tickets/' . $id);
    }

    public function editForm(int $id): void
    {
        Auth::require();

        $ticket = $this->ticketOr404($id);
        $ticket['labels'] = implode(', ', (new LabelRepository())->forTicket($id));

        $this->renderForm($ticket, (int) $ticket['project_id'], $ticket);
    }

    public function update(int $id): void
    {
        Auth::require();

        $ticket = $this->ticketOr404($id);

        try {
            (new TicketService())->update($id, $_POST, Auth::id());
        } catch (ValidationError $e) {
            $this->renderForm($ticket, (int) $ticket['project_id'], $this->typed($ticket), $e->getMessage(), 422);

            return;
        } catch (ConflictError $e) {
            // What they typed stays in the form, and the form now carries the
            // new version: saving again is a deliberate "mine over theirs",
            // made with theirs on the screen.
            $typed = $this->typed($ticket);
            $typed['version'] = $e->current['version'] ?? $ticket['version'];

            $this->renderForm($ticket, (int) $ticket['project_id'], $typed, $e->getMessage(), 409, $e->current);

            return;
        }

        $this->flash(__('Ticket saved.'));
        $this->redirect('/tickets/' . $id);
    }

    /** The one-click move along the board, from the ticket page or the board. */
    public function changeStatus(int $id): void
    {
        Auth::require();

        $this->ticketOr404($id);

        try {
            (new TicketService())->changeStatus($id, $this->input('status'), Auth::id());
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        // Back where it was clicked, so moving a ticket from the board does not
        // land somebody on the ticket's own page.
        $this->back('/tickets/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::requireAdmin();

        $ticket = $this->ticketOr404($id);

        try {
            (new TicketService())->delete($id);
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->redirect('/tickets/' . $id);
        }

        $this->flash(__('{key} was deleted.', ['key' => $ticket['project_code'] . '-' . $ticket['number']]), 'warning');
        $this->redirect('/projects/' . $ticket['project_id']);
    }

    /**
     * The form, new or editing, with whatever values it should show.
     *
     * @param array<string, mixed>|null $ticket the ticket being edited, if any
     * @param array<string, mixed> $values what the fields hold
     * @param array<string, mixed>|null $theirs the version somebody else saved, after a conflict
     */
    private function renderForm(
        ?array $ticket,
        int $projectId,
        array $values,
        ?string $error = null,
        int $status = 200,
        ?array $theirs = null
    ): void {
        // Estimates are stored in minutes and typed as "1h 30m": the form shows
        // what was typed when it comes back with an error, and the stored value
        // written out otherwise.
        if (!isset($values['estimate_text'])) {
            $values['estimate_text'] = !empty($values['estimate_minutes'])
                ? Format::duration((int) $values['estimate_minutes'])
                : '';
        }

        $this->render('tickets/form.twig', [
            'ticket' => $ticket,
            'values' => $values,
            'project_id' => $projectId,
            'projects' => (new ProjectRepository())->allWithCounts(),
            'epics' => $projectId > 0 ? (new EpicRepository())->openForProject($projectId) : [],
            'people' => (new UserRepository())->active(),
            'priorities' => TicketRepository::PRIORITIES,
            'types' => TicketRepository::TYPES,
            'statuses' => $projectId > 0 ? (new StatusRepository())->forProject($projectId) : [],
            'all_labels' => (new LabelRepository())->all(),
            'error' => $error,
            'theirs' => $theirs,
        ], $status);
    }

    /**
     * What was posted, in the shape the form reads — so a mistake in one field
     * does not cost the other seven.
     *
     * @param array<string, mixed>|null $ticket
     * @return array<string, mixed>
     */
    private function typed(?array $ticket = null): array
    {
        return [
            'type' => $this->input('type', 'task'),
            'epic_id' => $this->idInput('epic_id'),
            'title' => $this->input('title'),
            'description' => $this->input('description'),
            'status' => $this->input('status'),
            'priority' => $this->input('priority', 'normal'),
            'assignee_id' => $this->idInput('assignee_id'),
            'estimate_text' => $this->input('estimate'),
            'due_on' => $this->input('due_on'),
            'story_points' => $this->input('story_points'),
            'labels' => $this->input('labels'),
            'version' => $this->input('version', (string) ($ticket['version'] ?? '')),
        ];
    }

    private function ticketOr404(int $id): array
    {
        $ticket = (new TicketRepository())->find($id);

        if ($ticket === null) {
            $this->notFound(__('There is no such ticket.'));
        }

        return $ticket;
    }
}
