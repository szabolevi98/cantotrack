<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\HttpError;
use CantoTrack\Model\ApiTokenRepository;
use CantoTrack\Model\CommentRepository;
use CantoTrack\Model\LinkRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\StatusRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\CommentService;
use CantoTrack\Service\LinkService;
use CantoTrack\Service\Presenter;
use CantoTrack\Service\TicketService;
use CantoTrack\Service\TimerService;
use CantoTrack\Service\WorklogService;

/**
 * The JSON API, version 1: who is asking, the projects, the tickets, their
 * comments and hours, and the clock — for scripts and other programs. What
 * every endpoint shares (the token, the body, the Idempotency-Key) is in
 * ApiEndpoint.
 */
class ApiController extends ApiEndpoint
{
    // -----------------------------------------------------------------------
    // Who, and what
    // -----------------------------------------------------------------------

    public function me(): never
    {
        $this->json(['data' => Presenter::person((array) Auth::user(), true)]);
    }

    /** Signs this token out: the app's own "sign out". */
    public function signOut(): never
    {
        (new ApiTokenRepository())->revokeToken($this->token);
        \CantoTrack\Service\AuditLog::record('signout', 'user', (int) Auth::id(), (string) (Auth::user()['email'] ?? ''), 'API');

        $this->noContent();
    }

    public function users(): never
    {
        $this->json(['data' => array_map(fn(array $u): array => Presenter::person($u), (new UserRepository())->active())]);
    }

    public function projects(): never
    {
        $projects = (new ProjectRepository())->allWithCounts(isset($_GET['archived']));

        $this->json(['data' => array_map(fn(array $p): array => $this->projectData($p), $projects)]);
    }

    public function project(string $code): never
    {
        $project = $this->projectOr404($code);
        $statuses = (new StatusRepository())->forProject((int) $project['id']);

        $this->json(['data' => $this->projectData($project) + [
            'statuses' => array_map(static fn(array $s): array => [
                'id' => (int) $s['id'],
                'name' => $s['name'],
                'category' => $s['category'],
            ], $statuses),
        ]]);
    }

    // -----------------------------------------------------------------------
    // Tickets
    // -----------------------------------------------------------------------

    /**
     * A filtered page of tickets: ?project=CT&status=in_progress&assignee=me
     * &label=…&q=…&open=1&sprint=12&epic=4&release=7&parent=CT-12&due=overdue
     * &top_level=1&page=2&per_page=50.
     */
    public function tickets(): never
    {
        $filters = [];

        if (($_GET['project'] ?? '') !== '') {
            $filters['project_id'] = (int) $this->projectOr404((string) $_GET['project'])['id'];
        }

        // A sprint's tickets, or with "none" the ones in no sprint: a backlog.
        $sprint = (string) ($_GET['sprint'] ?? '');
        if ($sprint === 'none') {
            $filters['backlog_only'] = true;
        } elseif (ctype_digit($sprint)) {
            $filters['sprint_id'] = (int) $sprint;
        }

        foreach (['epic' => 'epic_id', 'release' => 'release_id'] as $given => $filter) {
            if (ctype_digit((string) ($_GET[$given] ?? ''))) {
                $filters[$filter] = (int) $_GET[$given];
            }
        }

        // A ticket's subtasks; or, the other way, only the tickets themselves.
        if (($_GET['parent'] ?? '') !== '') {
            $filters['parent_id'] = (int) $this->ticketOr404((string) $_GET['parent'])['id'];
        } elseif (!empty($_GET['top_level'])) {
            $filters['top_level'] = true;
        }

        if (in_array($_GET['due'] ?? '', ['overdue', 'week'], true)) {
            $filters['due'] = (string) $_GET['due'];
        }

        $assignee = (string) ($_GET['assignee'] ?? '');
        if ($assignee === 'me') {
            $filters['assignee_id'] = Auth::id();
        } elseif ($assignee === 'none') {
            $filters['unassigned'] = true;
        } elseif (ctype_digit($assignee)) {
            $filters['assignee_id'] = (int) $assignee;
        }

        foreach (['status', 'label', 'q', 'type'] as $key) {
            if (is_string($_GET[$key] ?? null) && $_GET[$key] !== '') {
                $filters[$key] = $_GET[$key];
            }
        }

        if (!empty($_GET['open'])) {
            $filters['open_only'] = true;
        }

        // ?query=project = CT AND assignee = me ORDER BY priority DESC
        if (is_string($_GET['query'] ?? null) && trim($_GET['query']) !== '') {
            $compiled = \CantoTrack\Service\TicketQuery::compile((string) $_GET['query'], Auth::id(), null, (new \CantoTrack\Model\CustomFieldRepository())->kindsByName());
            $filters['query_where'] = $compiled['where'];
            $filters['query_params'] = $compiled['params'];
            $filters['query_order'] = $compiled['order'];
        }

        [$page, $perPage, $offset] = $this->paging();
        $tickets = new TicketRepository();
        $total = $tickets->count($filters);

        $this->json([
            'data' => array_map(fn(array $t): array => Presenter::ticket($t), $tickets->search($filters, $perPage, $offset)),
            'meta' => self::pageMeta($page, $perPage, $total),
        ]);
    }

