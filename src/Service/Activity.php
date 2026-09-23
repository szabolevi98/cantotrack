<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Model\EventRepository;
use PDO;

/**
 * Everything that happens to a ticket goes through here: it is written into
 * the ticket's history, and passed on to whoever has to hear about it.
 *
 * One door, so that the history, the notifications and the webhooks cannot
 * disagree about what happened — they are all told by the same call.
 */
class Activity
{
    /** @var list<callable(array, ?int, string, ?string, ?string, ?string): void> */
    private static array $listeners = [];

    private EventRepository $events;

    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= DatabaseConnection::get();
        $this->events = new EventRepository($this->db);
    }

    /**
     * Something happened to a ticket.
     *
     * @param array $ticket the ticket as it is now (at least its id)
     */
    public function happened(
        array $ticket,
        ?int $actorId,
        string $kind,
        ?string $field = null,
        ?string $old = null,
        ?string $new = null
    ): void {
        $this->events->record((int) $ticket['id'], $actorId, $kind, $field, $old, $new);

        foreach (self::$listeners as $listener) {
            $listener($ticket, $actorId, $kind, $field, $old, $new);
        }
    }

    /**
     * Something else wants to hear about every change — the notifications and
     * the webhooks register themselves here when the application starts.
     *
     * @param callable(array, ?int, string, ?string, ?string, ?string): void $listener
     */
    public static function listen(callable $listener): void
    {
        self::$listeners[] = $listener;
    }

    public static function forgetListeners(): void
    {
        self::$listeners = [];
    }
}
