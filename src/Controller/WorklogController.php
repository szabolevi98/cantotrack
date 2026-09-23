<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Format;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\WorklogService;

/**
 * Logging time against a ticket, and editing what was logged.
 *
 * Hours belong to whoever worked them. Anyone can see anyone's, and only their
 * own can be changed — with one exception: an administrator can fix somebody
 * else's, because a mistyped entry from a colleague who is on holiday is
 * otherwise wrong until they come back.
 */
class WorklogController extends Controller
{
    /** Logged from the ticket page: the form posts here and comes straight back. */
    public function create(int $ticketId): void
    {
        Auth::requireMember();

        if ((new TicketRepository())->find($ticketId) === null) {
            $this->notFound(__('There is no such ticket.'));
        }

        try {
            $logged = (new WorklogService())->log(
                $ticketId,
                (int) Auth::id(),
                $this->input('time'),
                $this->input('work_date'),
                $this->input('note'),
                $this->input('remaining'),
                $this->billable()
            );
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->back('/tickets/' . $ticketId);
        }

        $this->flash($this->saidBack($logged['minutes'], $this->input('work_date') ?: date('Y-m-d'), $logged['rounded']));
        $this->back('/tickets/' . $ticketId);
    }

    public function update(int $id): void
    {
        Auth::requireMember();

        $worklog = $this->mineOr403($id);

        try {
            $changed = (new WorklogService())->change(
                $worklog,
                $this->input('time'),
                $this->input('work_date'),
                $this->input('note'),
                $this->billable()
            );
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->back('/tickets/' . $worklog['ticket_id']);
        }

        $this->flash($changed['rounded']
            ? __('Worklog updated, and rounded up to {minimum}.', ['minimum' => Format::duration($changed['minutes'])])
            : __('Worklog updated.'));
        $this->back('/tickets/' . $worklog['ticket_id']);
    }

    public function delete(int $id): void
    {
        Auth::requireMember();

        $worklog = $this->mineOr403($id);

        try {
            (new WorklogService())->remove($worklog);
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->back('/tickets/' . $worklog['ticket_id']);
        }

        $this->flash(__('Worklog deleted.'), 'warning');
        $this->back('/tickets/' . $worklog['ticket_id']);
    }

    /**
     * The worklog, if it is the signed-in person's — or theirs to fix as an
     * administrator. Anything else is refused rather than quietly ignored.
     */
    private function mineOr403(int $id): array
    {
        $worklog = (new WorklogRepository())->find($id);

        if ($worklog === null) {
            $this->notFound(__('There is no such worklog.'));
        }

        if (!WorklogService::canChange($worklog, (int) Auth::id(), Auth::isAdmin())) {
            $this->forbidden(__('Those are somebody else’s hours.'));
        }

        return $worklog;
    }

    /**
     * Whether the form said the entry is billed. An unticked box sends
     * nothing at all, so the form sends a marker beside it; without the
     * marker (the API, an old form) the project's default decides.
     */
    private function billable(): ?bool
    {
        return isset($_POST['billable_sent']) ? isset($_POST['billable']) : null;
    }

    private function saidBack(int $minutes, string $date, bool $rounded): string
    {
        $params = ['duration' => Format::duration($minutes), 'day' => Format::day($date)];

        return $rounded
            ? __('{duration} logged on {day} — rounded up, because that is the smallest slice logged here.', $params)
            : __('{duration} logged on {day}.', $params);
    }
}