    public function ticket(string $key): never
    {
        $this->json(['data' => Presenter::ticket($this->ticketOr404($key), true)]);
    }

    /**
     * {"project": "CT", "title": "…", "description": "…", "type": "bug",
     *  "priority": "high", "status": "To do", "assignee_id": 3,
     *  "labels": ["api"], "estimate": "2h", "due_on": "2026-10-01",
     *  "story_points": 3, "epic_id": 4, "parent": "CT-12", "release": "1.4"}
     */
    public function createTicket(): never
    {
        $this->member();
        $input = $this->body();
        $input['project_id'] = (int) $this->projectOr404((string) ($input['project'] ?? ''))['id'];

        $id = (new TicketService())->create($input, (int) Auth::id());
        $ticket = (array) (new TicketRepository())->find($id);

        header('Location: ' . Presenter::url('/api/v1/tickets/' . $ticket['project_code'] . '-' . $ticket['number']));
        $this->json(['data' => Presenter::ticket($ticket, true)], 201);
    }

    /**
     * Only the fields sent change. "version" (from a read) makes the change
     * conditional: somebody else's save in between answers 409.
     */
    public function updateTicket(string $key): never
    {
        $this->member();
        $ticket = $this->ticketOr404($key);
        $input = $this->body();
        $service = new TicketService();

        $status = $input['status'] ?? null;
        $resolution = isset($input['resolution']) ? (string) $input['resolution'] : null;
        $sprintGiven = array_key_exists('sprint', $input) || array_key_exists('sprint_id', $input);
        $sprint = $input['sprint'] ?? $input['sprint_id'] ?? null;
        unset($input['status'], $input['resolution'], $input['project'], $input['project_id'], $input['sprint'], $input['sprint_id']);

        if ($sprintGiven && $sprint !== null && !ctype_digit((string) $sprint)) {
            throw new HttpError(422, __('A sprint is given by its id, or null for the backlog.'));
        }

        if ($input !== []) {
            $service->update((int) $ticket['id'], $input, (int) Auth::id());
        }

        if ($status !== null) {
            $service->changeStatus((int) $ticket['id'], (string) $status, (int) Auth::id(), $resolution);
        } elseif ($resolution !== null) {
            $service->resolve((int) $ticket['id'], $resolution, (int) Auth::id());
        }

        // Into a sprint, or with null back to the backlog — its subtasks
        // with it. A sprint on a board the ticket's project is not on
        // leaves it where it was, which is said rather than let pass.
        if ($sprintGiven) {
            $sprintId = $sprint === null ? null : (int) $sprint;
            (new \CantoTrack\Service\SprintService())->assign([(int) $ticket['id']], $sprintId, (int) Auth::id());

            if ((int) ((new TicketRepository())->find((int) $ticket['id'])['sprint_id'] ?? 0) !== (int) $sprintId) {
                throw new HttpError(422, __('{key}’s project is not on that sprint’s board.', ['key' => $key]));
            }
        }

        $this->json(['data' => Presenter::ticket((array) (new TicketRepository())->find((int) $ticket['id']), true)]);
    }

