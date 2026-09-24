<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Model\AutomationRepository;
use CantoTrack\Model\CommentRepository;
use CantoTrack\Model\EventRepository;
use CantoTrack\Model\LabelRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\Activity;
use CantoTrack\Service\Automation;
use CantoTrack\Service\TicketService;

final class AutomationTest extends DatabaseTestCase
{
    private int $me;
    private int $project;
    private TicketService $service;
    private TicketRepository $tickets;
    private AutomationRepository $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person('Tamás Farkas', true, 'admin');
        $this->project = $this->project();
        $this->service = new TicketService($this->db);
        $this->tickets = new TicketRepository($this->db);
        $this->rules = new AutomationRepository($this->db);

        Automation::forget();
        Activity::forgetListeners();
        Activity::listen(static function (array $ticket, ?int $actorId, string $kind, ?string $field): void {
            Automation::collect($ticket, $actorId, $kind, $field);
        });
    }

    protected function tearDown(): void
    {
        Activity::forgetListeners();
        Automation::forget();
        parent::tearDown();
    }

    public function testTheParentIsDoneWhenItsLastSubtaskIs(): void
    {
        $rule = $this->rules->save(null, null, 'Done when its subtasks are', 'subtasks_done', 'category != done', [['type' => 'status', 'value' => 'done']], $this->me);
        $parent = $this->service->create(['project_id' => $this->project, 'title' => 'Pay by card'], $this->me);
        $a = $this->service->addSubtask((array) $this->tickets->find($parent), 'Form', null, $this->me);
        $b = $this->service->addSubtask((array) $this->tickets->find($parent), 'Receipt', null, $this->me);

        $this->service->changeStatus($a, 'done', $this->me);
        (new Automation($this->db))->runQueued();
        self::assertNotSame('done', $this->tickets->find($parent)['status_category']);

        $this->service->changeStatus($b, 'done', $this->me);
        (new Automation($this->db))->runQueued();
        self::assertSame('done', $this->tickets->find($parent)['status_category']);

        // Its history says the rule did it, and the rule's log says so too.
        $lines = array_values(array_filter((new EventRepository($this->db))->forTicket($parent), static fn(array $e): bool => $e['kind'] === 'status'));
        self::assertSame('Done when its subtasks are', end($lines)['via']);
        self::assertSame('done', $this->rules->recentLog($rule)[0]['outcome']);
    }

    public function testOnlyTicketsThatMatchAreTouched(): void
    {
        $this->rules->save(null, null, 'Urgent bugs', 'created', 'type = bug AND priority = urgent', [['type' => 'add_label', 'value' => 'triage'], ['type' => 'comment', 'value' => '{key} needs a look.']], $this->me);

        $urgent = $this->service->create(['project_id' => $this->project, 'title' => 'Broken', 'type' => 'bug', 'priority' => 'urgent'], $this->me);
        $calm = $this->service->create(['project_id' => $this->project, 'title' => 'Typo', 'type' => 'bug', 'priority' => 'low'], $this->me);
        (new Automation($this->db))->runQueued();

        self::assertSame(['triage'], (new LabelRepository($this->db))->forTicket($urgent));
        self::assertSame([], (new LabelRepository($this->db))->forTicket($calm));
        self::assertSame('CT-1 needs a look.', (new CommentRepository($this->db))->forTicket($urgent)[0]['body']);
    }

    public function testWhatARuleDoesDoesNotSetOffAnother(): void
    {
        // Two rules that would undo each other for ever, if one heard the other.
        $this->rules->save(null, null, 'To review', 'moved', 'category = "in progress"', [['type' => 'status', 'value' => 'done']], $this->me);
        $this->rules->save(null, null, 'Back again', 'moved', 'category = done', [['type' => 'status', 'value' => 'in progress']], $this->me);

        $id = $this->service->create(['project_id' => $this->project, 'title' => 'Ping-pong'], $this->me);
        $this->service->changeStatus($id, 'in progress', $this->me);
        $ran = (new Automation($this->db))->runQueued();

        self::assertSame(1, $ran);
        self::assertSame('done', $this->tickets->find($id)['status_category']);
    }

    public function testADailyRuleActsOnceADay(): void
    {
        $rule = $this->rules->save(null, null, 'Overdue', 'daily', 'due < startOfDay() AND category != done', [['type' => 'priority', 'value' => 'high']], $this->me);
        $late = $this->service->create(['project_id' => $this->project, 'title' => 'Late', 'due_on' => date('Y-m-d', strtotime('-3 days'))], $this->me);
        $this->service->create(['project_id' => $this->project, 'title' => 'On time', 'due_on' => date('Y-m-d', strtotime('+3 days'))], $this->me);

        self::assertSame(1, (new Automation($this->db))->daily());
        self::assertSame(0, (new Automation($this->db))->daily());
        self::assertSame('high', $this->tickets->find($late)['priority']);
        self::assertSame(1, (int) $this->rules->find($rule)['run_count']);
    }

    public function testAFailedActionIsInTheLog(): void
    {
        $rule = $this->rules->save(null, null, 'Nobody by that name', 'created', '', [['type' => 'assign', 'value' => 'Nobody Atall']], $this->me);
        $this->service->create(['project_id' => $this->project, 'title' => 'x'], $this->me);
        (new Automation($this->db))->runQueued();

        $log = $this->rules->recentLog($rule);
        self::assertSame('failed', $log[0]['outcome']);
        self::assertStringContainsString('Nobody Atall', (string) $log[0]['message']);
    }
}
