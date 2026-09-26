<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\Auth;
use CantoTrack\Model\BoardRepository;
use CantoTrack\Model\NotificationRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\BoardService;

/** The lists only the API reads: every board somebody may see, and a count of their notifications. */
final class ApiListsTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Auth::actAs(null);
    }

    public function testTheBoardsAreTheOnesOfTheProjectsSomebodyMaySee(): void
    {
        $open = $this->project('OPEN');
        $secret = $this->project('SECRET');
        $projects = new ProjectRepository($this->db);
        $projects->setVisibility($secret, 'private');
        $shared = (new BoardService($this->db))->create('Both', [$open, $secret], '');
        $boards = new BoardRepository($this->db);

        $ids = static fn(): array => array_map(static fn(array $b): int => (int) $b['id'], $boards->visible());
        $own = static fn(int $project): int => (int) $boards->ownOf($project)['id'];

        // A member sees the team's project's board, and the shared board it is on.
        Auth::actAs((array) (new UserRepository($this->db))->find($this->person()));
        self::assertSame([$own($open), $shared], $ids());

        // Added to the private project, its board too — the projects' own first.
        $guest = $this->person('Eszter Varga', true, 'guest');
        Auth::actAs((array) (new UserRepository($this->db))->find($guest));
        self::assertSame([], $ids());

        $projects->addMember($secret, $guest);
        Auth::actAs((array) (new UserRepository($this->db))->find($guest));
        self::assertSame([$own($secret), $shared], $ids());
    }

    public function testTheCountIsOfTheSameNotificationsTheListShows(): void
    {
        $me = $this->person();
        $project = $this->project();
        $ticket = (new \CantoTrack\Service\TicketService($this->db))->create(['project_id' => $project, 'title' => 'x'], $me);
        $notifications = new NotificationRepository($this->db);

        foreach (['watching', 'mentioned', 'assigned'] as $reason) {
            $notifications->create(['user_id' => $me, 'ticket_id' => $ticket, 'actor_id' => null, 'reason' => $reason, 'kind' => 'commented', 'field' => null, 'old_value' => null, 'new_value' => null]);
        }

        $first = (int) $notifications->forUser($me)[0]['id'];
        $notifications->markRead($first);

        self::assertSame(3, $notifications->countForUser($me));
        self::assertSame(2, $notifications->countForUser($me, ['unread' => true]));
        self::assertCount(2, $notifications->forUser($me, 50, 0, ['unread' => true]));
    }
}
