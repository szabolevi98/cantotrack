<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\LinkRepository;
use CantoTrack\Model\TicketRepository;
use PDO;

/**
 * Linking tickets: "this blocks that", "this is a duplicate of that", "these
 * two are about the same thing".
 *
 * People think of a link from the ticket they are looking at, so the form
 * offers both readings of the one-way kinds — "blocks" and "is blocked by" —
 * and this class turns either into the one row it is stored as.
 */
class LinkService
{
    /** What the form offers, and how each is stored: [kind, this ticket is the source]. */
    public const OFFERED = [
        'blocks' => ['blocks', true],
        'blocked_by' => ['blocks', false],
        'relates' => ['relates', true],
        'duplicates' => ['duplicates', true],
        'duplicated_by' => ['duplicates', false],
    ];

    private LinkRepository $links;
    private TicketRepository $tickets;
    private Activity $activity;

    public function __construct(?PDO $db = null)
    {
        $db ??= DatabaseConnection::get();

        $this->links = new LinkRepository($db);
        $this->tickets = new TicketRepository($db);
        $this->activity = new Activity($db);
    }

    /** @throws ValidationError */
    public function link(int $ticketId, string $offered, string $otherKey, ?int $userId): void
    {
        $ticket = $this->tickets->find($ticketId);
        $other = $this->tickets->findByKey($otherKey);

        if ($ticket === null) {
            throw new ValidationError(__('There is no such ticket.'));
        }

        if ($other === null) {
            throw new ValidationError(__('There is no ticket called {key}.', ['key' => strtoupper(trim($otherKey))]));
        }

        if ((int) $other['id'] === $ticketId) {
            throw new ValidationError(__('A ticket cannot be linked to itself.'));
        }

        if (!isset(self::OFFERED[$offered])) {
            throw new ValidationError(__('That is not a kind of link.'));
        }

        [$kind, $thisIsSource] = self::OFFERED[$offered];
        $source = $thisIsSource ? $ticketId : (int) $other['id'];
        $target = $thisIsSource ? (int) $other['id'] : $ticketId;

        if ($this->links->exists($source, $target, $kind)) {
            throw new ValidationError(__('Those two are already linked like that.'));
        }

        // A ticket blocking the ticket that blocks it is two tickets that can
        // never start.
        if ($kind === 'blocks' && $this->links->exists($target, $source, 'blocks')) {
            throw new ValidationError(__('That would make the two block each other.'));
        }

        $this->links->create($source, $target, $kind, $userId);

        $otherName = $other['project_code'] . '-' . $other['number'];
        $thisName = $ticket['project_code'] . '-' . $ticket['number'];

        // Both tickets' histories say so, each from its own end.
        $this->activity->happened($ticket, $userId, 'linked', $offered, null, $otherName);
        $this->activity->happened($other, $userId, 'linked', self::reverse($offered), null, $thisName);
    }

    public function unlink(array $link, int $fromTicketId, ?int $userId): void
    {
        $this->links->delete((int) $link['id']);

        foreach ([(int) $link['source_id'], (int) $link['target_id']] as $id) {
            $ticket = $this->tickets->find($id);
            $otherId = $id === (int) $link['source_id'] ? (int) $link['target_id'] : (int) $link['source_id'];
            $other = $this->tickets->find($otherId);

            if ($ticket !== null && $other !== null) {
                $this->activity->happened($ticket, $userId, 'unlinked', (string) $link['kind'], $other['project_code'] . '-' . $other['number']);
            }
        }
    }

    private static function reverse(string $offered): string
    {
        return match ($offered) {
            'blocks' => 'blocked_by',
            'blocked_by' => 'blocks',
            'duplicates' => 'duplicated_by',
            'duplicated_by' => 'duplicates',
            default => $offered,
        };
    }
}
