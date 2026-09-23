<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ConflictError;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\TicketService;

final class TicketServiceTest extends DatabaseTestCase
{
    public function testTicketsAreNumberedPerProject(): void
    {
        $me = $this->person();
        $ct = $this->project('CT');
        $web = $this->project('WEB');
        $service = new TicketService($this->db);

        $service->create(['project_id' => $ct, 'title' => 'One'], $me);
        $second = $service->create(['project_id' => $ct, 'title' => 'Two'], $me);
        $firstOfWeb = $service->create(['project_id' => $web, 'title' => 'Other'], $me);

        $tickets = new TicketRepository($this->db);
        self::assertSame(2, (int) $tickets->find($second)['number']);
        self::assertSame(1, (int) $tickets->find($firstOfWeb)['number']);
        self::assertSame($second, (int) $tickets->findByKey('ct-2')['id']);
    }

    public function testAnAssigneeMustBeAnActiveAccount(): void
    {
        $me = $this->person();
        $gone = $this->person('Gone Person', false);
        $project = $this->project();
        $service = new TicketService($this->db);

        foreach ([999999, $gone] as $assignee) {
            try {
                $service->create(['project_id' => $project, 'title' => 'x', 'assignee_id' => $assignee], $me);
                self::fail('An assignee who cannot work should be refused.');
            } catch (ValidationError $e) {
                self::assertStringContainsString('no such active account', $e->getMessage());
            }
        }
    }

    public function testAnAssigneeDeactivatedSinceIsKeptByAnUnrelatedEdit(): void
    {
        $me = $this->person();
        $colleague = $this->person('Márk Tóth');
        $project = $this->project();
        $service = new TicketService($this->db);

        $id = $service->create(['project_id' => $project, 'title' => 'x', 'assignee_id' => $colleague], $me);
        $this->db->exec('UPDATE users SET is_active = 0 WHERE id = ' . $colleague);

        $service->update($id, ['title' => 'A new title', 'assignee_id' => $colleague]);

        self::assertSame($colleague, (int) (new TicketRepository($this->db))->find($id)['assignee_id']);
    }

    public function testAnEpicMustBeFromTheTicketsOwnProject(): void
    {
        $me = $this->person();
        $ct = $this->project('CT');
        $web = $this->project('WEB');
        $foreignEpic = (new EpicRepository($this->db))->create($web, 'Elsewhere', null);

        $this->expectException(ValidationError::class);
        (new TicketService($this->db))->create(['project_id' => $ct, 'title' => 'x', 'epic_id' => $foreignEpic], $me);
    }

    public function testAnArchivedProjectTakesNoNewTickets(): void
    {
        $me = $this->person();
        $archived = $this->project('OLD', true);

        $this->expectException(ValidationError::class);
        (new TicketService($this->db))->create(['project_id' => $archived, 'title' => 'x'], $me);
    }

    public function testASaveOverAnOlderVersionIsRefused(): void
    {
        $me = $this->person();
        $project = $this->project();
        $service = new TicketService($this->db);
        $id = $service->create(['project_id' => $project, 'title' => 'Original'], $me);

        $service->update($id, ['title' => 'First edit', 'version' => 1]);

        try {
            $service->update($id, ['title' => 'Second edit over version 1', 'version' => 1]);
            self::fail('The second save should have been refused.');
        } catch (ConflictError $e) {
            self::assertSame('First edit', $e->current['title']);
        }

        self::assertSame('First edit', (new TicketRepository($this->db))->find($id)['title']);
    }

    public function testATicketWithHoursCannotBeDeleted(): void
    {
        $me = $this->person();
        $project = $this->project();
        $service = new TicketService($this->db);
        $id = $service->create(['project_id' => $project, 'title' => 'Worked on'], $me);
        (new WorklogRepository($this->db))->create($id, $me, date('Y-m-d'), 60, null);

        try {
            $service->delete($id);
            self::fail('A ticket with hours should not be deletable.');
        } catch (ValidationError) {
            self::assertNotNull((new TicketRepository($this->db))->find($id));
        }
    }

    public function testMovingToDoneAndBackKeepsTheClosingDateHonest(): void
    {
        $me = $this->person();
        $project = $this->project();
        $service = new TicketService($this->db);
        $tickets = new TicketRepository($this->db);
        $id = $service->create(['project_id' => $project, 'title' => 'x'], $me);

        $service->changeStatus($id, 'done');
        self::assertNotNull($tickets->find($id)['closed_at']);

        $service->changeStatus($id, 'in_progress');
        self::assertNull($tickets->find($id)['closed_at']);
    }
}
