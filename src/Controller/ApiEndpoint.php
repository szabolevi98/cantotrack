<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Access;
use CantoTrack\Core\Auth;
use CantoTrack\Core\ClientIp;
use CantoTrack\Core\Controller;
use CantoTrack\Core\HttpError;
use CantoTrack\Core\LoginThrottle;
use CantoTrack\Model\ApiTokenRepository;
use CantoTrack\Model\IdempotencyRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\TicketRepository;

/**
 * What every endpoint of the JSON API does the same way: the token checked
 * and the person behind it acted as, the body read, a request sent twice
 * with an Idempotency-Key answered once, and the refusals.
 *
 * Every request carries a personal access token as a bearer token and acts
 * as the person it belongs to, with exactly their rights — the API is another
 * way in to the same rules, never a way around them: it calls the same
 * services the forms do. Failures come back as
 * {"error": {"status": …, "message": …}}.
 */
abstract class ApiEndpoint extends Controller
{
    protected const MAX_PAGE = 100;

    /** The token this request came with — for signing out with it. */
    protected string $token = '';

    /** The Idempotency-Key claimed for this request, while its answer is due. */
    private ?int $idempotencyId = null;

    public function __construct()
    {
        $this->authenticate();
        $this->idempotent();
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

        $this->token = $m[1];
        Auth::actAs($user);
    }

    /**
     * A change sent with an Idempotency-Key is done once, however many times
     * it arrives (see the 0051 migration).
     *
     * The same key and the same request again get the first answer again,
     * with Idempotent-Replayed: true; while the first is still being worked
     * on, 409; the same key with a different request, 422. A request that
     * fails lets its key go when it ends, so that it can be tried again.
     */
    private function idempotent(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;

        if ($key === null || in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $key = trim((string) $key);

        if (preg_match('/^[\x21-\x7E]{1,100}$/', $key) !== 1) {
            throw new HttpError(400, __('An Idempotency-Key is 1 to 100 visible characters, without spaces — a UUID, say.'));
        }

        $keys = new IdempotencyRepository();
        $claim = $keys->claim((int) Auth::id(), $key, $this->fingerprint($method));

        switch ($claim['state']) {
            case 'new':
                $id = (int) ($claim['id'] ?? 0);
                $this->idempotencyId = $id;
                // Whatever ends the request before its answer — a refusal, a
                // fault — lets the key go: nothing was done under it.
                register_shutdown_function(function () use ($keys, $id): void {
                    if ($this->idempotencyId === $id) {
                        $keys->release($id);
                    }
                });

                return;

            case 'replay':
                $row = (array) ($claim['row'] ?? []);
                http_response_code((int) $row['status']);
                header('Idempotent-Replayed: true');
                header('Cache-Control: no-store');

                if ($row['location'] !== null) {
                    header('Location: ' . $row['location']);
                }

                if ((string) $row['body'] !== '') {
                    header('Content-Type: application/json; charset=utf-8');
                    echo $row['body'];
                }

                exit;

            case 'mismatch':
                throw new HttpError(422, __('That Idempotency-Key was used for a different request. Use a new key for a new request.'));

            default:
                header('Retry-After: 1');
                throw new HttpError(409, __('The first request with that Idempotency-Key is still being worked on. Ask again in a moment.'));
        }
    }

    /** What makes two requests the same one: the method, the address and what was sent. */
    private function fingerprint(string $method): string
    {
        $body = (string) file_get_contents('php://input');

        // A multipart upload is not in php://input: its fields and its files
        // stand for it instead.
        if ($body === '' && ($_POST !== [] || $_FILES !== [])) {
            $files = [];
            foreach ($_FILES as $field => $file) {
                foreach ((array) ($file['tmp_name'] ?? []) as $index => $tmp) {
                    $files[] = [$field, $index, is_string($tmp) && is_file($tmp) ? hash_file('sha256', $tmp) : null];
                }
            }
            $body = (string) json_encode([$_POST, $files]);
        }

        return hash('sha256', $method . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . "\n" . $body);
    }

    /**
     * What the request sent: a JSON object, or a form for the clients that
     * send one.
     *
     * @return array<string, mixed>
     */
    protected function body(): array
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
    protected function member(): void
    {
        if (Access::isGuest()) {
            $this->forbidden(__('Guests can read and comment, but not change the work.'));
        }
    }

    /** A day from the query string, or the default when none was given. */
    protected function date(string $given, string $default): string
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

    /** The page asked for, and how many to a page: [page, per page, offset]. */
    protected function paging(int $default = 50): array
    {
        $perPage = max(1, min(self::MAX_PAGE, (int) ($_GET['per_page'] ?? $default)));
        $page = max(1, (int) ($_GET['page'] ?? 1));

        return [$page, $perPage, ($page - 1) * $perPage];
    }

    /** @return array{page: int, per_page: int, total: int, pages: int} */
    protected static function pageMeta(int $page, int $perPage, int $total): array
    {
        return ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / $perPage)];
    }

    protected function projectOr404(string $code): array
    {
        $project = (new ProjectRepository())->findByCode(strtoupper(trim($code)));

        if ($project === null) {
            $this->notFound(__('There is no project {code}.', ['code' => $code]));
        }

        return $project;
    }

    protected function ticketOr404(string $key): array
    {
        $ticket = (new TicketRepository())->findByKey($key);

        if ($ticket === null) {
            $this->notFound(__('There is no ticket {key}.', ['key' => $key]));
        }

        return $ticket;
    }

    protected function keyOf(int $ticketId): ?string
    {
        $ticket = (new TicketRepository())->find($ticketId);

        return $ticket === null ? null : $ticket['project_code'] . '-' . $ticket['number'];
    }

    // -----------------------------------------------------------------------
    // The answer
    // -----------------------------------------------------------------------

    /**
     * The answer, written down first under the request's Idempotency-Key if
     * it came with one, so that a resend gets it again.
     *
     * @param array<string, mixed> $data
     */
    protected function json(array $data, int $status = 200): never
    {
        if ($this->idempotencyId !== null && $status < 400) {
            $this->remember($status, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        parent::json($data, $status);
    }

    /** 204: done, and nothing to show for it. */
    protected function noContent(): never
    {
        if ($this->idempotencyId !== null) {
            $this->remember(204, '');
        }

        http_response_code(204);
        exit;
    }

    private function remember(int $status, string $body): void
    {
        $location = null;
        foreach (headers_list() as $header) {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, strlen('Location:')));
            }
        }

        (new IdempotencyRepository())->complete((int) $this->idempotencyId, $status, $body, $location);
        $this->idempotencyId = null;
    }
}