    /**
     * Deletes a ticket — an administrator's to do, as on the web, and only
     * one without hours or subtasks (422 otherwise).
     */
    public function deleteTicket(string $key): never
    {
        if (!Auth::isAdmin()) {
            $this->forbidden(__('Only an administrator can delete a ticket.'));
        }

        $ticket = $this->ticketOr404($key);
        (new TicketService())->delete((int) $ticket['id']);
        \CantoTrack\Service\AuditLog::record('ticket_deleted', 'ticket', (int) $ticket['id'], $ticket['project_code'] . '-' . $ticket['number'] . ' ' . $ticket['title']);

        $this->noContent();
    }

    /** Follows a ticket: its changes and comments reach you from now on. */
    public function watch(string $key): never
    {
        $ticket = $this->ticketOr404($key);
        (new \CantoTrack\Model\NotificationRepository())->watch((int) $ticket['id'], (int) Auth::id());

        $this->noContent();
    }

    /** Stops following it. Being given it or mentioned in it still reaches you. */
    public function unwatch(string $key): never
    {
        $ticket = $this->ticketOr404($key);
        (new \CantoTrack\Model\NotificationRepository())->unwatch((int) $ticket['id'], (int) Auth::id());

        $this->noContent();
    }

    // -----------------------------------------------------------------------
    // Links between tickets
    // -----------------------------------------------------------------------

    /** A ticket's links, each read from this ticket's end. */
    public function links(string $key): never
    {
        $ticket = $this->ticketOr404($key);

        $this->json(['data' => array_map(fn(array $l): array => $this->linkData($l), (new LinkRepository())->forTicket((int) $ticket['id']))]);
    }

    /** {"kind": "blocks", "ticket": "CT-7"} — kind is blocks, blocked_by, relates, duplicates or duplicated_by. */
    public function addLink(string $key): never
    {
        $this->member();
        $ticket = $this->ticketOr404($key);
        $input = $this->body();

        (new LinkService())->link((int) $ticket['id'], (string) ($input['kind'] ?? ''), (string) ($input['ticket'] ?? ''), Auth::id());

        $links = (new LinkRepository())->forTicket((int) $ticket['id']);
        $other = strtoupper(trim((string) ($input['ticket'] ?? '')));
        $made = array_values(array_filter($links, fn(array $l): bool => $l['other_code'] . '-' . $l['other_number'] === $other));

        $this->json(['data' => array_map(fn(array $l): array => $this->linkData($l), $made)], 201);
    }

    public function deleteLink(int $id): never
    {
        $this->member();
        $link = (new LinkRepository())->find($id);

        if ($link === null) {
            $this->notFound(__('There is no such link.'));
        }

        (new LinkService())->unlink($link, (int) $link['source_id'], Auth::id());

        $this->noContent();
    }

    // -----------------------------------------------------------------------
    // Comments and hours
    // -----------------------------------------------------------------------

    public function comments(string $key): never
    {
        $ticket = $this->ticketOr404($key);

        $this->json(['data' => array_map(fn(array $c): array => $this->commentData($c), (new CommentRepository())->forTicket((int) $ticket['id']))]);
    }

    /** {"body": "Markdown, @mentions and CT-12 included"} */
    public function addComment(string $key): never
    {
        $ticket = $this->ticketOr404($key);
        $id = (new CommentService())->add((int) $ticket['id'], (int) Auth::id(), (string) ($this->body()['body'] ?? ''));

        $this->json(['data' => $this->commentData((array) (new CommentRepository())->find($id))], 201);
    }

    /** {"body": "…"} — one's own comment, corrected. */
    public function updateComment(int $id): never
    {
        $comment = $this->commentOr404($id);

        if (!CommentService::canEdit($comment, (int) Auth::id())) {
            $this->forbidden(__('Only the person who wrote a comment can change it.'));
        }

        (new CommentService())->edit($comment, (string) ($this->body()['body'] ?? ''));

        $this->json(['data' => $this->commentData((array) (new CommentRepository())->find($id))]);
    }

    /** One's own comment taken back — anybody's, as an administrator. */
    public function deleteComment(int $id): never
    {
        $comment = $this->commentOr404($id);

        if (!CommentService::canDelete($comment, (int) Auth::id(), Auth::isAdmin())) {
            $this->forbidden(__('That is somebody else’s comment.'));
        }

        (new CommentService())->remove($comment);

        $this->noContent();
    }

    /** A ticket's hours, everybody's, the newest day first. */
    public function ticketWorklogs(string $key): never
    {
        $ticket = $this->ticketOr404($key);

        $this->json(['data' => array_map(fn(array $w): array => $this->worklogData($w), (new WorklogRepository())->forTicket((int) $ticket['id']))]);
    }

