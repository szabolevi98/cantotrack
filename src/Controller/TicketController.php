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
use CantoTrack\Model\LinkRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\ReleaseRepository;
use CantoTrack\Model\SprintRepository;
use CantoTrack\Model\StatusRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\AttachmentService;
use CantoTrack\Service\LinkService;
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

        [$filters, $queryError] = $this->listFilters();
        $query = trim((string) ($_GET['query'] ?? ''));

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
            'query' => http_build_query(array_diff_key($_GET, ['page' => true, 'filter' => true])),
            'projects' => (new ProjectRepository())->allWithCounts(),
            'people' => (new UserRepository())->active(),
            // A project's own columns once one project is picked; across
            // projects only the three kinds of column mean the same thing.
            'statuses' => $filters['project_id'] ? (new StatusRepository())->forProject($filters['project_id']) : [],
            'labels' => (new LabelRepository())->all(),
            'types' => TicketRepository::TYPES,
            'sprints' => $filters['project_id'] ? array_values(array_filter(
                (new SprintRepository())->forProject($filters['project_id']),
                static fn(array $s): bool => $s['state'] !== 'closed'
            )) : [],
            'saved' => $this->savedFilter(),
            'query_text' => $query,
            'query_error' => $queryError,
            'query_mode' => $query !== '' || ($_GET['mode'] ?? '') === 'query',
            'as_query' => \CantoTrack\Service\TicketQuery::fromFilters($filters, $this->namesFor($filters)),
            'vocabulary' => \CantoTrack\Service\TicketQuery::vocabulary(),
            'custom_names' => array_values(array_unique(array_map(static fn(array $f): string => (string) $f['name'], (new \CantoTrack\Model\CustomFieldRepository())->visible()))),
            'releases' => $filters['project_id'] ? (new ReleaseRepository())->forProject($filters['project_id']) : [],
        ]);
    }

    /**
     * The list's filters with its query compiled into them, and what is
     * wrong with the query if it cannot be read — in which case the list is
     * empty rather than the whole tracker.
     *
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function listFilters(): array
    {
        $filters = $this->filters();
        $query = trim((string) ($_GET['query'] ?? ''));

        if ($query === '') {
            return [$filters, null];
        }

        try {
            $compiled = \CantoTrack\Service\TicketQuery::compile($query, Auth::id(), null, (new \CantoTrack\Model\CustomFieldRepository())->kindsByName());
            $filters['query_where'] = $compiled['where'];
            $filters['query_params'] = $compiled['params'];
            $filters['query_order'] = $compiled['order'];

            return [$filters, null];
        } catch (ValidationError $e) {
            $filters['query_where'] = 'FALSE';

            return [$filters, $e->getMessage()];
        }
    }

    /**
     * The list as a spreadsheet: every ticket the same filters or query find,
     * with the projects' own fields as columns of their own.
     */
    public function export(): void
    {
        Auth::require();

        [$filters] = $this->listFilters();
        $tickets = (new TicketRepository())->search($filters, 500);
        $fields = new \CantoTrack\Model\CustomFieldRepository();
        $names = array_values(array_unique(array_map(static fn(array $f): string => (string) $f['name'], $fields->visible())));
        $byId = [];
        foreach ($fields->visible() as $field) {
            $byId[(int) $field['id']] = $field;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tickets-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: private, no-store');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            exit;
        }

        $safe = static fn(mixed $v): string => ReportController::neutral((string) ($v ?? ''));
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(['Key', 'Type', 'Title', 'Status', 'Priority', 'Assignee', 'Reporter', 'Epic', 'Sprint', 'Release', 'Parent', 'Labels', 'Points', 'Estimate (h)', 'Logged (h)', 'Due', 'Created', 'Finished'], $names), ',', '"', '');

        foreach ($tickets as $t) {
            $values = [];
            foreach ($fields->valuesFor((int) $t['id']) as $fieldId => $value) {
                if (isset($byId[$fieldId])) {
                    $values[(string) $byId[$fieldId]['name']] = $byId[$fieldId]['kind'] === 'checkbox' ? 'yes' : $value;
                }
            }

            fputcsv($out, array_merge([
                $t['project_code'] . '-' . $t['number'], $t['type'], $safe($t['title']), $t['status_name'], $t['priority'],
                $t['assignee_name'] ?? '', $t['reporter_name'] ?? '', $safe($t['epic_title']), $t['sprint_name'] ?? '', $t['release_name'] ?? '',
                $t['parent_id'] === null ? '' : $t['project_code'] . '-' . $t['parent_number'],
                $safe(str_replace("\n", ', ', (string) ($t['label_names'] ?? ''))),
                $t['story_points'] ?? '', $t['estimate_minutes'] ? Format::hours((int) $t['estimate_minutes']) : '',
                Format::hours((int) $t['logged_minutes']), $t['due_on'] ?? '', substr((string) $t['created_at'], 0, 10), $t['closed_at'] ? substr((string) $t['closed_at'], 0, 10) : '',
            ], array_map(static fn(string $name): string => $safe($values[$name] ?? ''), $names)), ',', '"', '');
        }

        fclose($out);
        exit;
    }

    /**
     * The names the simple filters stand for — the project's code, the
     * person's name, the column's — to write them out as a query.
     *
     * @return array<string, array<int, string>>
     */
    private function namesFor(array $filters): array
    {
        $names = ['project' => [], 'person' => [], 'status' => [], 'release' => []];

        if (!empty($filters['project_id']) && ($project = (new ProjectRepository())->find((int) $filters['project_id'])) !== null) {
            $names['project'][(int) $project['id']] = (string) $project['code'];

            foreach ((new StatusRepository())->forProject((int) $project['id']) as $status) {
                $names['status'][(int) $status['id']] = (string) $status['name'];
            }

            foreach ((new ReleaseRepository())->forProject((int) $project['id']) as $release) {
                $names['release'][(int) $release['id']] = (string) $release['name'];
            }
        }

        if (!empty($filters['assignee_id']) && ($person = (new UserRepository())->find((int) $filters['assignee_id'])) !== null) {
            $names['person'][(int) $person['id']] = (string) $person['name'];
        }

        return $names;
    }

    /**
     * What a query can be finished with, for the suggestions under the box:
     * the values one field takes, among what the person may see.
     */
    public function queryValues(): void
    {
        Auth::require();

        $field = strtolower((string) ($_GET['field'] ?? ''));
        $db = \CantoTrack\Core\DatabaseConnection::get();
        $visible = \CantoTrack\Core\Access::sql('project_id');
        $column = static function (string $sql) use ($db): array {
            $statement = $db->query($sql);

            return $statement === false ? [] : array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
        };

        $values = match ($field) {
            'project' => $column('SELECT code FROM projects WHERE is_archived = 0' . str_replace('project_id', 'id', $visible) . ' ORDER BY code'),
            'status' => $column('SELECT DISTINCT name FROM statuses WHERE 1 = 1' . $visible . ' ORDER BY name'),
            'category', 'statuscategory' => ['to do', 'in progress', 'done'],
            'type', 'issuetype' => array_merge(TicketRepository::TYPES, ['subtask']),
            'priority' => TicketRepository::PRIORITIES,
            'assignee', 'reporter', 'watcher' => array_merge(['me', 'currentUser()'], array_map(static fn(array $u): string => (string) $u['name'], (new UserRepository())->active())),
            'epic' => $column('SELECT DISTINCT title FROM epics WHERE is_done = 0' . $visible . ' ORDER BY title'),
            'sprint' => array_merge(['openSprints()', 'closedSprints()', 'futureSprints()'], $column('SELECT name FROM sprints WHERE state <> \'closed\'' . $visible . ' ORDER BY name')),
            'release', 'fixversion', 'version' => array_merge(['unreleased()', 'released()'], $column('SELECT name FROM releases WHERE released_at IS NULL' . $visible . ' ORDER BY name')),
            'label', 'labels' => $column('SELECT name FROM labels ORDER BY name'),
            'created', 'updated', 'resolved', 'due', 'duedate' => ['startOfDay()', 'startOfWeek()', 'endOfWeek()', 'startOfMonth()', 'endOfMonth()', '-7d', '-1m'],
            default => $this->customValues($field),
        };

        $this->json(['values' => array_slice($values, 0, 200)]);
    }

    /** @return list<string> the choices of one of the projects' own fields, by its name */
    private function customValues(string $name): array
    {
        $values = [];

        foreach ((new \CantoTrack\Model\CustomFieldRepository())->visible() as $field) {
            if (mb_strtolower((string) $field['name']) === trim(mb_strtolower($name), '"')) {
                $values = array_merge($values, match ($field['kind']) {
                    'select' => $field['choices'],
                    'checkbox' => ['yes', 'no'],
                    'date' => ['startOfDay()', 'startOfWeek()', 'endOfMonth()', '-7d'],
                    default => [],
                });
            }
        }

        return array_values(array_unique($values));
    }

    /** The saved filter the list was opened from, if it is one this person may see. */
    private function savedFilter(): ?array
    {
        $id = $this->idQuery('filter');
        $filter = $id === null ? null : (new \CantoTrack\Model\SavedFilterRepository())->find($id);

        if ($filter === null || ((int) $filter['user_id'] !== (int) Auth::id() && (int) $filter['is_shared'] !== 1)) {
            return null;
        }

        return $filter;
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
            'release_id' => $this->idQuery('release'),
        ];
    }

    public function show(int $id): void
    {
        Auth::require();

        $ticket = $this->ticketOr404($id);
        $showing = in_array($_GET['activity'] ?? '', ['comments', 'history'], true) ? $_GET['activity'] : 'all';

        // Looking at the ticket is reading what was said about it.
        $notifications = new \CantoTrack\Model\NotificationRepository();
        $notifications->markTicketRead((int) Auth::id(), $id);

        $this->render('tickets/show.twig', [
            'ticket' => $ticket,
            'remaining' => TicketRepository::remaining($ticket),
            'watching' => $notifications->isWatching($id, (int) Auth::id()),
            'favourite' => (new TicketRepository())->isFavourite($id, (int) Auth::id()),
            'watchers' => $notifications->watcherCount($id),
            'labels' => (new LabelRepository())->forTicket($id),
            'attachments' => (new AttachmentRepository())->forTicket($id),
            'links' => (new LinkRepository())->forTicket($id),
            'link_kinds' => array_keys(LinkService::OFFERED),
            'sprints' => array_values(array_filter(
                (new SprintRepository())->forProject((int) $ticket['project_id']),
                static fn(array $s): bool => $s['state'] !== 'closed'
            )),
            'max_upload_mb' => intdiv(AttachmentService::maxBytes(), 1048576),
            'people' => (new UserRepository())->active(),
            'statuses' => (new StatusRepository())->forProject((int) $ticket['project_id']),
            'worklogs' => (new WorklogRepository())->forTicket($id),
            'subtasks' => (new TicketRepository())->subtasks($id),
            'pages' => (new \CantoTrack\Model\PageRepository())->mentioning($id),
            'custom_fields' => (new \CantoTrack\Model\CustomFieldRepository())->forProject((int) $ticket['project_id']),
            'field_values' => (new \CantoTrack\Model\CustomFieldRepository())->valuesFor($id),
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
        Auth::requireMember();

        $projectId = (int) $this->idQuery('project');

        $this->renderForm(null, $projectId, [
            'epic_id' => $this->idQuery('epic'),
            'parent' => trim((string) ($_GET['parent'] ?? '')),
        ]);
    }

    public function create(): void
    {
        Auth::requireMember();

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
        Auth::requireMember();

        $ticket = $this->ticketOr404($id);
        $ticket['labels'] = implode(', ', (new LabelRepository())->forTicket($id));
        $ticket['parent'] = $ticket['parent_id'] === null ? '' : $ticket['project_code'] . '-' . $ticket['parent_number'];

        $this->renderForm($ticket, (int) $ticket['project_id'], $ticket);
    }

    public function update(int $id): void
    {
        Auth::requireMember();

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

    /** A subtask added from its parent's page: a title, and whose it is. */
    public function addSubtask(int $id): void
    {
        Auth::requireMember();

        $parent = $this->ticketOr404($id);

        try {
            (new TicketService())->addSubtask($parent, $this->input('title'), $this->idInput('assignee_id'), (int) Auth::id());
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/tickets/' . $id . '#subtasks');
    }

    /** The one-click move along the board, from the ticket page or the board. */
    public function changeStatus(int $id): void
    {
        Auth::requireMember();

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

    /**
     * The same change made to many tickets at once, from the list.
     *
     * Each ticket is changed through the same rules as its own edit form, one
     * by one — so a person who cannot be assigned is refused for all of them,
     * and each ticket's history says who changed what. The ones a change does
     * not fit (a column of another project) are skipped and counted.
     */
    public function bulk(): void
    {
        Auth::requireMember();

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['ids'] ?? [])),
            static fn(int $id): bool => $id > 0
        )));

        if ($ids === []) {
            $this->flash(__('Tick the tickets to change first.'), 'danger');
            $this->back('/tickets');
        }

        $changes = [];
        $assignee = $this->input('set_assignee');
        if ($assignee !== '') {
            $changes['assignee_id'] = $assignee === 'none' ? null : (int) $assignee;
        }
        foreach (['set_priority' => 'priority', 'set_type' => 'type'] as $field => $key) {
            if ($this->input($field) !== '') {
                $changes[$key] = $this->input($field);
            }
        }

        $release = $this->input('set_release');
        if ($release !== '') {
            $changes['release_id'] = $release === 'none' ? null : (int) $release;
        }

        $status = $this->input('set_status');
        $sprint = $this->input('set_sprint');
        $addLabel = $this->input('add_label');
        $removeLabel = mb_strtolower($this->input('remove_label'));

        $service = new TicketService();
        $sprints = new \CantoTrack\Service\SprintService();
        $labels = new LabelRepository();
        $changed = 0;
        $skipped = [];

        foreach ($ids as $id) {
            try {
                $ticketChanges = $changes;

                if ($addLabel !== '' || $removeLabel !== '') {
                    $current = $labels->forTicket($id);
                    $next = array_values(array_filter($current, static fn(string $l): bool => mb_strtolower($l) !== $removeLabel));
                    if ($addLabel !== '') {
                        $next[] = $addLabel;
                    }
                    $ticketChanges['labels'] = $next;
                }

                if ($ticketChanges !== []) {
                    $service->update($id, $ticketChanges, Auth::id());
                }

                if ($status !== '') {
                    $service->changeStatus($id, $status, Auth::id());
                }

                if ($sprint !== '') {
                    $sprints->assign([$id], $sprint === 'backlog' ? null : (int) $sprint, Auth::id());
                }

                $changed++;
            } catch (ValidationError $e) {
                $skipped[$e->getMessage()] = ($skipped[$e->getMessage()] ?? 0) + 1;
            }
        }

        $this->flash(__n('{count} ticket changed.', '{count} tickets changed.', $changed), $changed > 0 ? 'success' : 'warning');

        foreach ($skipped as $reason => $count) {
            $this->flash(__n('{count} was left as it was: {reason}', '{count} were left as they were: {reason}', $count, ['reason' => $reason]), 'warning');
        }

        $this->back('/tickets');
    }

    /**
     * A card dropped on the board, sent by the board's script: which column,
     * between which two cards, and — across swimlanes — whose or which epic's.
     * Answers in JSON; the page has already moved the card and only needs to
     * hear that it may stay there.
     */
    public function move(int $id): void
    {
        Auth::requireMember();

        $this->ticketOr404($id);

        $lane = [];
        $field = $this->input('lane_field');
        if (in_array($field, ['assignee_id', 'epic_id'], true)) {
            $lane[$field] = $this->idInput('lane_value');
        }

        try {
            (new TicketService())->move(
                $id,
                $this->input('status'),
                $this->idInput('above'),
                $this->idInput('below'),
                $lane,
                Auth::id()
            );
        } catch (ValidationError $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        $this->json(['ok' => true]);
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
            'releases' => $projectId > 0 ? (new ReleaseRepository())->unreleased($projectId) : [],
            // A ticket being edited has its project's fields; a new one, every
            // project's, shown as its project is chosen.
            'custom_fields' => $ticket !== null ? (new \CantoTrack\Model\CustomFieldRepository())->forProject($projectId) : (new \CantoTrack\Model\CustomFieldRepository())->visible(),
            'field_values' => $values['fields'] ?? ($ticket !== null ? (new \CantoTrack\Model\CustomFieldRepository())->valuesFor((int) $ticket['id']) : []),
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
            'parent' => $this->input('parent'),
            'release_id' => $this->idInput('release_id'),
            'fields' => is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [],
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

    /** The star on a ticket: one's own list of the tickets one keeps coming back to. */
    public function favourite(int $id): void
    {
        Auth::require();

        $ticket = $this->ticketOr404($id);
        $starred = (new TicketRepository())->toggleFavourite((int) $ticket['id'], (int) Auth::id());

        $this->flash($starred
            ? __('Starred: it has a row in your week’s grid, and comes first when you log time.')
            : __('The star is off.'));
        $this->back('/tickets/' . $id);
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
