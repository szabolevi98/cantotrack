<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\BoardRepository;
use CantoTrack\Model\SprintRepository;
use CantoTrack\Model\StatusRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\BoardColumns;
use CantoTrack\Service\BoardService;
use CantoTrack\Service\SprintService;
use CantoTrack\Service\TicketService;

/** Shared boards: several projects planned in one sprint — see the 0047 migration. */
final class BoardTest extends DatabaseTestCase
{
    private int $me;
    private int $shop;
    private int $sync;
    private BoardService $boards;
    private SprintService $sprints;
    private TicketService $tickets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person();
        $this->shop = $this->project('PTS');
        $this->sync = $this->project('OIL');
        $this->boards = new BoardService($this->db);
        $this->sprints = new SprintService($this->db);
        $this->tickets = new TicketService($this->db);
    }

    public function testAProjectComesWithABoardOfItsOwnHoldingOnlyIt(): void
    {
        $board = (new BoardRepository($this->db))->ownOf($this->shop);

        self::assertSame($this->shop, (int) $board['project_id']);
        self::assertSame([$this->shop], (new BoardRepository($this->db))->projectIds((int) $board['id']));
    }

    public function testASharedBoardsSprintHoldsTicketsOfItsProjectsAndNoOthers(): void
    {
        $other = $this->project('CC2');
        $board = $this->sharedBoard();
        $sprint = $this->sprints->create($board, '', '', '', '');

        $a = $this->ticket($this->shop);
        $b = $this->ticket($this->sync);
        $c = $this->ticket($other);
        $this->sprints->assign([$a, $b, $c], $sprint, $this->me);

        $in = array_map('intval', array_column((new SprintRepository($this->db))->tickets($sprint), 'id'));
        sort($in);

        self::assertSame([$a, $b], $in);
        self::assertNull((new TicketRepository($this->db))->find($c)['sprint_id']);
    }

    public function testASprintIsNamedAfterItsBoard(): void
    {
        $sprint = (new SprintRepository($this->db))->find($this->sprints->create($this->sharedBoard(), '', '', '', ''));
        $own = (new SprintRepository($this->db))->find($this->sprints->create($this->ownBoard($this->shop), '', '', '', ''));

        self::assertSame('Development Sprint 1', $sprint['name']);
        self::assertSame('PTS Sprint 1', $own['name']);
    }

    public function testAProjectsOwnBoardAndASharedOneEachRunASprint(): void
    {
        $shared = $this->sprints->create($this->sharedBoard(), '', '', '', '');
        $own = $this->sprints->create($this->ownBoard($this->shop), '', '', '', '');
        $this->sprints->assign([$this->ticket($this->shop)], $shared, $this->me);
        $this->sprints->assign([$this->ticket($this->shop)], $own, $this->me);

        $repository = new SprintRepository($this->db);
        $this->sprints->start((array) $repository->find($shared));
        $this->sprints->start((array) $repository->find($own));

        self::assertCount(2, $repository->activeForProject($this->shop));
    }

    public function testABoardRunsOneSprintAtATime(): void
    {
        $board = $this->sharedBoard();
        $repository = new SprintRepository($this->db);
        $first = $this->sprints->create($board, '', '', '', '');
        $second = $this->sprints->create($board, '', '', '', '');
        $this->sprints->assign([$this->ticket($this->shop)], $first, $this->me);
        $this->sprints->assign([$this->ticket($this->sync)], $second, $this->me);

        $this->sprints->start((array) $repository->find($first));

        $this->expectException(ValidationError::class);
        $this->sprints->start((array) $repository->find($second));
    }

    public function testUnfinishedWorkGoesOnOnlyToASprintOfTheSameBoard(): void
    {
        $repository = new SprintRepository($this->db);
        $running = $this->sprints->create($this->sharedBoard(), '', '', '', '');
        $elsewhere = $this->sprints->create($this->ownBoard($this->shop), '', '', '', '');
        $this->sprints->assign([$this->ticket($this->shop)], $running, $this->me);
        $this->sprints->start((array) $repository->find($running));

        $this->expectException(ValidationError::class);
        $this->sprints->close((array) $repository->find($running), $elsewhere, $this->me);
    }

    public function testTheBacklogHoldsBothProjectsAndSaysWhatAnotherBoardHasPlanned(): void
    {
        $board = (array) (new BoardRepository($this->db))->find($this->sharedBoard());
        $waiting = [$this->ticket($this->shop), $this->ticket($this->sync)];
        $taken = $this->ticket($this->shop);
        $own = $this->sprints->create($this->ownBoard($this->shop), '', '', '', '');
        $this->sprints->assign([$taken], $own, $this->me);

        $scope = $this->boards->scope($board);
        $tickets = new TicketRepository($this->db);
        $backlog = array_map('intval', array_column($tickets->planning((int) $board['id'], $scope)[0], 'id'));
        sort($backlog);

        self::assertSame($waiting, $backlog);
        self::assertSame(1, $tickets->plannedElsewhere((int) $board['id'], $scope)[0]['count']);
    }

    public function testTheBoardsQueryNarrowsItsBacklog(): void
    {
        $id = $this->boards->create('Bugs', [$this->shop, $this->sync], 'type = bug');
        $bug = $this->tickets->create(['project_id' => $this->shop, 'title' => 'Broken', 'type' => 'bug'], $this->me);
        $this->ticket($this->sync);

        $board = (array) (new BoardRepository($this->db))->find($id);
        $backlog = (new TicketRepository($this->db))->planning($id, $this->boards->scope($board))[0];

        self::assertSame([$bug], array_map('intval', array_column($backlog, 'id')));
    }

    public function testAQueryThatDoesNotReadIsSaidAtOnce(): void
    {
        $this->expectException(ValidationError::class);
        $this->boards->create('Broken', [$this->shop], 'type = = bug');
    }

    public function testAProjectTakenOffTheBoardTakesItsTicketsOutOfItsOpenSprints(): void
    {
        $id = $this->sharedBoard();
        $sprint = $this->sprints->create($id, '', '', '', '');
        $ticket = $this->ticket($this->sync);
        $this->sprints->assign([$ticket], $sprint, $this->me);

        $this->boards->update((array) (new BoardRepository($this->db))->find($id), 'Development', [$this->shop], '');

        self::assertNull((new TicketRepository($this->db))->find($ticket)['sprint_id']);
    }

    public function testColumnsOfTheSameNameAreOneAndANewNameComesAfterTheOneBeforeIt(): void
    {
        $statuses = new StatusRepository($this->db);
        $statuses->create($this->sync, 'Elakadt', 'in_progress', 'red', null);
        $statuses->move($this->statusNamed($this->sync, 'Elakadt'), -1);
        $statuses->move($this->statusNamed($this->sync, 'Elakadt'), -1);

        $worked = $this->boards->columns((array) (new BoardRepository($this->db))->find($this->sharedBoard()));
        $names = array_column($worked['columns'], 'name');

        self::assertSame(['Backlog', 'To do', 'In progress', 'Elakadt', 'Review', 'Done'], $names);
        self::assertCount(2, $worked['columns'][2]['status_ids']);
        self::assertTrue($worked['columns'][5]['done']);
    }

    public function testAStatusNotPutAnywhereGoesByItsNameAndThenByItsKind(): void
    {
        $statuses = new StatusRepository($this->db);
        $statuses->create($this->sync, 'Waiting on client', 'in_progress', 'amber', null);
        $board = (array) (new BoardRepository($this->db))->find($this->sharedBoard());
        $this->boards->saveColumns($board, "TO DO\nIN PROGRESS\nKÉSZ", [
            $this->statusNamed($this->shop, 'Backlog') => 'TO DO',
            $this->statusNamed($this->shop, 'Done') => 'KÉSZ',
        ]);

        $of = $this->boards->columns($board)['of'];
        $column = static fn(int $statusId): string => $of[$statusId];
        $byName = array_column($this->boards->columns($board)['columns'], 'name', 'id');

        self::assertSame('TO DO', $byName[$column($this->statusNamed($this->shop, 'Backlog'))]);
        self::assertSame('IN PROGRESS', $byName[$column($this->statusNamed($this->shop, 'In progress'))]);
        self::assertSame('IN PROGRESS', $byName[$column($this->statusNamed($this->sync, 'Waiting on client'))]);
        self::assertSame('KÉSZ', $byName[$column($this->statusNamed($this->sync, 'Done'))]);
    }

    public function testACardDroppedInAColumnGoesToItsOwnProjectsStatusThere(): void
    {
        $board = (array) (new BoardRepository($this->db))->find($this->sharedBoard());
        $ticket = (array) (new TicketRepository($this->db))->find($this->ticket($this->sync));
        $column = BoardColumns::plain('Review');

        $drop = $this->boards->dropInto($board, 'n-' . $column, $ticket);
        $this->tickets->move((int) $ticket['id'], (string) $drop['status']['id'], null, null, [], $this->me, $drop['column']);

        self::assertSame($this->statusNamed($this->sync, 'Review'), (int) (new TicketRepository($this->db))->find((int) $ticket['id'])['status_id']);
    }

    public function testTheBoardDrawsBothProjectsInItsColumns(): void
    {
        $board = (array) (new BoardRepository($this->db))->find($this->sharedBoard());
        $a = $this->ticket($this->shop);
        $b = $this->ticket($this->sync);

        $worked = $this->boards->columns($board);
        $drawn = (new TicketRepository($this->db))->boardColumns($worked['columns'], $this->boards->scope($board));
        $first = array_map('intval', array_column($drawn['columns'][$worked['columns'][0]['id']], 'id'));
        sort($first);

        self::assertSame([$a, $b], $first);
    }

    private function sharedBoard(): int
    {
        return $this->boards->create('Development', [$this->shop, $this->sync], '');
    }

    private function ticket(int $projectId): int
    {
        return $this->tickets->create(['project_id' => $projectId, 'title' => 'Something to do'], $this->me);
    }

    private function statusNamed(int $projectId, string $name): int
    {
        foreach ((new StatusRepository($this->db))->forProject($projectId) as $status) {
            if ($status['name'] === $name) {
                return (int) $status['id'];
            }
        }

        self::fail('No status called ' . $name);
    }
}
