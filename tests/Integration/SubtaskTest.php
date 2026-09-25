<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\SprintService;
use CantoTrack\Service\TicketService;
use CantoTrack\Service\WorklogService;

final class SubtaskTest extends DatabaseTestCase
{
    private int $me;
    private int $project;
    private TicketService $service;
    private TicketRepository $tickets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person();
        $this->project = $this->project();
        $this->service = new TicketService($this->db);
        $this->tickets = new TicketRepository($this->db);
    }

    public function testASubtaskTakesItsParentsEpicAndSprint(): void
    {
        $epic = (new EpicRepository($this->db))->create($this->project, 'Checkout', null);
        $sprints = new SprintService($this->db);
        $sprint = $sprints->create($this->ownBoard($this->project), 'Sprint 1', '', '2026-10-05', '2026-10-16');
        $parent = $this->service->create(['project_id' => $this->project, 'title' => 'Pay by card', 'epic_id' => $epic], $this->me);
        $sprints->assign([$parent], $sprint, $this->me);

        $sub = $this->service->addSubtask((array) $this->tickets->find($parent), 'The form', null, $this->me);
        $found = (array) $this->tickets->find($sub);

        self::assertSame($parent, (int) $found['parent_id']);
        self::assertSame($epic, (int) $found['epic_id']);
        self::assertSame($sprint, (int) $found['sprint_id']);

        // Taken out of the sprint, the parent takes its steps along.
        $sprints->assign([$parent], null, $this->me);
        self::assertNull($this->tickets->find($sub)['sprint_id']);
    }

    public function testTheParentAddsUpItsSubtasks(): void
    {
        $parent = $this->service->create(['project_id' => $this->project, 'title' => 'Pay by card'], $this->me);
        $sub = $this->service->addSubtask((array) $this->tickets->find($parent), 'The form', null, $this->me);
        $this->service->update($sub, ['estimate' => '3h'], $this->me);
        (new WorklogService($this->db))->log($sub, $this->me, '2h', date('Y-m-d'), '');
        $this->service->changeStatus($sub, 'done', $this->me);

        $found = (array) $this->tickets->find($parent);

        self::assertSame(1, (int) $found['subtask_count']);
        self::assertSame(1, (int) $found['subtasks_done']);
        self::assertSame(120, (int) $found['subtask_minutes']);
        self::assertSame(180, (int) $found['subtask_estimate']);
    }

    public function testSubtasksGoOneLevelDeep(): void
    {
        $parent = $this->service->create(['project_id' => $this->project, 'title' => 'Pay by card'], $this->me);
        $sub = $this->service->addSubtask((array) $this->tickets->find($parent), 'The form', null, $this->me);

        $this->expectException(ValidationError::class);
        $this->service->addSubtask((array) $this->tickets->find($sub), 'Deeper', null, $this->me);
    }

    public function testATicketWithSubtasksCannotBecomeOne(): void
    {
        $first = $this->service->create(['project_id' => $this->project, 'title' => 'One'], $this->me);
        $second = $this->service->create(['project_id' => $this->project, 'title' => 'Two'], $this->me);
        $this->service->addSubtask((array) $this->tickets->find($first), 'A step', null, $this->me);

        $this->expectException(ValidationError::class);
        $this->service->update($first, ['parent' => 'CT-' . $this->tickets->find($second)['number']], $this->me);
    }

    public function testAParentIsNotDeletedFromUnderItsSubtasks(): void
    {
        $parent = $this->service->create(['project_id' => $this->project, 'title' => 'Pay by card'], $this->me);
        $this->service->addSubtask((array) $this->tickets->find($parent), 'The form', null, $this->me);

        $this->expectException(ValidationError::class);
        $this->service->delete($parent);
    }

    public function testASubtaskIsMadeATicketOfItsOwnAgain(): void
    {
        $parent = $this->service->create(['project_id' => $this->project, 'title' => 'Pay by card'], $this->me);
        $sub = $this->service->addSubtask((array) $this->tickets->find($parent), 'The form', null, $this->me);

        $this->service->update($sub, ['parent' => ''], $this->me);

        self::assertNull($this->tickets->find($sub)['parent_id']);
        self::assertSame(0, (int) $this->tickets->find($parent)['subtask_count']);
    }
}
