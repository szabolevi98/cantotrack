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
 * The page a signed-in person lands on, laid out the way they want it: what
 * they are doing today, what they missed, and the pieces of their own they
 * put there (see Gadgets).
 *
 * Each piece's figures are read only when the piece is on the page — a
 * dashboard without the budgets does not add up the budgets.
 */
class DashboardController extends Controller
{
    public function index(): void
    {
        Auth::require();

        $userId = (int) Auth::id();
        $gadgets = new Gadgets();
        $gadgets->layOut($userId);

        $pieces = $gadgets->drawn($userId);
        $has = array_flip(array_map(static fn(array $g): string => (string) $g['kind'], $pieces));
        $tickets = new TicketRepository();
        $isGuest = (Auth::user()['role'] ?? '') === 'guest';

        // The person's own open tickets feed the numbers as well as the list.
        $mine = isset($has['mine']) || isset($has['numbers']) ? $tickets->openFor($userId, 25) : [];

        $data = [
            'mine' => $mine,
            // In progress is the count that matters: several at once is the
            // thing a person can see about their own week and act on.
            'in_progress' => count(array_filter($mine, static fn(array $t): bool => $t['status_category'] === 'in_progress')),
            'overdue' => count(array_filter($mine, static fn(array $t): bool => $t['due_on'] !== null && $t['due_on'] < date('Y-m-d'))),
            'projects' => (new ProjectRepository())->allWithCounts(),
            'recent' => isset($has['activity']) ? (new EventRepository())->recent(15) : [],
            'week' => isset($has['week']) && !$isGuest ? $this->week() : null,
            'sprints' => isset($has['sprints']) ? $this->runningSprints() : [],
            'releases' => isset($has['releases']) ? $this->comingReleases() : [],
            'pages' => isset($has['pages']) ? (new \CantoTrack\Model\PageRepository())->recent(5) : [],
            'starred' => isset($has['starred']) ? $tickets->favouritesOf($userId, 6) : [],
            // Budgets past their warning, for whoever looks after them.
            'budgets' => isset($has['budgets']) && Auth::isAdmin() ? (new \CantoTrack\Service\Budget())->running((new ProjectRepository())->allWithCounts()) : [],
            'looked_at' => isset($has['looked_at']) ? (new \CantoTrack\Model\RecentRepository())->latest($userId, 5) : [],
        ];

        $this->render('dashboard/index.twig', $data + [
            'main' => array_values(array_filter($pieces, static fn(array $g): bool => $g['area'] === 'main')),
            'side' => array_values(array_filter($pieces, static fn(array $g): bool => $g['area'] === 'side')),
            'editing' => ($_GET['edit'] ?? '') === '1',
            'hidden_builtins' => array_values(array_filter(array_keys(Gadgets::BUILTIN), static fn(string $k): bool => !isset($has[$k]))),
            'examples' => Gadgets::examples(),
            'gadget_groups' => array_keys(Gadgets::GROUPS),
        ]);
    }

    /** A piece of one's own, added to the dashboard. */
    public function addGadget(): void
    {
        Auth::require();

        try {
            (new Gadgets())->add((int) Auth::id(), $this->input('kind'), $this->input('title'), $this->input('query'), $this->input('group_by'), $this->input('area', 'main'));
            $this->flash(__('Added to your dashboard.'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/?edit=1');
    }

    /** A piece of one's own, changed where it is. */
    public function updateGadget(int $id): void
    {
        Auth::require();

        try {
            (new Gadgets())->update((int) Auth::id(), $id, $this->input('kind'), $this->input('title'), $this->input('query'), $this->input('group_by'));
            $this->flash(__('Saved.'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/?edit=1#gadget-' . $id);
    }

    /** One of the pieces every dashboard starts with, put back. */
    public function addBuiltin(): void
    {
        Auth::require();

        try {
            $id = (new Gadgets())->addBuiltin((int) Auth::id(), $this->input('kind'));
            $this->redirect('/?edit=1#gadget-' . $id);
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->redirect('/?edit=1');
        }
    }

    public function removeGadget(int $id): void
    {
        Auth::require();

        (new GadgetRepository())->delete($id, (int) Auth::id());
        $this->redirect('/?edit=1');
    }

    /** A step up or down, or over to the other column — without dragging. */
    public function moveGadget(int $id): void
    {
        Auth::require();

        $direction = in_array($this->input('direction'), ['up', 'down', 'other'], true) ? $this->input('direction') : 'down';
        (new GadgetRepository())->move($id, (int) Auth::id(), $direction);
        $this->redirect('/?edit=1#gadget-' . $id);
    }

    /** The layout as it was dragged, sent by the page's script. */
    public function arrange(): void
    {
        Auth::require();

        $body = json_decode((string) file_get_contents('php://input'), true);
        $ids = static fn(mixed $list): array => array_values(array_map('intval', array_filter(is_array($list) ? $list : [], 'is_numeric')));

        (new GadgetRepository())->arrange((int) Auth::id(), $ids($body['main'] ?? []), $ids($body['side'] ?? []));
        $this->json(['ok' => true]);
    }

    /** Back to how a dashboard starts; one's own pieces are kept. */
    public function reset(): void
    {
        Auth::require();

        (new Gadgets())->reset((int) Auth::id());
        $this->flash(__('Your dashboard is back as it starts. Your own pieces are still on it.'));
        $this->redirect('/?edit=1');
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
