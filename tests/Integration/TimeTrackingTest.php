<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\TicketService;
use CantoTrack\Service\TimerService;
use CantoTrack\Service\WorklogService;

final class TimeTrackingTest extends DatabaseTestCase
{
    private int $me;
    private int $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person();
        $this->ticket = (new TicketService($this->db))->create(['project_id' => $this->project(), 'title' => 'x', 'estimate' => '1d'], $this->me);
    }

    public function testWhatIsLeftCountsDownAndCanBeSaidOutLoud(): void
    {
        $worklogs = new WorklogService($this->db);
        $tickets = new TicketRepository($this->db);

        self::assertSame(480, TicketRepository::remaining($tickets->find($this->ticket)), 'a day of estimate, nothing logged');

        $worklogs->log($this->ticket, $this->me, '2h', '', null);
        self::assertSame(360, TicketRepository::remaining($tickets->find($this->ticket)));

        $worklogs->log($this->ticket, $this->me, '1h', '', null, '5h');
        self::assertSame(300, TicketRepository::remaining($tickets->find($this->ticket)), 'said out loud, it is what was said');

        $worklogs->log($this->ticket, $this->me, '8h', '', null);
        self::assertSame(0, TicketRepository::remaining($tickets->find($this->ticket)), 'never below nothing');
    }

    public function testTheClockBecomesAWorklogWhenItStops(): void
    {
        $timers = new TimerService($this->db);
        $timers->start($this->me, $this->ticket);

        // As if it had been running for twenty minutes.
        $this->db->exec('UPDATE timers SET started_at = NOW() - INTERVAL 20 MINUTE');

        $logged = $timers->stop($this->me, 'From the clock.');

        self::assertSame(20, $logged['minutes'] ?? null);
        self::assertNull($timers->running($this->me));
        self::assertSame('From the clock.', (new WorklogRepository($this->db))->forTicket($this->ticket)[0]['note']);
    }

    public function testAClickIsNotWork(): void
    {
        $timers = new TimerService($this->db);
        $timers->start($this->me, $this->ticket);

        self::assertNull($timers->stop($this->me, null));
        self::assertSame([], (new WorklogRepository($this->db))->forTicket($this->ticket));
    }

    public function testStartingAnotherClockStopsTheFirst(): void
    {
        $other = (new TicketService($this->db))->create(['project_id' => $this->project('WEB'), 'title' => 'y'], $this->me);
        $timers = new TimerService($this->db);
        $timers->start($this->me, $this->ticket);
        $this->db->exec('UPDATE timers SET started_at = NOW() - INTERVAL 45 MINUTE');

        $logged = $timers->start($this->me, $other);

        self::assertSame(45, $logged['minutes'] ?? null);
        self::assertSame($other, (int) $timers->running($this->me)['ticket_id']);
    }
}
