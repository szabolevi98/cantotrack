<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\Mailer;
use CantoTrack\Core\Markdown;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\NotificationRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\EpicService;
use CantoTrack\Service\NotifySettings;
use CantoTrack\Service\TicketService;

final class EpicConversationTest extends DatabaseTestCase
{
    private int $me;
    private int $anna;
    private int $project;
    private int $epic;
    private EpicService $service;
    private EpicRepository $epics;

    protected function setUp(): void
    {
        parent::setUp();

        Markdown::forget();
        Mailer::$sent = [];

        $this->me = $this->person('Szabó Levente');
        $this->anna = $this->person('Anna Kovács');
        $this->project = $this->project();
        $this->service = new EpicService($this->db);
        $this->epics = new EpicRepository($this->db);
        $this->epic = $this->service->create($this->project, 'Checkout', 'From cart to paid order.', '2026-09-01', '2026-10-30', $this->me);
    }

    public function testMakingAnEpicIsItsFirstLineAndItsMakerFollowsIt(): void
    {
        self::assertSame(['created'], array_column($this->epics->events($this->epic), 'kind'));
        self::assertTrue($this->epics->isWatching($this->epic, $this->me));
    }

    public function testEachChangedFactIsALineOfItsHistory(): void
    {
        $this->service->update($this->epic, ['title' => 'Checkout and payment', 'ends_on' => '2026-11-15', 'description' => 'More.'], $this->me);

        $lines = array_map(
            static fn(array $e): string => $e['kind'] . ':' . $e['field'] . ':' . $e['old_value'] . '>' . $e['new_value'],
            array_slice($this->epics->events($this->epic), 1)
        );

        self::assertSame([
            'changed:title:Checkout>Checkout and payment',
            'changed:ends:2026-10-30>2026-11-15',
            'changed:description:>',
        ], $lines);
    }

    public function testNothingChangedIsNoHistory(): void
    {
        $this->service->update($this->epic, ['title' => 'Checkout', 'starts_on' => '2026-09-01'], $this->me);

        self::assertCount(1, $this->epics->events($this->epic));
    }

    public function testAnEpicCannotEndBeforeItStarts(): void
    {
        $this->expectException(ValidationError::class);

        $this->service->setField($this->epic, 'ends_on', '2026-08-01', $this->me);
    }

    public function testOnlyItsOwnFactsCanBeChangedOneByOne(): void
    {
        $this->expectException(ValidationError::class);

        $this->service->setField($this->epic, 'project_id', '99', $this->me);
    }

    public function testAMentionTellsThePersonAndMakesThemFollow(): void
    {
        $handle = (string) (new UserRepository($this->db))->find($this->anna)['handle'];

        $this->service->comment($this->epic, $this->me, 'What do you think, @' . $handle . '?');

        $told = (new NotificationRepository($this->db))->forUser($this->anna);
        self::assertCount(1, $told);
        self::assertSame('mentioned', $told[0]['reason']);
        self::assertSame((string) $this->epic, (string) $told[0]['epic_id']);
        self::assertSame('Checkout', $told[0]['epic_title']);
        self::assertTrue($this->epics->isWatching($this->epic, $this->anna));
        self::assertCount(1, Mailer::$sent);
        self::assertStringContainsString('[CT] Checkout', (string) Mailer::$sent[0]->getSubject());
    }

    public function testFollowersHearOfCommentsAndOfTheEpicBeingDoneButNotOfWhatTheyDidThemselves(): void
    {
        $this->epics->watch($this->epic, $this->anna);

        $this->service->comment($this->epic, $this->me, 'Started on the payment page.');
        $this->service->update($this->epic, ['is_done' => true], $this->me);
        $this->service->update($this->epic, ['ends_on' => '2026-11-30'], $this->me);

        $told = (new NotificationRepository($this->db))->forUser($this->anna);
        self::assertSame(['done', 'commented'], array_column($told, 'kind'));
        self::assertSame([], (new NotificationRepository($this->db))->forUser($this->me));
    }

    public function testDoneIsToldTheWayStatusChangesAre(): void
    {
        $this->epics->watch($this->epic, $this->anna);
        (new UserRepository($this->db))->setNotifications($this->anna, NotifySettings::encode(['status' => 'off']), null);

        $this->service->update($this->epic, ['is_done' => true], $this->me);

        self::assertSame([], (new NotificationRepository($this->db))->forUser($this->anna));
    }

    public function testEpicAndTicketNotificationsAreCountedTogether(): void
    {
        $this->epics->watch($this->epic, $this->anna);
        $ticket = (new TicketService($this->db))->create(['project_id' => $this->project, 'title' => 'x'], $this->me);
        $notifications = new NotificationRepository($this->db);
        $notifications->create(['user_id' => $this->anna, 'ticket_id' => $ticket, 'actor_id' => $this->me, 'reason' => 'watching', 'kind' => 'commented', 'field' => null, 'old_value' => null, 'new_value' => 'hi']);

        $this->service->comment($this->epic, $this->me, 'And here.');

        self::assertSame(2, $notifications->unreadCount($this->anna));

        $notifications->markEpicRead($this->anna, $this->epic);
        self::assertSame(1, $notifications->unreadCount($this->anna));
    }

    public function testAnEmptyCommentSaysNothing(): void
    {
        $this->expectException(ValidationError::class);

        $this->service->comment($this->epic, $this->me, "  \n ");
    }

    public function testProgressCountsItsTicketsAndTheHoursOnThemAndTheirSteps(): void
    {
        $tickets = new TicketService($this->db);
        $first = $tickets->create(['project_id' => $this->project, 'title' => 'a', 'epic_id' => $this->epic, 'story_points' => 3, 'estimate' => '4h'], $this->me);
        $tickets->create(['project_id' => $this->project, 'title' => 'b', 'epic_id' => $this->epic, 'story_points' => 5], $this->me);
        $step = $tickets->create(['project_id' => $this->project, 'title' => 'a1', 'parent' => 'CT-' . $this->number($first)], $this->me);
        $this->db->prepare('INSERT INTO worklogs (ticket_id, user_id, work_date, minutes) VALUES (:t, :u, CURDATE(), 90), (:s, :u2, CURDATE(), 30)')
            ->execute(['t' => $first, 'u' => $this->me, 's' => $step, 'u2' => $this->me]);

        $progress = $this->epics->progress($this->epic);

        self::assertSame(2, $progress['tickets']);
        self::assertSame(0, $progress['done']);
        self::assertSame(8, $progress['points']);
        self::assertSame(240, $progress['estimate']);
        self::assertSame(120, $progress['logged']);
    }

    private function number(int $ticketId): int
    {
        $statement = $this->db->prepare('SELECT number FROM tickets WHERE id = :id');
        $statement->execute(['id' => $ticketId]);

        return (int) $statement->fetchColumn();
    }
}
