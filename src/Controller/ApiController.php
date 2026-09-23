<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Access;
use CantoTrack\Core\Auth;
use CantoTrack\Core\ClientIp;
use CantoTrack\Core\Controller;
use CantoTrack\Core\HttpError;
use CantoTrack\Core\LoginThrottle;
use CantoTrack\Model\ApiTokenRepository;
use CantoTrack\Model\CommentRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\StatusRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\CommentService;
use CantoTrack\Service\Presenter;
use CantoTrack\Service\TicketService;
use CantoTrack\Service\WorklogService;

/**
 * The JSON API, version 1: tickets, their comments and hours, for scripts
 * and other programs.
 *
 * Every request carries a personal access token as a bearer token and acts
 * as the person it belongs to, with exactly their rights — the API is another
 * way in to the same rules, never a way around them: it calls the same
 * services the forms do. Failures come back as
 * {"error": {"status": …, "message": …}}.
 */
class ApiController extends Controller
{
    private const MAX_PAGE = 100;

    public function __construct()
    {
        $this->authenticate();
    }

    // -----------------------------------------------------------------------
    // Who, and what
    // -----------------------------------------------------------------------

    public function me(): never
    {
        $this->json(['data' => Presenter::person((array) Auth::user(), true)]);
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
     * &label=…&q=…&open=1&page=2&per_page=50.
     */
    public function tickets(): never
    {
        $filters = [];

        if (($_GET['project'] ?? '') !== '') {
            $filters['project_id'] = (int) $this->projectOr404((string) $_GET['project'])['id'];
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

        $perPage = max(1, min(self::MAX_PAGE, (int) ($_GET['per_page'] ?? 50)));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $tickets = new TicketRepository();
        $total = $tickets->count($filters);

        $this->json([
            'data' => array_map(fn(array $t): array => Presenter::ticket($t), $tickets->search($filters, $perPage, ($page - 1) * $perPage)),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / $perPage)],
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
        unset($input['status'], $input['project'], $input['project_id']);

        if ($input !== []) {
            $service->update((int) $ticket['id'], $input, (int) Auth::id());
        }

        if ($status !== null) {
            $service->changeStatus((int) $ticket['id'], (string) $status, (int) Auth::id());
        }

        $this->json(['data' => Presenter::ticket((array) (new TicketRepository())->find((int) $ticket['id']), true)]);
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

        http_response_code(204);
        exit;
    }

    // -----------------------------------------------------------------------
    // The request
    // -----------------------------------------------------------------------

    /**
     * The token from the Authorization header, checked, and the person it
     * belongs to acted as for the rest of the request.
     *
     * Wrong tokens count against the connection like wrong passwords do, and
     * a connection with too many is answered 429 for a while. A right token
     * is never held up by that: a CI server that shares its address with a
     * script still using a revoked token keeps working. (Guessing one is not
     * what the limit is for — 160 random bits are not guessed — but a client
     * stuck retrying a dead token should not get an answer every time.)
     */
    private function authenticate(): void
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) !== 1) {
            header('WWW-Authenticate: Bearer realm="CantoTrack"');
            throw new HttpError(401, __('Send a personal access token: "Authorization: Bearer ct_…". Make one on your profile.'));
        }

        $user = (new ApiTokenRepository())->userFor($m[1]);

        if ($user === null) {
            $ip = ClientIp::get();
            $throttle = new LoginThrottle();
            $key = 'api-token@' . $ip;

            if ($throttle->isBlocked($key, $ip)) {
                header('Retry-After: ' . LoginThrottle::WINDOW_MINUTES * 60);
                throw new HttpError(429, __('Too many wrong tokens from here. Try again in {minutes} minutes.', ['minutes' => LoginThrottle::WINDOW_MINUTES]));
            }

            $throttle->recordFailure($key, $ip);
            header('WWW-Authenticate: Bearer realm="CantoTrack", error="invalid_token"');
            throw new HttpError(401, __('That token is not valid: unknown, expired, or its account is deactivated.'));
        }

        Auth::actAs($user);
    }

    /**
     * What the request sent: a JSON object, or a form for the clients that
     * send one.
     *
     * @return array<string, mixed>
     */
    private function body(): array
    {
        if (!str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'json')) {
            return $_POST;
        }

        $raw = (string) file_get_contents('php://input');

        if (trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new HttpError(400, __('The body has to be a JSON object.'));
        }

        return $data;
    }

    /** Guests read and comment; the rest of the API is for the team. */
    private function member(): void
    {
        if (Access::isGuest()) {
            $this->forbidden(__('Guests can read and comment, but not change the work.'));
        }
    }

    private function date(string $given, string $default): string
    {
        if ($given === '') {
            return $default;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $given);

        if ($date === false || $date->format('Y-m-d') !== $given) {
            throw new HttpError(422, __('Dates are written 2026-09-22.'));
        }

        return $given;
    }

    private function projectOr404(string $code): array
    {
        $project = (new ProjectRepository())->findByCode(strtoupper(trim($code)));

        if ($project === null) {
            $this->notFound(__('There is no project {code}.', ['code' => $code]));
        }

        return $project;
    }

    private function ticketOr404(string $key): array
    {
        $ticket = (new TicketRepository())->findByKey($key);

        if ($ticket === null) {
            $this->notFound(__('There is no ticket {key}.', ['key' => $key]));
        }

        return $ticket;
    }

    // -----------------------------------------------------------------------
    // What goes out
    // -----------------------------------------------------------------------

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
