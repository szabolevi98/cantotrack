<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\LinkRepository;
use CantoTrack\Model\SprintRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\LinkService;
use CantoTrack\Service\SprintService;
use CantoTrack\Service\TicketService;

final class SprintServiceTest extends DatabaseTestCase
{
    private int $me;
    private int $project;
    private TicketService $tickets;
    private SprintService $sprints;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person();
        $this->project = $this->project();
        $this->tickets = new TicketService($this->db);
        $this->sprints = new SprintService($this->db);
    }

    public function testStartingWritesDownWhatTheSprintSetOutToDo(): void
    {
        $sprint = $this->sprintWith([3, 5]);

        $this->sprints->start($sprint);
        $started = (new SprintRepository($this->db))->find((int) $sprint['id']);

        self::assertSame('active', $started['state']);
        self::assertSame(8, (int) $started['committed_points']);
        self::assertSame(2, (int) $started['committed_count']);
    }

    public function testOnlyOneSprintRunsAtATime(): void
    {
        $this->sprints->start($this->sprintWith([1]));

        $this->expectException(ValidationError::class);
        $this->sprints->start($this->sprintWith([2]));
    }

    public function testAClosedSprintsBurndownStillCountsTheWorkItHandedOn(): void
    {
        $sprint = $this->sprintWith([3, 5]);
        $this->sprints->start($sprint);
        $repository = new SprintRepository($this->db);
        $ids = array_column($repository->tickets((int) $sprint['id']), 'id');
        $this->tickets->changeStatus((int) $ids[0], 'done');

        $this->sprints->close((array) $repository->find((int) $sprint['id']), null, $this->me);
        $burndown = $this->sprints->burndown((array) $repository->find((int) $sprint['id']));

        // The five points that went back to the backlog were never done in it.
        $last = end($burndown['days']);
        self::assertSame(8, $burndown['total']);
        self::assertSame(5, $last === false ? null : $last['remaining']);
    }

    public function testClosingSendsUnfinishedWorkOn(): void
    {
        $sprint = $this->sprintWith([3, 5]);
        $this->sprints->start($sprint);
        $next = $this->sprintWith([]);
        $ids = array_column((new SprintRepository($this->db))->tickets((int) $sprint['id']), 'id');
        $this->tickets->changeStatus((int) $ids[0], 'done');

        $this->sprints->close((array) (new SprintRepository($this->db))->find((int) $sprint['id']), (int) $next['id'], $this->me);

        $closed = (new SprintRepository($this->db))->find((int) $sprint['id']);
        self::assertSame('closed', $closed['state']);
        self::assertSame(1, (int) $closed['completed_count']);
        self::assertSame((int) $next['id'], (int) (new TicketRepository($this->db))->find((int) $ids[1])['sprint_id']);
    }

    public function testTheBurndownCountsWhatIsLeftEachDay(): void
    {
        $sprint = $this->sprintWith([3, 5], date('Y-m-d', strtotime('-2 days')), date('Y-m-d', strtotime('+2 days')));
        $this->sprints->start($sprint);
        $ids = array_column((new SprintRepository($this->db))->tickets((int) $sprint['id']), 'id');
        $this->tickets->changeStatus((int) $ids[0], 'done');

        $burndown = $this->sprints->burndown((array) (new SprintRepository($this->db))->find((int) $sprint['id']));
        $today = array_values(array_filter($burndown['days'], static fn(array $d): bool => $d['day'] === date('Y-m-d')))[0];

        self::assertSame('points', $burndown['unit']);
        self::assertSame(8, $burndown['total']);
        self::assertSame(8, $burndown['days'][0]['remaining'], 'nothing was done on the first day');
        self::assertSame(5, $today['remaining'], 'the three-point ticket finished today');
        self::assertNull(end($burndown['days'])['remaining'], 'the future is not drawn');
    }

    public function testLinksReadFromBothEndsAndCannotLoop(): void
    {
        $a = $this->tickets->create(['project_id' => $this->project, 'title' => 'A'], $this->me);
        $b = $this->tickets->create(['project_id' => $this->project, 'title' => 'B'], $this->me);
        $links = new LinkService($this->db);

        $links->link($a, 'blocks', 'CT-2', $this->me);

        $fromB = (new LinkRepository($this->db))->forTicket($b);
        self::assertSame('in', $fromB[0]['direction']);
        self::assertSame(1, (int) (new TicketRepository($this->db))->find($b)['blocked_by']);

        $this->expectException(ValidationError::class);
        $links->link($a, 'blocked_by', 'CT-2', $this->me);
    }

    /** A planned sprint holding tickets of these point sizes. */
    private function sprintWith(array $points, ?string $start = null, ?string $end = null): array
    {
        $id = $this->sprints->create($this->project, '', '', $start ?? date('Y-m-d'), $end ?? '');
        $ticketIds = [];

        foreach ($points as $size) {
            $ticketIds[] = $this->tickets->create(['project_id' => $this->project, 'title' => 'Sized ' . $size, 'story_points' => (string) $size], $this->me);
        }

        $this->sprints->assign($ticketIds, $id, $this->me);

        return (array) (new SprintRepository($this->db))->find($id);
    }
}
