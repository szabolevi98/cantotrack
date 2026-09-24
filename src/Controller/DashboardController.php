<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\EventRepository;
use CantoTrack\Model\GadgetRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\Gadgets;

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
            'week' => (Auth::user()['role'] ?? '') !== 'guest' ? $this->week() : null,
            'sprints' => $this->runningSprints(),
            'releases' => $this->comingReleases(),
            'pages' => (new \CantoTrack\Model\PageRepository())->recent(5),
            'starred' => $tickets->favouritesOf((int) Auth::id(), 6),
            'gadgets' => (new Gadgets())->drawn((int) Auth::id()),
            // Budgets past their warning, for whoever looks after them.
            'budgets' => Auth::isAdmin() ? (new \CantoTrack\Service\Budget())->running((new ProjectRepository())->allWithCounts()) : [],
            'looked_at' => (new \CantoTrack\Model\RecentRepository())->latest((int) Auth::id(), 5),
            'gadget_groups' => array_keys(Gadgets::GROUPS),
        ]);
    }

    /** A piece added to one's own dashboard. */
    public function addGadget(): void
    {
        Auth::require();

        try {
            (new Gadgets())->add((int) Auth::id(), $this->input('kind'), $this->input('title'), $this->input('query'), $this->input('group_by'));
            $this->flash(__('Added to your dashboard.'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/#gadgets');
    }

    public function removeGadget(int $id): void
    {
        Auth::require();

        (new GadgetRepository())->delete($id, (int) Auth::id());
        $this->redirect('/#gadgets');
    }

    public function moveGadget(int $id): void
    {
        Auth::require();

        (new GadgetRepository())->move($id, (int) Auth::id(), $this->input('direction') === 'up' ? -1 : 1);
        $this->redirect('/#gadgets');
    }

    /**
     * This week's hours against one's own working week — and how many of
     * this week's working days are behind.
     *
     * @return array{logged: int, expected: int, so_far: int}
     */
    private function week(): array
    {
        $monday = (new \DateTimeImmutable('monday this week'))->format('Y-m-d');
        $sunday = (new \DateTimeImmutable('sunday this week'))->format('Y-m-d');
        $today = date('Y-m-d');
        $days = (new \CantoTrack\Service\Calendar())->days((array) Auth::user(), $monday, $sunday);

        $statement = \CantoTrack\Core\DatabaseConnection::get()->prepare(
            'SELECT COALESCE(SUM(minutes), 0) FROM worklogs WHERE user_id = :user AND work_date BETWEEN :from AND :to'
        );
        $statement->execute(['user' => Auth::id(), 'from' => $monday, 'to' => $sunday]);

        return [
            'logged' => (int) $statement->fetchColumn(),
            'expected' => array_sum(array_column($days, 'expected')),
            'so_far' => array_sum(array_map(static fn(array $d): int => $d['expected'], array_filter($days, static fn(string $date): bool => $date <= $today, ARRAY_FILTER_USE_KEY))),
        ];
    }

    /** @return list<array<string, mixed>> the sprints running in the projects one may see, with their project */
    private function runningSprints(): array
    {
        $out = [];

        foreach ((new ProjectRepository())->allWithCounts() as $project) {
            foreach ((new \CantoTrack\Model\SprintRepository())->forProject((int) $project['id']) as $sprint) {
                if ($sprint['state'] === 'active') {
                    $sprint['project_code'] = $project['code'];
                    $sprint['days_left'] = $sprint['ends_on'] === null ? null : (int) floor((strtotime((string) $sprint['ends_on']) - strtotime('today')) / 86400);
                    $out[] = $sprint;
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the next releases to go out, soonest first */
    private function comingReleases(): array
    {
        $out = [];

        foreach ((new ProjectRepository())->allWithCounts() as $project) {
            foreach ((new \CantoTrack\Model\ReleaseRepository())->unreleased((int) $project['id']) as $release) {
                if ($release['release_on'] !== null) {
                    $out[] = $release;
                }
            }
        }

        usort($out, static fn(array $a, array $b): int => strcmp((string) $a['release_on'], (string) $b['release_on']));

        return array_slice($out, 0, 4);
    }
}