    /** ?from=2026-09-01&to=2026-09-30&user=3 — one's own when no user is given. */
    public function worklogs(): never
    {
        $this->member();
        $from = $this->date((string) ($_GET['from'] ?? ''), date('Y-m-d', strtotime('monday this week')));
        $to = $this->date((string) ($_GET['to'] ?? ''), date('Y-m-d'));
        $userId = ctype_digit((string) ($_GET['user'] ?? '')) ? (int) $_GET['user'] : (int) Auth::id();

        if ($to < $from || (strtotime($to) - strtotime($from)) > 366 * 86400) {
            throw new HttpError(422, __('A range of hours is at most a year, and ends after it starts.'));
        }

        $this->json([
            'data' => array_map(fn(array $w): array => $this->worklogData($w), (new WorklogRepository())->forRange($userId, $from, $to)),
            'meta' => ['from' => $from, 'to' => $to, 'user_id' => $userId],
        ]);
    }

    /** {"time": "1h 30m", "date": "2026-09-22", "note": "…", "remaining": "2h", "billable": true} */
    public function logWork(string $key): never
    {
        $this->member();
        $ticket = $this->ticketOr404($key);
        $input = $this->body();

        $logged = (new WorklogService())->log(
            (int) $ticket['id'],
            (int) Auth::id(),
            (string) ($input['time'] ?? ''),
            (string) ($input['date'] ?? ''),
            isset($input['note']) ? (string) $input['note'] : null,
            (string) ($input['remaining'] ?? ''),
            isset($input['billable']) ? (bool) $input['billable'] : null,
            (string) ($input['start'] ?? ''),
            (string) ($input['work_type'] ?? '')
        );

        $this->json(['data' => $this->worklogData((array) (new WorklogRepository())->find($logged['id'])) + ['rounded' => $logged['rounded']]], 201);
    }

    /**
     * {"time": "1h 30m", "date": "2026-09-22", "note": "…", "start": "09:30",
     *  "billable": true, "work_type": "Design"} — only the fields sent change.
     * A day that is handed in or approved, or hours already billed, are
     * refused with 422, as on the web.
     */
    public function updateWorklog(int $id): never
    {
        $this->member();
        $worklog = (new WorklogRepository())->find($id);

        if ($worklog === null) {
            $this->notFound(__('There is no such worklog.'));
        }

        if (!WorklogService::canChange($worklog, (int) Auth::id(), Auth::isAdmin())) {
            $this->forbidden(__('Those are somebody else’s hours.'));
        }

        $input = $this->body();
        $changed = (new WorklogService())->change(
            $worklog,
            isset($input['time']) ? (string) $input['time'] : $worklog['minutes'] . 'm',
            isset($input['date']) ? (string) $input['date'] : (string) $worklog['work_date'],
            array_key_exists('note', $input) ? (string) $input['note'] : ($worklog['note'] === null ? null : (string) $worklog['note']),
            isset($input['billable']) ? (bool) $input['billable'] : null,
            array_key_exists('start', $input) ? (string) $input['start'] : null,
            isset($input['work_type']) ? (string) $input['work_type'] : null
        );

        $this->json(['data' => $this->worklogData((array) (new WorklogRepository())->find($id)) + ['rounded' => $changed['rounded']]]);
    }

    public function deleteWorklog(int $id): never
    {
        $this->member();
        $worklog = (new WorklogRepository())->find($id);

        if ($worklog === null) {
            $this->notFound(__('There is no such worklog.'));
        }

        if (!WorklogService::canChange($worklog, (int) Auth::id(), Auth::isAdmin())) {
            $this->forbidden(__('Those are somebody else’s hours.'));
        }

        (new WorklogService())->remove($worklog);

        $this->noContent();
    }

    // -----------------------------------------------------------------------
    // The clock
    // -----------------------------------------------------------------------

    /** The clock that runs for you, or null — the same one the web shows. */
    public function timer(): never
    {
        $this->member();

        $this->json(['data' => $this->timerData((new TimerService())->running((int) Auth::id()))]);
    }

