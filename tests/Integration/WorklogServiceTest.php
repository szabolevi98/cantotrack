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

    public function testAnEntrySaysWhenItStartedAndWhatKindOfWorkItWas(): void
    {
        $types = new \CantoTrack\Model\WorkTypeRepository($this->db);
        $meeting = $types->create('Meeting');
        $service = new WorklogService($this->db);

        $logged = $service->log($this->ticket, $this->me, '45m', '', null, '', null, '9:30', 'Meeting');
        $entry = (new WorklogRepository($this->db))->find($logged['id']);

        self::assertNotNull($entry);
        self::assertSame('09:30:00', $entry['started_at']);
        self::assertSame($meeting, (int) $entry['work_type_id']);
        self::assertSame('Meeting', $entry['work_type_name']);

        // A retired type is not offered, and not taken.
        $types->update($meeting, 'Meeting', false);
        $this->expectException(ValidationError::class);
        $service->log($this->ticket, $this->me, '15m', '', null, '', null, '', 'Meeting');
    }

    public function testAnEntryCannotRunPastMidnight(): void
    {
        $this->expectException(ValidationError::class);
        (new WorklogService($this->db))->log($this->ticket, $this->me, '2h', '', null, '', null, '23:00');
    }

    public function testTheReportsAddTheHoursUpByWorkType(): void
    {
        $types = new \CantoTrack\Model\WorkTypeRepository($this->db);
        $types->create('Development');
        $service = new WorklogService($this->db);
        $service->log($this->ticket, $this->me, '1h', '', null, '', null, '', 'Development');
        $service->log($this->ticket, $this->me, '30m', '', null);

        $summary = (new \CantoTrack\Model\ReportRepository($this->db))->summary(['from' => date('Y-m-d'), 'to' => date('Y-m-d')], 'type');

        self::assertEqualsCanonicalizing(['Development' => 60, '' => 30], array_column($summary, 'minutes', 'label'));
    }
}
