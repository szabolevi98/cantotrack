<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\TicketService;
use CantoTrack\Service\WorklogService;

final class WorklogServiceTest extends DatabaseTestCase
{
    private int $me;
    private int $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person();
        $this->ticket = (new TicketService($this->db))->create(['project_id' => $this->project(), 'title' => 'x'], $this->me);
    }

    public function testASliverIsRoundedUpToTheMinimum(): void
    {
        $logged = (new WorklogService($this->db))->log($this->ticket, $this->me, '5m', '', null);

        self::assertTrue($logged['rounded']);
        self::assertSame(15, $logged['minutes']);
        self::assertSame(15, (int) (new WorklogRepository($this->db))->find($logged['id'])['minutes']);
    }

    public function testAFutureDayIsRefused(): void
    {
        $this->expectException(ValidationError::class);
        (new WorklogService($this->db))->log($this->ticket, $this->me, '1h', date('Y-m-d', strtotime('+2 days')), null);
    }

    public function testAnImpossibleDateIsRefusedRatherThanRolledOver(): void
    {
        $this->expectException(ValidationError::class);
        (new WorklogService($this->db))->log($this->ticket, $this->me, '1h', '2026-02-31', null);
    }

    public function testMoreThanADayInOneEntryIsRefused(): void
    {
        $this->expectException(ValidationError::class);
        (new WorklogService($this->db))->log($this->ticket, $this->me, '1500', '', null);
    }

    public function testOnlyTheOwnerOrAnAdministratorMayChangeAnEntry(): void
    {
        $entry = ['user_id' => $this->me];

        self::assertTrue(WorklogService::canChange($entry, $this->me, false));
        self::assertFalse(WorklogService::canChange($entry, $this->me + 1, false));
        self::assertTrue(WorklogService::canChange($entry, $this->me + 1, true));
    }
}
