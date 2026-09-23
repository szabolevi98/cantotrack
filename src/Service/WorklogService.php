<?php

namespace CantoTrack\Service;

use CantoTrack\Core\Config;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Format;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\WorklogRepository;
use PDO;

/**
 * The rules about hours: how long an entry may be, which days it may be on,
 * and whose it is to change.
 *
 * The ticket page, the timesheet, the weekly grid, the timer and the API all
 * log time, and they all come through here — a rule that lives in one form's
 * controller is a rule the other four ways around it do not know about.
 */
class WorklogService
{
    /** The longest single entry: a day's worth is normal, a week's is a typo. */
    public const MAX_MINUTES = 24 * 60;

    private WorklogRepository $worklogs;
    private TicketRepository $tickets;
    private Activity $activity;
    private Calendar $calendar;

    public function __construct(?PDO $db = null)
    {
        $db ??= DatabaseConnection::get();

        $this->worklogs = new WorklogRepository($db);
        $this->tickets = new TicketRepository($db);
        $this->activity = new Activity($db);
        $this->calendar = new Calendar($db);
    }

    /**
     * Logs time. Returns the entry's id and the minutes actually stored, which
     * may be more than were typed — see minutes().
     *
     * @return array{id: int, minutes: int, rounded: bool}
     * @throws ValidationError
     */
    public function log(
        int $ticketId,
        int $userId,
        string $time,
        string $date,
        ?string $note,
        string $remaining = '',
        ?bool $billable = null,
        string $start = ''
    ): array {
        $ticket = $this->tickets->find($ticketId);

        if ($ticket === null) {
            throw new ValidationError(__('There is no such ticket.'));
        }

        [$minutes, $rounded] = $this->minutes($time);
        $day = $this->day($date);
        $this->calendar->ensureOpen($userId, $day);
        $left = $this->remainingAfter($ticket, $minutes, $remaining);

        // Unless the entry says, it is what its project's hours usually are.
        $billable ??= (int) ($ticket['project_billable'] ?? 1) === 1;

        $id = $this->worklogs->create($ticketId, $userId, $day, $minutes, $this->note($note), $billable, $this->start($start, $minutes));

        if ($left !== false) {
            $this->tickets->setRemaining($ticketId, $left);
        }

        $this->activity->happened($ticket, $userId, 'logged', 'time', $day, Format::duration($minutes));

        return ['id' => $id, 'minutes' => $minutes, 'rounded' => $rounded];
    }

    /**
     * What is left on the ticket once this time is logged.
     *
     * Said out loud ("2h" in the remaining field): that. Left empty: what was
     * left before, less this — the work goes down by the time put in, until
     * somebody knows better. A ticket that never had an estimate has nothing
     * to go down from, and stays without one (false: leave it alone).
     *
     * @throws ValidationError
     */
    private function remainingAfter(array $ticket, int $minutes, string $given): int|false
    {
        $given = trim($given);

        if ($given !== '') {
            $parsed = Format::parseDuration($given);

            if ($parsed === null) {
                throw new ValidationError(__('What is left should read like "3h" or "1d 2h".'));
            }

            return $parsed;
        }

        $before = TicketRepository::remaining($ticket);

        return $before === null ? false : max(0, $before - $minutes);
    }

    /**
     * @return array{minutes: int, rounded: bool}
     * @throws ValidationError
     */
    public function change(array $worklog, string $time, string $date, ?string $note, ?bool $billable = null, ?string $start = null): array
    {
        [$minutes, $rounded] = $this->minutes($time);
        $day = $this->day($date);

        // Both ends of a move: out of a closed day, and into one.
        $this->calendar->ensureOpen((int) $worklog['user_id'], (string) $worklog['work_date']);
        $this->calendar->ensureOpen((int) $worklog['user_id'], $day);

        $this->worklogs->update((int) $worklog['id'], $day, $minutes, $this->note($note));

        // Null: the form said nothing about it (the grid, the API without
        // it), and the start stays as it was.
        if ($start !== null) {
            $this->worklogs->setStart((int) $worklog['id'], $this->start($start, $minutes));
        }

        if ($billable !== null) {
            $this->worklogs->setBillable((int) $worklog['id'], $billable);
        }

        return ['minutes' => $minutes, 'rounded' => $rounded];
    }

    /**
     * When in the day an entry started, as HH:MM:00 — or null for none. It
     * has to end on the same day: an entry is one day's work.
     *
     * @throws ValidationError
     */
    public function start(string $given, int $minutes): ?string
    {
        $given = trim($given);

        if ($given === '') {
            return null;
        }

        if (preg_match('/^([01]?\d|2[0-3])[:.]([0-5]\d)$/', $given, $m) !== 1) {
            throw new ValidationError(__('A start is a time of day, like 9:30.'));
        }

        $from = (int) $m[1] * 60 + (int) $m[2];

        if ($from + $minutes > self::MAX_MINUTES) {
            throw new ValidationError(__('Started then, it would run past midnight. An entry is one day’s work.'));
        }

        return sprintf('%02d:%02d:00', intdiv($from, 60), $from % 60);
    }

    /** @throws ValidationError when its day is closed */
    public function remove(array $worklog): void
    {
        $this->calendar->ensureOpen((int) $worklog['user_id'], (string) $worklog['work_date']);

        $this->worklogs->delete((int) $worklog['id']);
    }

    /** Whether a person may change an entry: their own, or anybody's as an administrator. */
    public static function canChange(array $worklog, int $userId, bool $isAdmin): bool
    {
        return $isAdmin || (int) $worklog['user_id'] === $userId;
    }

    /**
     * What was typed, in minutes, and whether it had to be rounded up.
     *
     * `work.minimum_minutes` is the smallest slice anyone logs: five minutes
     * typed is stored as the minimum, and the person is told so rather than
     * finding out at the end of the month.
     *
     * @return array{0: int, 1: bool}
     * @throws ValidationError
     */
    public function minutes(string $time): array
    {
        $time = trim($time);

        if ($time === '') {
            throw new ValidationError(__('How long did it take?'));
        }

        $minutes = Format::parseDuration($time);

        if ($minutes === null) {
            throw new ValidationError(__('The time should read like "2h", "45m", "1h 30m" or "1:30".'));
        }

        if ($minutes <= 0) {
            throw new ValidationError(__('Log something longer than nothing.'));
        }

        // "480" meaning minutes rather than hours is how a week lands in one
        // entry.
        if ($minutes > self::MAX_MINUTES) {
            throw new ValidationError(__('That is more than a day. Log it as several entries.'));
        }

        $minimum = max(1, Config::int('work.minimum_minutes', 15));

        return $minutes < $minimum ? [$minimum, true] : [$minutes, false];
    }

    /**
     * The day the work was done: a real date, and not one that has yet to
     * happen. Empty means today, which it is nine times in ten.
     *
     * @throws ValidationError
     */
    public function day(string $given): string
    {
        $given = trim($given);

        if ($given === '') {
            return date('Y-m-d');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $given);

        // createFromFormat accepts "2026-02-31" and rolls it into March, so the
        // result is compared back against what was typed.
        if ($date === false || $date->format('Y-m-d') !== $given) {
            throw new ValidationError(__('That date does not look like a date.'));
        }

        if ($given > date('Y-m-d')) {
            throw new ValidationError(__('Time cannot be logged against a day that has not happened.'));
        }

        return $given;
    }

    private function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 500);
    }
}
