<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\SettingRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\Calendar;
use CantoTrack\Service\TicketService;
use CantoTrack\Service\WeekReview;
use CantoTrack\Service\WorklogService;

final class CalendarTest extends DatabaseTestCase
{
    private int $me;
    private int $ticket;
    private string $monday;

    protected function setUp(): void
    {
        parent::setUp();
        SettingRepository::forget();

        $this->me = $this->person();
        $this->ticket = (new TicketService($this->db))->create(['project_id' => $this->project(), 'title' => 'x'], $this->me);
        $this->monday = date('Y-m-d', strtotime('monday last week'));
    }

    protected function tearDown(): void
    {
        SettingRepository::forget();
    }

    public function testAHolidayAndADayAwayExpectNothing(): void
    {
        $calendar = new Calendar($this->db);
        $tuesday = date('Y-m-d', strtotime($this->monday . ' +1 day'));
        $wednesday = date('Y-m-d', strtotime($this->monday . ' +2 days'));

        $calendar->addHoliday($tuesday, 'A day off for everybody');
        $calendar->addAbsence($this->me, $wednesday, $wednesday, 'sick', '');

        $person = (new UserRepository($this->db))->find($this->me);
        self::assertNotNull($person);
        $days = $calendar->days($person, $this->monday, date('Y-m-d', strtotime($this->monday . ' +6 days')));

        self::assertGreaterThan(0, $days[$this->monday]['expected']);
        self::assertSame(0, $days[$tuesday]['expected']);
        self::assertSame('A day off for everybody', $days[$tuesday]['holiday']);
        self::assertSame(0, $days[$wednesday]['expected']);
        self::assertSame('sick', $days[$wednesday]['absence']);
    }

    public function testAHandedInWeekCannotBeChangedUntilItIsSentBack(): void
    {
        $worklogs = new WorklogService($this->db);
        $review = new WeekReview($this->db);
        $admin = $this->person('Admin', true, 'admin');

        $worklogs->log($this->ticket, $this->me, '1h', $this->monday, null);
        $review->submit($this->me, $this->monday);

        try {
            $worklogs->log($this->ticket, $this->me, '1h', $this->monday, null);
            self::fail('A handed-in week took another entry.');
        } catch (ValidationError) {
            // as it should
        }

        self::assertCount(1, $review->pending());

        try {
            $review->review($this->me, $this->monday, $admin, false, '');
            self::fail('A week was sent back without a reason.');
        } catch (ValidationError) {
            // as it should
        }

        $review->review($this->me, $this->monday, $admin, false, 'Tuesday is missing');
        $worklogs->log($this->ticket, $this->me, '1h', $this->monday, null);

        $review->submit($this->me, $this->monday);
        $review->review($this->me, $this->monday, $admin, true, '');

        $this->expectException(ValidationError::class);
        $review->submit($this->me, $this->monday);
    }

    public function testNothingOnOrBeforeTheLockDateChanges(): void
    {
        (new SettingRepository($this->db))->set(Calendar::LOCK_SETTING, $this->monday);

        $worklogs = new WorklogService($this->db);
        $worklogs->log($this->ticket, $this->me, '1h', date('Y-m-d', strtotime($this->monday . ' +1 day')), null);

        $this->expectException(ValidationError::class);
        $worklogs->log($this->ticket, $this->me, '1h', $this->monday, null);
    }
}
