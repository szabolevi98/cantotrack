<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Service\Calendar;
use CantoTrack\Service\Planning;
use CantoTrack\Service\TicketService;

final class PlanningTest extends DatabaseTestCase
{
    public function testOnlyTheWorkingDaysOfAStretchArePlanned(): void
    {
        $me = $this->person();
        $project = $this->project();
        (new TicketService($this->db))->create(['project_id' => $project, 'title' => 'x'], $me);

        // A week from Monday the 5th of October 2026, with a holiday on the Wednesday.
        (new Calendar($this->db))->addHoliday('2026-10-07', 'A day off for everybody');
        $planning = new Planning($this->db);
        $planning->add($me, 'CT-1', null, '2026-10-05', '2026-10-11', '4h', '', $me);

        $days = $planning->perDay($planning->plans('2026-10-05', '2026-10-11'), '2026-10-05', '2026-10-11')[$me];

        self::assertSame(['2026-10-05', '2026-10-06', '2026-10-08', '2026-10-09'], array_keys($days));
        self::assertSame(16 * 60, array_sum($days));
    }

    public function testAPlanNeedsSomethingToBeFor(): void
    {
        $me = $this->person();

        $this->expectException(ValidationError::class);
        (new Planning($this->db))->add($me, '', null, '2026-10-05', '2026-10-09', '4h', '', $me);
    }
}
