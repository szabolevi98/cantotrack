<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\ReleaseRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\ReleaseService;
use CantoTrack\Service\TicketService;

final class ReleaseTest extends DatabaseTestCase
{
    private int $me;
    private int $project;
    private ReleaseService $service;
    private TicketService $tickets;
    private TicketRepository $found;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person();
        $this->project = $this->project();
        $this->service = new ReleaseService($this->db);
        $this->tickets = new TicketService($this->db);
        $this->found = new TicketRepository($this->db);
    }

    public function testAReleaseNameIsTheProjectsOwn(): void
    {
        $this->service->create($this->project, '1.4', '', '', '');

        $this->expectException(ValidationError::class);
        $this->service->create($this->project, '1.4', '', '', '');
    }

    public function testReleasingMovesTheUnfinishedWorkOnWithItsSubtasks(): void
    {
        $now = $this->service->create($this->project, '1.4', '', '', '2026-10-30');
        $next = $this->service->create($this->project, '1.5', '', '', '');
        $done = $this->tickets->create(['project_id' => $this->project, 'title' => 'Done one', 'release' => '1.4', 'status' => 'done'], $this->me);
        $open = $this->tickets->create(['project_id' => $this->project, 'title' => 'Open one', 'release' => '1.4'], $this->me);
        $step = $this->tickets->addSubtask((array) $this->found->find($open), 'A step of it', null, $this->me);

        self::assertSame($now, (int) $this->found->find($step)['release_id']);

        $releases = new ReleaseRepository($this->db);
        $this->service->release((array) $releases->find($now), $next, $this->me);

        self::assertNotNull($releases->find($now)['released_at']);
        self::assertSame($now, (int) $this->found->find($done)['release_id']);
        self::assertSame($next, (int) $this->found->find($open)['release_id']);
        self::assertSame($next, (int) $this->found->find($step)['release_id']);
    }

    public function testAReleaseThatWentOutTakesNoNewTickets(): void
    {
        $release = $this->service->create($this->project, '1.4', '', '', '');
        $this->service->release((array) (new ReleaseRepository($this->db))->find($release), null, $this->me);

        $this->expectException(ValidationError::class);
        $this->tickets->create(['project_id' => $this->project, 'title' => 'Too late', 'release_id' => $release], $this->me);
    }

    public function testTheNotesListWhatIsFinishedByKind(): void
    {
        $release = $this->service->create($this->project, '1.4', 'Autumn.', '', '');
        $this->tickets->create(['project_id' => $this->project, 'title' => 'Pay by card', 'type' => 'story', 'release' => '1.4', 'status' => 'done'], $this->me);
        $this->tickets->create(['project_id' => $this->project, 'title' => 'Double shipping', 'type' => 'bug', 'release' => '1.4', 'status' => 'done'], $this->me);
        $this->tickets->create(['project_id' => $this->project, 'title' => 'Not yet', 'type' => 'story', 'release' => '1.4'], $this->me);

        $notes = $this->service->notes((array) (new ReleaseRepository($this->db))->find($release));

        self::assertStringContainsString("### New\n\n- CT-1 Pay by card", $notes);
        self::assertStringContainsString("### Fixed\n\n- CT-2 Double shipping", $notes);
        self::assertStringNotContainsString('Not yet', $notes);
    }
}
