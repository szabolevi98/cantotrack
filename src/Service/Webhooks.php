<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Logger;
use CantoTrack\Core\OutboundUrl;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Model\WebhookRepository;
use PDO;

/**
 * Telling other programs what happened: every change a service makes is
 * collected while the request runs, and at its end becomes one message per
 * ticket and kind of change — an edit of three fields is one
 * "ticket.changed" with three changes in it, not three messages.
 *
 * Messages are written down first and sent after. One that cannot be sent
 * (the receiver is down, slow, or answers 500) is tried again by
 * bin/webhooks.php, run from cron, a few times and further apart each time.
 *
 * Each message is signed: X-CantoTrack-Signature is "sha256=" and the
 * HMAC-SHA256 of the body with the webhook's secret, so the receiver can
 * tell it came from here and was not changed on the way.
 */
class Webhooks
{
    /** How many messages a web request sends itself before leaving the rest to cron. */
    private const SEND_INLINE = 5;

    /** @var array<string, array{ticket: array, actor: ?int, kind: string, changes: list<array>}> */
    private static array $pending = [];

    private static bool $registered = false;

    private WebhookRepository $hooks;

    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= DatabaseConnection::get();
        $this->hooks = new WebhookRepository($this->db);
    }

    /** Listens to every change, and sends what was collected when the request ends. */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        Activity::listen(static function (array $ticket, ?int $actorId, string $kind, ?string $field, ?string $old, ?string $new): void {
            self::collect($ticket, $actorId, $kind, $field, $old, $new);
        });

        register_shutdown_function(static function (): void {
            // The page is answered first and the messages go out after —
            // where the server can do that. Under mod_php it cannot: the
            // browser would wait for a slow receiver, so the messages are
            // only written down, and bin/webhooks.php sends them within the
            // minute.
            $answered = function_exists('fastcgi_finish_request') && fastcgi_finish_request();

            try {
                $webhooks = new self();
                $queued = $webhooks->flush();

                if ($answered) {
                    $webhooks->send($queued, self::SEND_INLINE);
                }
            } catch (\Throwable $e) {
                // A message that cannot be written or sent must never undo
                // the change it was about, nor turn the answer into an error.
                Logger::error('Webhooks could not be sent: ' . $e->getMessage());
            }
        });
    }

    public static function collect(array $ticket, ?int $actorId, string $kind, ?string $field, ?string $old, ?string $new): void
    {
        $key = (int) $ticket['id'] . '|' . $kind . '|' . (int) $actorId;

        self::$pending[$key] ??= ['ticket' => $ticket, 'actor' => $actorId, 'kind' => $kind, 'changes' => []];
        self::$pending[$key]['ticket'] = $ticket;
        self::$pending[$key]['changes'][] = array_filter(
            ['field' => $field, 'old' => $old, 'new' => $new],
            static fn($value): bool => $value !== null
        );
    }

    /**
     * Writes what was collected as deliveries, for every webhook that wants
     * it, and returns their ids.
     *
     * @return list<int>
     */
    public function flush(): array
    {
        $collected = self::$pending;
        self::$pending = [];
        $ids = [];

        if ($collected === []) {
            return [];
        }

        $tickets = new TicketRepository($this->db);
        $users = new UserRepository($this->db);

        foreach ($collected as $item) {
            $event = 'ticket.' . $item['kind'];
            $hooks = $this->hooks->listeningTo($event, (int) ($item['ticket']['project_id'] ?? 0));

            if ($hooks === []) {
                continue;
            }

            // The ticket as it is now, after everything in the request.
            $ticket = $tickets->find((int) $item['ticket']['id']) ?? $item['ticket'];
            $actor = $item['actor'] === null ? null : $users->find($item['actor']);

            $payload = [
                'event' => $event,
                'sent_at' => date(DATE_ATOM),
                'actor' => $actor === null ? null : Presenter::person($actor),
                'ticket' => isset($ticket['project_code']) ? Presenter::ticket($ticket) : ['id' => (int) $ticket['id']],
                'changes' => $item['changes'],
            ];

            foreach ($hooks as $hook) {
                $ids[] = $this->hooks->queue((int) $hook['id'], $event, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        return $ids;
    }

    /** A "ping" to one webhook, to see that the address answers. */
    public function ping(array $hook, array $actor): int
    {
        $id = $this->hooks->queue((int) $hook['id'], 'ping', (string) json_encode([
            'event' => 'ping',
            'sent_at' => date(DATE_ATOM),
            'actor' => Presenter::person($actor),
            'webhook' => ['id' => (int) $hook['id'], 'name' => $hook['name']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->send([$id]);

        return $id;
    }

    /**
     * Sends deliveries now; what is not sent (over the limit, or failed) waits
     * for its next turn.
     *
     * @param list<int> $ids
     */
    public function send(array $ids, int $limit = PHP_INT_MAX): int
    {
        $sent = 0;

        foreach (array_slice($ids, 0, $limit) as $id) {
            $delivery = $this->hooks->delivery($id);
            $hook = $delivery === null ? null : $this->hooks->find((int) $delivery['webhook_id']);

            if ($delivery === null || $hook === null || $delivery['state'] !== 'pending') {
                continue;
            }

            $result = self::post($hook, $delivery);
            $ok = $result['status'] !== null && $result['status'] >= 200 && $result['status'] < 300;
            $this->hooks->recordAttempt($id, $ok, $result);
            $sent += $ok ? 1 : 0;
        }

        return $sent;
    }

    /** What cron runs: every delivery whose turn it is. */
    public function sendDue(): int
    {
        return $this->send($this->hooks->due());
    }

    public static function signature(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /** @return array{status: ?int, body: ?string, error: ?string, ms: int} */
    private static function post(array $hook, array $delivery): array
    {
        $started = microtime(true);
        $body = (string) $delivery['payload'];

        try {
            $target = OutboundUrl::check((string) $hook['url']);
        } catch (ValidationError $e) {
            return ['status' => null, 'body' => null, 'error' => $e->getMessage(), 'ms' => 0];
        }

        $handle = curl_init((string) $hook['url']);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            // Connect to the address that was checked, not to whatever the
            // name resolves to a moment later.
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . (str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'])],
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: CantoTrack-Webhook/1',
                'X-CantoTrack-Event: ' . $delivery['event'],
                'X-CantoTrack-Delivery: ' . $delivery['id'],
                'X-CantoTrack-Signature: ' . self::signature($body, (string) $hook['secret']),
            ],
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = $response === false ? curl_error($handle) : null;
        curl_close($handle);

        return [
            'status' => $status > 0 ? $status : null,
            'body' => is_string($response) ? $response : null,
            'error' => $error,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /** For the tests: nothing collected carries over. */
    public static function reset(): void
    {
        self::$pending = [];
    }
}
