<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Model\RecentRepository;
use CantoTrack\Service\Pages;
use CantoTrack\Service\TicketService;

final class RecentTest extends DatabaseTestCase
{
    public function testWhatWasOpenedLatelyComesBackLatestFirst(): void
    {
        $me = $this->person();
        $project = $this->project();
        $ticket = (new TicketService($this->db))->create(['project_id' => $project, 'title' => 'The ticket'], $me);
        $page = (new Pages($this->db))->create($project, null, 'The page', '', $me);
        $recent = new RecentRepository($this->db);

        $recent->viewed($me, 'ticket', $ticket);
        $this->db->exec('UPDATE recent_views SET viewed_at = NOW() - INTERVAL 1 HOUR');
        $recent->viewed($me, 'page', $page);

        $latest = $recent->latest($me);
        self::assertSame(['The page', 'The ticket'], array_column($latest, 'label'));
        self::assertSame('/tickets/' . $ticket, $latest[1]['url']);
        self::assertSame('CT-1', $latest[1]['hint']);

        // Opened again: first again, still once.
        $this->db->exec('UPDATE recent_views SET viewed_at = viewed_at - INTERVAL 1 HOUR');
        $recent->viewed($me, 'ticket', $ticket);
        self::assertSame(['The ticket', 'The page'], array_column($recent->latest($me), 'label'));
    }

    public function testSomethingDeletedSinceIsNotOffered(): void
    {
        $me = $this->person();
        $ticket = (new TicketService($this->db))->create(['project_id' => $this->project(), 'title' => 'Gone soon'], $me);
        $recent = new RecentRepository($this->db);
        $recent->viewed($me, 'ticket', $ticket);

        (new TicketService($this->db))->delete($ticket);

        self::assertSame([], $recent->latest($me));
    }
}
