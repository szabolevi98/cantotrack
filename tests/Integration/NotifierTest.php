<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\Mailer;
use CantoTrack\Core\Markdown;
use CantoTrack\Model\NotificationRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\Activity;
use CantoTrack\Service\CommentService;
use CantoTrack\Service\Notifier;
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
        self::assertCount(1, Mailer::$sent);
        self::assertStringContainsString('[CT-1]', (string) Mailer::$sent[0]->getSubject());

        Mailer::$sent = [];
        $this->db->exec('UPDATE users SET notify_email = 0 WHERE id = ' . $this->mark);
        (new TicketService($this->db))->update($this->ticket, ['assignee_id' => $this->mark], $this->me);

        // Anna, who follows it since it was hers, hears by email; Márk, who
        // turned email off, only in the application.
        $markEmail = (new UserRepository($this->db))->find($this->mark)['email'];
        $to = array_map(static fn($mail): string => $mail->getTo()[0]->getAddress(), Mailer::$sent);
        self::assertNotContains($markEmail, $to);
        self::assertCount(1, (new NotificationRepository($this->db))->forUser($this->mark));
    }
}
