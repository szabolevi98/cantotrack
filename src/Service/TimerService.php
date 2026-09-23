<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\TicketRepository;
use PDO;

/**
 * A clock that runs while somebody works on a ticket, and becomes a worklog
 * when it stops.
 *
 * One per person: starting it on another ticket stops the one that was
 * running and logs it first, because nobody works on two tickets at once and
 * a forgotten clock is the usual way a day gets logged twice.
 */
class TimerService
{
    private PDO $db;
    private TicketRepository $tickets;
    private WorklogService $worklogs;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
        $this->tickets = new TicketRepository($this->db);
        $this->worklogs = new WorklogService($this->db);
    }

    /** The running clock of a person, with the ticket it runs on — or null. */
    public function running(int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT tm.*, t.number AS ticket_number, t.title AS ticket_title, p.code AS project_code
             FROM timers tm JOIN tickets t ON t.id = tm.ticket_id JOIN projects p ON p.id = t.project_id
             WHERE tm.user_id = :user'
        );
        $statement->execute(['user' => $userId]);

        return $statement->fetch() ?: null;
    }

    /**
     * Starts the clock on a ticket. Returns what the clock that was running
     * before logged, if one was.
     *
     * @return array{minutes: int, ticket_id: int}|null
     * @throws ValidationError
     */
    public function start(int $userId, int $ticketId): ?array
    {
        if ($this->tickets->find($ticketId) === null) {
            throw new ValidationError(__('There is no such ticket.'));
        }

        $previous = $this->running($userId);
        $logged = null;

        if ($previous !== null) {
            if ((int) $previous['ticket_id'] === $ticketId) {
                return null;
            }

            $logged = $this->stop($userId, null);
        }

        $this->db->prepare('INSERT INTO timers (user_id, ticket_id, started_at) VALUES (:user, :ticket, NOW())')
            ->execute(['user' => $userId, 'ticket' => $ticketId]);

        return $logged;
    }

    /**
     * Stops the clock and logs the time on the day it started. Under a minute
     * is thrown away rather than logged as the minimum — that was a click, not
     * work. More than a day is cut at a day: a clock left running over the
     * weekend is not three days of work.
     *
     * @return array{minutes: int, ticket_id: int}|null what was logged
     * @throws ValidationError
     */
    public function stop(int $userId, ?string $note): ?array
    {
        $timer = $this->running($userId);

        if ($timer === null) {
            throw new ValidationError(__('No clock is running.'));
        }

        $this->discard($userId);

        $started = new \DateTimeImmutable((string) $timer['started_at']);
        $seconds = time() - $started->getTimestamp();
        $minutes = min(WorklogService::MAX_MINUTES, (int) ceil($seconds / 60));

        if ($seconds < 60) {
            return null;
        }

        $logged = $this->worklogs->log((int) $timer['ticket_id'], $userId, $minutes . 'm', $started->format('Y-m-d'), $note);

        return ['minutes' => $logged['minutes'], 'ticket_id' => (int) $timer['ticket_id']];
    }

    public function discard(int $userId): void
    {
        $this->db->prepare('DELETE FROM timers WHERE user_id = :user')->execute(['user' => $userId]);
    }
}
