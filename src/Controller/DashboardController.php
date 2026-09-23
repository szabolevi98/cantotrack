<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Model\EventRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\TicketRepository;

/**
 * The page a signed-in person lands on: their own open tickets first, and what
 * has happened lately around them.
 *
 * Not a summary of everything — a dashboard that shows the whole company's
 * numbers is one people look at once. What belongs here is what somebody needs
 * to answer "what am I doing today", and "what did I miss".
 */
class DashboardController extends Controller
{
    public function index(): void
    {
        Auth::require();

        $tickets = new TicketRepository();
        $mine = $tickets->openFor((int) Auth::id(), 25);

        $this->render('dashboard/index.twig', [
            'mine' => $mine,
            // In progress is the count that matters: several at once is the
            // thing a person can see about their own week and act on.
            'in_progress' => count(array_filter($mine, static fn(array $t): bool => $t['status_category'] === 'in_progress')),
            'overdue' => count(array_filter(
                $mine,
                static fn(array $t): bool => $t['due_on'] !== null && $t['due_on'] < date('Y-m-d')
            )),
            'projects' => (new ProjectRepository())->allWithCounts(),
            'recent' => (new EventRepository())->recent(15),
        ]);
    }
}
