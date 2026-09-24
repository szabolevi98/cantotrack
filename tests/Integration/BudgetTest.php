<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\Budget;
use CantoTrack\Service\TicketService;

final class BudgetTest extends DatabaseTestCase
{
    public function testTheProjectsRateCountsBeforeThePersons(): void
    {
        $me = $this->person();
        $project = $this->project();
        $projects = new ProjectRepository($this->db);
        (new UserRepository($this->db))->setRate($me, 100.0);
        $ticket = (new TicketService($this->db))->create(['project_id' => $project, 'title' => 'x'], $me);
        $worklogs = new WorklogRepository($this->db);
        $worklogs->create($ticket, $me, date('Y-m-d'), 120, 'billed', true);
        $worklogs->create($ticket, $me, date('Y-m-d'), 60, 'not billed', false);

        $projects->setBudget($project, null, 10.0, 1000.0, 80);
        $budget = (new Budget($this->db))->of((array) $projects->find($project));
        self::assertSame(3.0, $budget['hours_used'], 'every hour counts against the hours');
        self::assertSame(200.0, $budget['amount_used'], 'the billable ones, at the person\'s rate, against the money');

        $projects->setBudget($project, 150.0, 10.0, 1000.0, 80);
        self::assertSame(300.0, (new Budget($this->db))->of((array) $projects->find($project))['amount_used']);
    }

    public function testAProjectWithoutABudgetHasNone(): void
    {
        self::assertNull((new Budget($this->db))->of((array) (new ProjectRepository($this->db))->find($this->project())));
    }
}
