<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Config;
use CantoTrack\Core\Format;
use CantoTrack\Core\Session;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\WorklogRepository;

/**
 * Logging time against a ticket, and editing what was logged.
 *
 * Hours belong to whoever worked them. Anyone can see anyone's, and only their
 * own can be changed — with one exception: an administrator can fix somebody
 * else's, because a mistyped entry from a colleague who is on holiday is
 * otherwise wrong until they come back.
 */
class WorklogController
{
    /** Logged from the ticket page: the form posts here and comes straight back. */
    public function create(int $ticketId): void
    {
        Auth::require();

        $ticket = (new TicketRepository())->find($ticketId);

        if ($ticket === null) {
            http_response_code(404);
            exit('There is no such ticket.');
        }

        $minutes = $this->minutesFromForm();
        $date = $this->dateFromForm();

        $error = match (true) {
            $minutes === false => 'The time should read like "2h", "45m", "1h 30m" or "1:30".',
            $minutes !== null && $minutes <= 0 => 'Log something longer than nothing.',
            $minutes === null => 'How long did it take?',
            // A day's worth in one entry is normal; a week's worth is a typo,
            // and "480" meaning minutes rather than hours is how it happens.
            $minutes > 24 * 60 => 'That is more than a day. Log it as several entries.',
            $date === null => 'That date does not look like a date.',
            $date > date('Y-m-d') => 'Time cannot be logged against a day that has not happened.',
            default => null,
        };

        if ($error !== null) {
            Session::flash($error, 'danger');
            $this->redirect('/tickets/' . $ticketId);
        }

        (new WorklogRepository())->create(
            $ticketId,
            (int) Auth::id(),
            (string) $date,
            (int) $minutes,
            (string) ($_POST['note'] ?? '')
        );

        Session::flash(Format::duration((int) $minutes) . ' logged on ' . Format::day((string) $date) . '.');
        $this->redirect('/tickets/' . $ticketId);
    }

    public function update(int $id): void
    {
        Auth::require();

        $worklog = $this->mineOr403($id);
        $minutes = $this->minutesFromForm();
        $date = $this->dateFromForm();

        if ($minutes === false || $minutes === null || $minutes <= 0 || $minutes > 24 * 60 || $date === null) {
            Session::flash('That did not look like a time and a date.', 'danger');
            $this->redirect('/tickets/' . $worklog['ticket_id']);
        }

        (new WorklogRepository())->update($id, (string) $date, (int) $minutes, (string) ($_POST['note'] ?? ''));

        Session::flash('Worklog updated.');
        $this->redirect($this->backTo((string) ($_POST['back'] ?? ''), '/tickets/' . $worklog['ticket_id']));
    }

    public function delete(int $id): void
    {
        Auth::require();

        $worklog = $this->mineOr403($id);
        (new WorklogRepository())->delete($id);

        Session::flash('Worklog deleted.', 'warning');
        $this->redirect($this->backTo((string) ($_POST['back'] ?? ''), '/tickets/' . $worklog['ticket_id']));
    }

    /**
     * The worklog, if it is the signed-in person's — or theirs to fix as an
     * administrator. Anything else is refused rather than quietly ignored.
     */
    private function mineOr403(int $id): array
    {
        $worklog = (new WorklogRepository())->find($id);

        if ($worklog === null) {
            http_response_code(404);
            exit('There is no such worklog.');
        }

        if ((int) $worklog['user_id'] !== (int) Auth::id() && !Auth::isAdmin()) {
            http_response_code(403);
            exit('Those are somebody else\'s hours.');
        }

        return $worklog;
    }

    /** What was typed into the time box, in minutes. False when it is unreadable. */
    private function minutesFromForm(): int|false|null
    {
        $given = trim((string) ($_POST['time'] ?? ''));

        if ($given === '') {
            return null;
        }

        return Format::parseDuration($given) ?? false;
    }

    /** The day, defaulting to today — which is what it is nine times in ten. */
    private function dateFromForm(): ?string
    {
        $given = trim((string) ($_POST['work_date'] ?? ''));

        if ($given === '') {
            return date('Y-m-d');
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $given);

        // createFromFormat accepts "2026-02-31" and rolls it into March, so the
        // result is compared back against what was typed.
        return ($date !== false && $date->format('Y-m-d') === $given) ? $given : null;
    }

    private function backTo(string $given, string $fallback): string
    {
        return str_starts_with($given, '/') && !str_starts_with($given, '//') ? $given : $fallback;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim((string) Config::get('app.base_url'), '/') . $path);
        exit;
    }
}
