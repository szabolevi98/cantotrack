<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\Mailer;
use CantoTrack\Core\Markdown;
use CantoTrack\Model\NotificationRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\Activity;
use CantoTrack\Service\CommentService;
use CantoTrack\Service\Notifier;
use CantoTrack\Service\NotifySettings;
use CantoTrack\Service\TicketService;

final class NotifierTest extends DatabaseTestCase
{
    private int $me;
    private int $anna;
    private int $mark;
    private int $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Activity::forgetListeners();
        Activity::listen(fn(array $t, ?int $a, string $k, ?string $f, ?string $o, ?string $n) =>
            (new Notifier($this->db))->handle($t, $a, $k, $f, $o, $n));
        Markdown::forget();
        Mailer::$sent = [];

        $this->me = $this->person('Szabó Levente');
        $this->anna = $this->person('Anna Kovács');
        $this->mark = $this->person('Márk Tóth');
        $this->ticket = (new TicketService($this->db))->create(['project_id' => $this->project(), 'title' => 'x'], $this->me);
    }

    protected function tearDown(): void
    {
        Activity::forgetListeners();
    }

    public function testEveryNewAccountHasAHandleOfItsOwn(): void
    {
        $users = new UserRepository($this->db);
        $first = $users->create('Anna', 'anna.k@example.test', 'a long enough password');
        $second = $users->create('Anna', 'annak@elsewhere.test', 'a long enough password');

        self::assertSame('annak', $users->find($first)['handle']);
        self::assertSame('annak2', $users->find($second)['handle']);
    }

    public function testSomebodyWhoTurnedAKindOffIsNotToldOfIt(): void
    {
        (new UserRepository($this->db))->setNotifications($this->anna, NotifySettings::encode(['assigned' => 'off']), null);

        (new TicketService($this->db))->update($this->ticket, ['assignee_id' => $this->anna], $this->me);

        self::assertSame([], (new NotificationRepository($this->db))->forUser($this->anna));
    }

    public function testHereOnlyIsToldButNotEmailed(): void
    {
        (new UserRepository($this->db))->setNotifications($this->anna, NotifySettings::encode(['assigned' => 'app']), null);

        (new TicketService($this->db))->update($this->ticket, ['assignee_id' => $this->anna], $this->me);
        $this->mailed();

        self::assertCount(1, (new NotificationRepository($this->db))->forUser($this->anna));
        self::assertSame([], Mailer::$sent);
    }

    public function testADigestListsWhatItsQueryFindsAndWhatIsDue(): void
    {
        $service = new TicketService($this->db);
        $service->update($this->ticket, ['assignee_id' => $this->anna, 'due_on' => date('Y-m-d')], $this->me);
        (new UserRepository($this->db))->setNotifications($this->anna, NotifySettings::encode([]), 'assignee = me');

        $anna = (array) (new UserRepository($this->db))->find($this->anna);
        [$subject, $text] = (new \CantoTrack\Service\Digest($this->db))->compose($anna, new \DateTimeImmutable('today'));

        self::assertStringContainsString('1 to look at', $subject);
        self::assertStringContainsString('CT-1', $text);
        self::assertStringContainsString('Due this week', $text);

        // Once a day: sent today, not again today.
        $digest = new \CantoTrack\Service\Digest($this->db);
        $monday = new \DateTimeImmutable('monday next week');
        self::assertSame(1, $digest->sendDue($monday));
        self::assertSame(0, $digest->sendDue($monday));
        self::assertSame(0, $digest->sendDue(new \DateTimeImmutable('sunday next week')), 'not on a weekend');
    }

    public function testBeingGivenATicketIsNotified(): void
    {
        (new TicketService($this->db))->update($this->ticket, ['assignee_id' => $this->anna], $this->me);

        $told = (new NotificationRepository($this->db))->forUser($this->anna);
        self::assertCount(1, $told);
        self::assertSame('assigned', $told[0]['reason']);
    }

    public function testAMentionReachesSomebodyWhoDidNotFollowTheTicket(): void
    {
        $handle = (new UserRepository($this->db))->find($this->mark)['handle'];

        (new CommentService($this->db))->add($this->ticket, $this->me, 'Could you look, @' . $handle . '?');

        $told = (new NotificationRepository($this->db))->forUser($this->mark);
        self::assertSame('mentioned', $told[0]['reason'] ?? null);
        self::assertTrue((new NotificationRepository($this->db))->isWatching($this->ticket, $this->mark), 'and follows it now');
    }

    public function testNobodyIsToldAboutWhatTheyDidThemselves(): void
    {
        (new CommentService($this->db))->add($this->ticket, $this->me, 'A note to myself.');

        self::assertSame([], (new NotificationRepository($this->db))->forUser($this->me));
    }

    public function testFollowersHearAboutCommentsButNotAboutHours(): void
    {
        (new NotificationRepository($this->db))->watch($this->ticket, $this->anna);

        (new CommentService($this->db))->add($this->ticket, $this->mark, 'Found the cause.');
        (new \CantoTrack\Service\WorklogService($this->db))->log($this->ticket, $this->mark, '1h', '', null);

        $told = (new NotificationRepository($this->db))->forUser($this->anna);
        self::assertCount(1, $told);
        self::assertSame('commented', $told[0]['kind']);
    }

    public function testANotificationIsAlsoAnEmailUnlessTurnedOff(): void
    {
        (new TicketService($this->db))->update($this->ticket, ['assignee_id' => $this->anna], $this->me);
        $this->mailed();
        self::assertCount(1, Mailer::$sent);
        self::assertStringContainsString('[CT-1]', (string) Mailer::$sent[0]->getSubject());

        Mailer::$sent = [];
        $this->db->exec('UPDATE users SET notify_email = 0 WHERE id = ' . $this->mark);
        (new TicketService($this->db))->update($this->ticket, ['assignee_id' => $this->mark], $this->me);
        $this->mailed();

        // Anna, who follows it since it was hers, hears by email; Márk, who
        // turned email off, only in the application.
        $markEmail = (new UserRepository($this->db))->find($this->mark)['email'];
        $to = array_map(static fn($mail): string => $mail->getTo()[0]->getAddress(), Mailer::$sent);
        self::assertNotContains($markEmail, $to);
        self::assertCount(1, (new NotificationRepository($this->db))->forUser($this->mark));
    }

    public function testATicketsChangesOfAFewMinutesAreOneEmail(): void
    {
        (new NotificationRepository($this->db))->watch($this->ticket, $this->anna);
        $comments = new CommentService($this->db);
        $comments->add($this->ticket, $this->mark, 'Found the cause.');
        $comments->add($this->ticket, $this->mark, 'And fixed it.');
        (new TicketService($this->db))->update($this->ticket, ['priority' => 'high'], $this->mark);

        self::assertSame(0, (new Notifier($this->db))->sendDue(), 'nothing before its minutes are up');
        $this->mailed();

        $toAnna = $this->mailTo($this->anna);
        self::assertCount(1, $toAnna);
        self::assertStringContainsString('Found the cause.', (string) $toAnna[0]->getTextBody());
        self::assertStringContainsString('And fixed it.', (string) $toAnna[0]->getTextBody());
        self::assertStringContainsString('because you follow CT-1', (string) $toAnna[0]->getTextBody());
    }

    public function testWhatWasReadInTheMeantimeIsNotEmailed(): void
    {
        (new NotificationRepository($this->db))->watch($this->ticket, $this->anna);
        (new CommentService($this->db))->add($this->ticket, $this->mark, 'Seen it?');

        (new NotificationRepository($this->db))->markTicketRead($this->anna, $this->ticket);
        $this->mailed();

        self::assertSame([], $this->mailTo($this->anna));
    }

    public function testAMessageIsWrittenOnceHoweverOftenTheJobRuns(): void
    {
        (new TicketService($this->db))->update($this->ticket, ['assignee_id' => $this->anna], $this->me);

        self::assertSame(1, (new Notifier($this->db))->sendDue(10));
        self::assertSame(0, (new Notifier($this->db))->sendDue(10));
    }

    public function testAMutedTicketsChangesDoNotReachItsReporterButAMentionDoes(): void
    {
        $notifications = new NotificationRepository($this->db);
        self::assertTrue($notifications->isWatching($this->ticket, $this->me), 'its reporter hears about it');

        $notifications->unwatch($this->ticket, $this->me);
        self::assertTrue($notifications->isMuted($this->ticket, $this->me));
        self::assertFalse($notifications->isWatching($this->ticket, $this->me));

        $comments = new CommentService($this->db);
        $comments->add($this->ticket, $this->mark, 'A change nobody asked about.');
        self::assertSame([], $notifications->forUser($this->me));

        $handle = (new UserRepository($this->db))->find($this->me)['handle'];
        $comments->add($this->ticket, $this->mark, 'But this one is for you, @' . $handle);
        self::assertSame('mentioned', $notifications->forUser($this->me)[0]['reason'] ?? null);
        self::assertFalse($notifications->isMuted($this->ticket, $this->me), 'a mention follows it again');
    }

    public function testTheBellFindsWhatCameAfterTheNewestItKnew(): void
    {
        $notifications = new NotificationRepository($this->db);
        $notifications->watch($this->ticket, $this->anna);
        (new CommentService($this->db))->add($this->ticket, $this->mark, 'One.');
        $known = $notifications->latestId($this->anna);
        (new CommentService($this->db))->add($this->ticket, $this->mark, 'Two.');

        $fresh = $notifications->unreadSince($this->anna, $known);
        self::assertCount(1, $fresh);
        self::assertSame('Two.', $fresh[0]['new_value']);
        self::assertSame('commented', $fresh[0]['told_kind']);
    }

    public function testTheListIsNarrowedByKindAndOneTicketsRowsGoTogether(): void
    {
        $notifications = new NotificationRepository($this->db);
        $notifications->watch($this->ticket, $this->anna);
        $projectId = (int) (new \CantoTrack\Model\TicketRepository($this->db))->find($this->ticket)['project_id'];
        $other = (new TicketService($this->db))->create(['project_id' => $projectId, 'title' => 'y', 'assignee_id' => $this->anna], $this->mark);
        (new CommentService($this->db))->add($this->ticket, $this->mark, 'One.');
        (new CommentService($this->db))->add($this->ticket, $this->mark, 'Two.');

        self::assertCount(2, $notifications->forUser($this->anna, 50, 0, ['kind' => 'commented']));
        self::assertCount(1, $notifications->forUser($this->anna, 50, 0, ['kind' => 'assigned']));

        $groups = \CantoTrack\Controller\NotificationController::grouped($notifications->forUser($this->anna));
        self::assertCount(2, $groups);
        self::assertCount(1, $groups[0]['rest']);
        self::assertSame(2, $groups[0]['unread']);
        self::assertSame($other, (int) $groups[1]['first']['ticket_id']);
    }

    public function testOldReadNotificationsAreCleared(): void
    {
        $notifications = new NotificationRepository($this->db);
        $notifications->watch($this->ticket, $this->anna);
        (new CommentService($this->db))->add($this->ticket, $this->mark, 'Old news.');
        (new CommentService($this->db))->add($this->ticket, $this->mark, 'Still unread.');
        $this->db->exec('UPDATE notifications SET created_at = NOW() - INTERVAL 200 DAY');
        $this->db->exec("UPDATE notifications SET read_at = NOW() WHERE new_value = 'Old news.'");

        // Anna's read one and its reporter's, both half a year old.
        self::assertSame(2, $notifications->prune());
        self::assertSame(['Still unread.'], array_column($notifications->forUser($this->anna), 'new_value'));
    }

    /** As if the few minutes had passed and the outbox job had run. */
    private function mailed(): void
    {
        (new Notifier($this->db))->sendDue(10);
    }

    /** @return list<\Symfony\Component\Mime\Email> */
    private function mailTo(int $userId): array
    {
        $address = (new UserRepository($this->db))->find($userId)['email'];

        return array_values(array_filter(Mailer::$sent, static fn($mail): bool => $mail->getTo()[0]->getAddress() === $address));
    }
}
