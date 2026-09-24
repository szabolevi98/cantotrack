<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\StatusRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\TicketQuery;
use CantoTrack\Service\TicketService;

final class WorkflowTest extends DatabaseTestCase
{
    /** @return array<string, int> the project's columns by name */
    private function columns(int $project): array
    {
        $ids = [];
        foreach ((new StatusRepository($this->db))->forProject($project) as $status) {
            $ids[(string) $status['name']] = (int) $status['id'];
        }

        return $ids;
    }

    public function testEveryMoveIsAllowedUntilAColumnIsLimited(): void
    {
        $me = $this->person();
        $project = $this->project();
        $service = new TicketService($this->db);
        $id = $service->create(['project_id' => $project, 'title' => 'x', 'status' => 'Backlog'], $me);

        $service->changeStatus($id, 'Done', $me);

        self::assertSame('Done', (new TicketRepository($this->db))->find($id)['status_name']);
        self::assertNull((new StatusRepository($this->db))->forProject($project)[0]['moves']);
    }

    public function testALimitedColumnLetsItsTicketsGoOnlyWhereItSays(): void
    {
        $me = $this->person();
        $project = $this->project();
        $c = $this->columns($project);
        $statuses = new StatusRepository($this->db);
        $every = array_values($c);
        $moves = array_fill_keys($every, $every);
        $moves[$c['In progress']] = [$c['Review'], $c['To do']];
        $statuses->setMoves($project, $moves);

        $service = new TicketService($this->db);
        $id = $service->create(['project_id' => $project, 'title' => 'x', 'status' => 'In progress'], $me);

        try {
            $service->changeStatus($id, 'Done', $me);
            self::fail('In progress may not go straight to Done');
        } catch (ValidationError $e) {
            self::assertStringContainsString('workflow', $e->getMessage());
        }

        $service->changeStatus($id, 'Review', $me);
        $service->changeStatus($id, 'Done', $me);
        self::assertSame('Done', (new TicketRepository($this->db))->find($id)['status_name']);

        // The other columns stayed open to everything.
        self::assertNull($statuses->find($c['Review'])['moves']);
        self::assertEqualsCanonicalizing([$c['Review'], $c['To do']], $statuses->find($c['In progress'])['moves']);
    }

    public function testARuleOrACommitIsNotHeldToTheMoves(): void
    {
        $me = $this->person();
        $project = $this->project();
        $c = $this->columns($project);
        $moves = array_fill_keys(array_values($c), array_values($c));
        $moves[$c['To do']] = [];
        (new StatusRepository($this->db))->setMoves($project, $moves);

        $service = new TicketService($this->db);
        $id = $service->create(['project_id' => $project, 'title' => 'x', 'status' => 'To do'], $me);

        $service->changeStatus($id, 'Done', $me, null, true);

        self::assertSame('Done', (new TicketRepository($this->db))->find($id)['status_name']);
    }

    public function testADraggedCardTheWorkflowRefusesChangesNothing(): void
    {
        $me = $this->person();
        $other = $this->person('Bence Tóth');
        $project = $this->project();
        $c = $this->columns($project);
        $moves = array_fill_keys(array_values($c), array_values($c));
        $moves[$c['Backlog']] = [$c['To do']];
        (new StatusRepository($this->db))->setMoves($project, $moves);

        $service = new TicketService($this->db);
        $id = $service->create(['project_id' => $project, 'title' => 'x', 'status' => 'Backlog'], $me);

        try {
            $service->move($id, (string) $c['Done'], null, null, ['assignee_id' => $other], $me);
            self::fail('Backlog may only go to To do');
        } catch (ValidationError) {
        }

        $ticket = (new TicketRepository($this->db))->find($id);
        self::assertSame('Backlog', $ticket['status_name']);
        self::assertNull($ticket['assignee_id'], 'the lane did not change it either');
    }

    public function testAllowingEveryMoveUnlimitsTheColumn(): void
    {
        $project = $this->project();
        $c = $this->columns($project);
        $statuses = new StatusRepository($this->db);
        $moves = array_fill_keys(array_values($c), array_values($c));
        $moves[$c['Done']] = [$c['To do']];
        $statuses->setMoves($project, $moves);
        self::assertNotNull($statuses->find($c['Done'])['moves']);

        $statuses->setMoves($project, array_fill_keys(array_values($c), array_values($c)));
        self::assertNull($statuses->find($c['Done'])['moves']);
    }

    public function testAFinishedTicketHasAResolutionAndAReopenedOneHasNone(): void
    {
        $me = $this->person();
        $project = $this->project();
        $service = new TicketService($this->db);
        $tickets = new TicketRepository($this->db);
        $id = $service->create(['project_id' => $project, 'title' => 'x'], $me);

        self::assertNull($tickets->find($id)['resolution']);

        $service->changeStatus($id, 'Done', $me, "Won't do");
        self::assertSame('wont_do', $tickets->find($id)['resolution']);

        $service->resolve($id, 'duplicate', $me);
        self::assertSame('duplicate', $tickets->find($id)['resolution']);

        $service->changeStatus($id, 'To do', $me);
        self::assertNull($tickets->find($id)['resolution']);

        $service->changeStatus($id, 'Done', $me);
        self::assertSame('done', $tickets->find($id)['resolution']);

        $created = $service->create(['project_id' => $project, 'title' => 'y', 'status' => 'Done'], $me);
        self::assertSame('done', $tickets->find($created)['resolution']);
    }

    public function testAnOpenTicketHasNoResolutionToChange(): void
    {
        $me = $this->person();
        $service = new TicketService($this->db);
        $id = $service->create(['project_id' => $this->project(), 'title' => 'x'], $me);

        $this->expectException(ValidationError::class);
        $service->resolve($id, 'duplicate', $me);
    }

    public function testTheResolutionCanBeAskedFor(): void
    {
        $me = $this->person();
        $project = $this->project();
        $service = new TicketService($this->db);
        $kept = $service->create(['project_id' => $project, 'title' => 'kept'], $me);
        $dropped = $service->create(['project_id' => $project, 'title' => 'dropped'], $me);
        $service->changeStatus($kept, 'Done', $me);
        $service->changeStatus($dropped, 'Done', $me, 'wont_do');

        $titles = function (string $query) use ($me): array {
            $compiled = TicketQuery::compile($query, $me);
            $rows = (new TicketRepository($this->db))->search(['query_where' => $compiled['where'], 'query_params' => $compiled['params']]);

            return array_column($rows, 'title');
        };

        self::assertSame(['dropped'], $titles('resolution = "won\'t do"'));
        self::assertSame(['kept'], $titles('resolution = done'));
        self::assertSame([], $titles('resolution IS EMPTY AND category = done'));
    }
}
