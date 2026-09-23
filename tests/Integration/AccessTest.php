<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\Access;
use CantoTrack\Core\Auth;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\TicketService;
use CantoTrack\Service\WorklogService;

final class AccessTest extends DatabaseTestCase
{
    private int $team;
    private int $private;
    private int $teamTicket;
    private int $privateTicket;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->person('Admin', true, 'admin');
        $this->team = $this->project('TM');
        $this->private = $this->project('PV');
        (new ProjectRepository($this->db))->setVisibility($this->private, 'private');

        $tickets = new TicketService($this->db);
        $this->teamTicket = $tickets->create(['project_id' => $this->team, 'title' => 'Open to the team'], $admin);
        $this->privateTicket = $tickets->create(['project_id' => $this->private, 'title' => 'Behind the door'], $admin);
    }

    protected function tearDown(): void
    {
        Auth::refresh();
    }

    private function as(int $userId): void
    {
        Auth::actAs((array) (new UserRepository($this->db))->find($userId));
    }

    public function testAMemberSeesTheTeamsProjectsButNotAPrivateOne(): void
    {
        $this->as($this->person());
        $tickets = new TicketRepository($this->db);

        self::assertNotNull($tickets->find($this->teamTicket));
        self::assertNull($tickets->find($this->privateTicket));
        self::assertNull($tickets->findByKey('PV-1'));
        self::assertNull((new ProjectRepository($this->db))->find($this->private));
        self::assertSame(['TM'], array_column((new ProjectRepository($this->db))->allWithCounts(), 'code'));
        self::assertSame([$this->teamTicket], array_map('intval', array_column($tickets->search(), 'id')));
        self::assertSame(1, $tickets->count());
    }

    public function testAMemberAddedToAPrivateProjectSeesIt(): void
    {
        $me = $this->person();
        (new ProjectRepository($this->db))->addMember($this->private, $me);
        $this->as($me);

        self::assertNotNull((new TicketRepository($this->db))->find($this->privateTicket));
    }

    public function testAGuestSeesOnlyTheProjectsTheyWereAddedTo(): void
    {
        $guest = $this->person('Client', true, 'guest');
        (new ProjectRepository($this->db))->addMember($this->private, $guest);
        $this->as($guest);

        self::assertTrue(Access::isGuest());
        self::assertSame([$this->private], Access::projectIds());
        self::assertNull((new TicketRepository($this->db))->find($this->teamTicket));
        self::assertNotNull((new TicketRepository($this->db))->find($this->privateTicket));
    }

    public function testAnAdministratorSeesEverything(): void
    {
        $this->as($this->person('Another admin', true, 'admin'));

        self::assertNull(Access::projectIds());
        self::assertSame(2, (new TicketRepository($this->db))->count());
    }

    public function testYourOwnHoursStayYoursAfterYouAreTakenOffTheProject(): void
    {
        $me = $this->person();
        $projects = new ProjectRepository($this->db);
        $projects->addMember($this->private, $me);
        (new WorklogService($this->db))->log($this->privateTicket, $me, '1h', '', null);
        $projects->removeMember($this->private, $me);

        $this->as($me);
        $today = date('Y-m-d');
        $worklogs = new WorklogRepository($this->db);

        self::assertCount(1, $worklogs->forRange($me, $today, $today));
        self::assertSame(60, $worklogs->totalMinutes($me, $today, $today));

        // But nobody else outside the project sees them.
        $this->as($this->person('Colleague'));
        self::assertCount(0, $worklogs->forRange($me, $today, $today));
    }
}