    /**
     * Starts the clock on a ticket. One that was running on another ticket
     * is stopped and logged first, and "logged" says what that came to.
     */
    public function startTimer(string $key): never
    {
        $this->member();
        $ticket = $this->ticketOr404($key);
        $timers = new TimerService();
        $logged = $timers->start((int) Auth::id(), (int) $ticket['id']);

        $this->json([
            'data' => $this->timerData($timers->running((int) Auth::id())),
            'logged' => $logged === null ? null : ['minutes' => $logged['minutes'], 'ticket' => $this->keyOf($logged['ticket_id'])],
        ], 201);
    }

    /**
     * {"note": "…"} — stops the clock and logs it. Under a minute nothing is
     * logged, and "data" is null.
     */
    public function stopTimer(): never
    {
        $this->member();
        $input = $this->body();
        $logged = (new TimerService())->stop((int) Auth::id(), isset($input['note']) ? (string) $input['note'] : null);

        $this->json(['data' => $logged === null ? null : ['minutes' => $logged['minutes'], 'ticket' => $this->keyOf($logged['ticket_id'])]]);
    }

    /** Stops the clock without logging anything. */
    public function discardTimer(): never
    {
        $this->member();
        (new TimerService())->discard((int) Auth::id());

        $this->noContent();
    }

    // -----------------------------------------------------------------------
    // What goes out
    // -----------------------------------------------------------------------

    private function commentOr404(int $id): array
    {
        $comment = (new CommentRepository())->find($id);

        if ($comment === null) {
            $this->notFound(__('There is no such comment.'));
        }

        return $comment;
    }

    /**
     * A link as seen from the ticket it was asked about: "blocks" CT-7 from
     * the one doing the blocking, "blocked_by" CT-3 from the one waiting —
     * the same words a new link is made with.
     */
    private function linkData(array $link): array
    {
        $out = $link['direction'] === 'out';

        return [
            'id' => (int) $link['id'],
            'kind' => match ((string) $link['kind']) {
                'blocks' => $out ? 'blocks' : 'blocked_by',
                'duplicates' => $out ? 'duplicates' : 'duplicated_by',
                default => 'relates',
            },
            'ticket' => $link['other_code'] . '-' . $link['other_number'],
            'title' => $link['other_title'],
            'status' => ['name' => $link['other_status'], 'category' => $link['other_category']],
        ];
    }

    private function projectData(array $project): array
    {
        return [
            'code' => $project['code'],
            'name' => $project['name'],
            'description' => $project['description'] ?? null,
            'archived' => (int) $project['is_archived'] === 1,
            'tickets' => isset($project['ticket_count']) ? (int) $project['ticket_count'] : null,
            'url' => Presenter::url('/projects/' . $project['id']),
        ];
    }

    private function commentData(array $comment): array
    {
        return [
            'id' => (int) $comment['id'],
            'author' => ['id' => (int) $comment['user_id'], 'name' => $comment['user_name']],
            'body' => $comment['body'],
            'created_at' => $comment['created_at'],
            'edited_at' => $comment['edited_at'] ?? null,
        ];
    }

    /**
     * "seconds" is how long it has run by the server's clock, so that an app
     * counts on from there rather than from a phone clock that may be off.
     */
    private function timerData(?array $timer): ?array
    {
        if ($timer === null) {
            return null;
        }

        $started = new \DateTimeImmutable((string) $timer['started_at']);

        return [
            'ticket' => $timer['project_code'] . '-' . $timer['ticket_number'],
            'title' => $timer['ticket_title'],
            'started_at' => $started->format(DATE_ATOM),
            'seconds' => max(0, time() - $started->getTimestamp()),
        ];
    }

    private function worklogData(array $worklog): array
    {
        return [
            'id' => (int) $worklog['id'],
            'ticket' => $worklog['project_code'] . '-' . $worklog['ticket_number'],
            'user' => ['id' => (int) $worklog['user_id'], 'name' => $worklog['user_name']],
            'date' => $worklog['work_date'],
            'start' => $worklog['started_at'] === null ? null : substr((string) $worklog['started_at'], 0, 5),
            'minutes' => (int) $worklog['minutes'],
            'work_type' => $worklog['work_type_name'] ?? null,
            'note' => $worklog['note'],
            'billable' => (int) ($worklog['billable'] ?? 1) === 1,
            'created_at' => $worklog['created_at'] ?? null,
        ];
    }
}
