<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Model\StatusRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\TicketService;

final class StatusRepositoryTest extends DatabaseTestCase
{
    public function testANewProjectStartsWithTheFiveColumns(): void
    {
        $columns = (new StatusRepository($this->db))->forProject($this->project());

        self::assertSame(['Backlog', 'To do', 'In progress', 'Review', 'Done'], array_column($columns, 'name'));
        self::assertSame('done', $columns[4]['category']);
    }

    public function testAColumnIsFoundByIdNameOrKind(): void
    {
        $project = $this->project();
        $statuses = new StatusRepository($this->db);
        $columns = $statuses->forProject($project);

        self::assertSame($columns[2]['id'], $statuses->resolve($project, (string) $columns[2]['id'])['id']);
        self::assertSame('In progress', $statuses->resolve($project, 'in_progress')['name']);
        self::assertSame('To do', $statuses->resolve($project, 'todo')['name'], 'a name wins over a kind');
        self::assertSame('Done', $statuses->resolve($project, 'DONE')['name']);
        self::assertNull($statuses->resolve($project, 'no such column'));
    }

    public function testAnotherProjectsColumnIsNotThisOnes(): void
    {
        $ct = $this->project('CT');
        $web = $this->project('WEB');
        $statuses = new StatusRepository($this->db);
        $foreign = $statuses->forProject($web)[0]['id'];

        self::assertNull($statuses->resolve($ct, (string) $foreign));
    }

    public function testColumnsMoveOneStepAtATime(): void
    {
        $project = $this->project();
        $statuses = new StatusRepository($this->db);
        $review = $statuses->resolve($project, 'Review');

        $statuses->move((int) $review['id'], -1);

        self::assertSame(
            ['Backlog', 'To do', 'Review', 'In progress', 'Done'],
            array_column($statuses->forProject($project), 'name')
        );
    }

    public function testDeletingAColumnMovesItsTicketsFirst(): void
    {
        $me = $this->person();
        $project = $this->project();
        $statuses = new StatusRepository($this->db);
        $review = $statuses->resolve($project, 'Review');
        $done = $statuses->resolve($project, 'Done');
        $id = (new TicketService($this->db))->create(['project_id' => $project, 'title' => 'x', 'status' => 'Review'], $me);

        $statuses->delete((int) $review['id'], (int) $done['id']);

        $ticket = (new TicketRepository($this->db))->find($id);
        self::assertSame((int) $done['id'], (int) $ticket['status_id']);
        self::assertNotNull($ticket['closed_at'], 'moved into done, it was finished now');
        self::assertNull($statuses->find((int) $review['id']));
    }

    public function testChangingAColumnsKindChangesWhatItsTicketsAre(): void
    {
        $me = $this->person();
        $project = $this->project();
        $statuses = new StatusRepository($this->db);
        $review = $statuses->resolve($project, 'Review');
        $id = (new TicketService($this->db))->create(['project_id' => $project, 'title' => 'x', 'status' => 'Review'], $me);

        $statuses->update((int) $review['id'], 'Shipped', 'done', 'green', null);
        self::assertNotNull((new TicketRepository($this->db))->find($id)['closed_at']);

        $statuses->update((int) $review['id'], 'Shipped', 'in_progress', 'green', null);
        self::assertNull((new TicketRepository($this->db))->find($id)['closed_at']);
    }

    public function testAProjectWithTicketsCanStillBeDeleted(): void
    {
        $me = $this->person();
        $project = $this->project();
        (new TicketService($this->db))->create(['project_id' => $project, 'title' => 'x'], $me);

        (new \CantoTrack\Model\ProjectRepository($this->db))->delete($project);

        self::assertSame([], (new StatusRepository($this->db))->forProject($project));
    }
}
