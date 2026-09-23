<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\CommentRepository;
use CantoTrack\Model\TicketRepository;
use PDO;

/**
 * Comments: saying something on a ticket, correcting it, taking it back.
 *
 * A comment is its author's to edit and delete. An administrator may delete
 * anybody's — a pasted password has to be removable by someone who is at work
 * that day — but not edit it: words put in somebody else's mouth are worse
 * than words taken out.
 */
class CommentService
{
    public const MAX_LENGTH = 20000;

    private CommentRepository $comments;
    private TicketRepository $tickets;
    private Activity $activity;

    public function __construct(?PDO $db = null)
    {
        $db ??= DatabaseConnection::get();

        $this->comments = new CommentRepository($db);
        $this->tickets = new TicketRepository($db);
        $this->activity = new Activity($db);
    }

    /** @throws ValidationError */
    public function add(int $ticketId, int $userId, string $body): int
    {
        $ticket = $this->tickets->find($ticketId);

        if ($ticket === null) {
            throw new ValidationError(__('There is no such ticket.'));
        }

        $body = $this->body($body);
        $id = $this->comments->create($ticketId, $userId, $body);

        // The whole text goes to whoever listens: a mention is only a mention
        // if the notifications can see the @.
        $this->activity->happened($ticket, $userId, 'commented', 'comment', null, $body);

        return $id;
    }

    /** @throws ValidationError */
    public function edit(array $comment, string $body): void
    {
        $this->comments->update((int) $comment['id'], $this->body($body));
    }

    public function remove(array $comment): void
    {
        $this->comments->delete((int) $comment['id']);
    }

    public static function canEdit(array $comment, int $userId): bool
    {
        return (int) $comment['user_id'] === $userId;
    }

    public static function canDelete(array $comment, int $userId, bool $isAdmin): bool
    {
        return $isAdmin || (int) $comment['user_id'] === $userId;
    }

    private function body(string $body): string
    {
        $body = trim(str_replace("\r\n", "\n", $body));

        if ($body === '') {
            throw new ValidationError(__('An empty comment says nothing.'));
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new ValidationError(__('A comment can be at most {count} characters.', ['count' => self::MAX_LENGTH]));
        }

        return $body;
    }
}
