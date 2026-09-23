<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Format;
use CantoTrack\Core\ValidationError;
use CantoTrack\Service\TimerService;

/** Starting and stopping the clock. */
class TimerController extends Controller
{
    public function start(int $ticketId): void
    {
        Auth::requireMember();

        try {
            $logged = (new TimerService())->start((int) Auth::id(), $ticketId);

            if ($logged !== null) {
                $this->flash(__('The clock that was running logged {duration}; it runs on this ticket now.', [
                    'duration' => Format::duration($logged['minutes']),
                ]));
            }
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->back('/tickets/' . $ticketId);
    }

    public function stop(): void
    {
        Auth::requireMember();

        try {
            $logged = (new TimerService())->stop((int) Auth::id(), $this->input('note'));

            if ($logged === null) {
                $this->flash(__('Under a minute — nothing was logged.'), 'warning');
                $this->back('/');
            }

            $this->flash(__('{duration} logged from the clock.', ['duration' => Format::duration($logged['minutes'])]));
            $this->back('/tickets/' . $logged['ticket_id']);
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->back('/');
        }
    }

    public function discard(): void
    {
        Auth::requireMember();

        (new TimerService())->discard((int) Auth::id());

        $this->flash(__('The clock is stopped, and nothing was logged.'), 'warning');
        $this->back('/');
    }
}
