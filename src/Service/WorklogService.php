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

    public function __construct(?PDO $db = null)
    {
        $db ??= DatabaseConnection::get();

        $this->worklogs = new WorklogRepository($db);
        $this->tickets = new TicketRepository($db);
    }

    /**
     * Logs time. Returns the entry's id and the minutes actually stored, which
     * may be more than were typed — see minutes().
     *
     * @return array{id: int, minutes: int, rounded: bool}
     * @throws ValidationError
     */
    public function log(int $ticketId, int $userId, string $time, string $date, ?string $note): array
    {
        if ($this->tickets->find($ticketId) === null) {
            throw new ValidationError(__('There is no such ticket.'));
        }

        [$minutes, $rounded] = $this->minutes($time);
        $day = $this->day($date);

        $id = $this->worklogs->create($ticketId, $userId, $day, $minutes, $this->note($note));

        return ['id' => $id, 'minutes' => $minutes, 'rounded' => $rounded];
    }

    /**
     * @return array{minutes: int, rounded: bool}
     * @throws ValidationError
     */
    public function change(array $worklog, string $time, string $date, ?string $note): array
    {
        [$minutes, $rounded] = $this->minutes($time);
        $day = $this->day($date);

        $this->worklogs->update((int) $worklog['id'], $day, $minutes, $this->note($note));

        return ['minutes' => $minutes, 'rounded' => $rounded];
    }

    public function remove(array $worklog): void
    {
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
