<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Markdown;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\ApiTokenRepository;
use CantoTrack\Model\NotificationRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\Activity;
use CantoTrack\Service\CommentService;
use CantoTrack\Service\Notifier;
use CantoTrack\Service\TicketService;

final class ServiceAccountTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Activity::forgetListeners();
    }

    public function testAServiceAccountIsNamedAfterItsProgramAndIsNobodysMailbox(): void
    {
        $users = new UserRepository($this->db);
        $first = (array) $users->find($users->createService('GitLab CI'));
        $second = (array) $users->find($users->createService('GitLab CI', 'guest'));
        $accented = (array) $users->find($users->createService('Webshop — Árfolyam'));

        self::assertSame('gitlab-ci@service.invalid', $first['email']);
        self::assertSame('gitlab-ci-2@service.invalid', $second['email']);
        self::assertSame('gitlabci', $first['handle']);
        self::assertSame('gitlabci2', $second['handle']);
        self::assertStringEndsWith('@service.invalid', (string) $accented['email']);
        self::assertSame(1, (int) $first['is_service']);
        self::assertSame(0, (int) $first['notify_email']);
        self::assertSame('member', $first['role']);
        self::assertSame('guest', $second['role']);
    }

    public function testItIsNeverAnAdministrator(): void
    {
        $users = new UserRepository($this->db);

        self::assertSame('member', $users->find($users->createService('Sneaky', 'admin'))['role']);
    }

    public function testItIsLeftOutOfThePeopleAndCannotSignInWithAPassword(): void
    {
        $users = new UserRepository($this->db);
        $person = $this->person();
        $service = $users->createService('Webshop');

        self::assertSame([$person], array_map('intval', array_column($users->active(), 'id')));
        self::assertContains($service, array_map('intval', array_column($users->active(true), 'id')));
        self::assertNotContains($service, array_map('intval', array_column($users->withActivity(), 'id')));
        self::assertSame([$service], array_map('intval', array_column($users->services(), 'id')));

        // Whatever its password is, the sign-in refuses it; its tokens work.
        $users->setPassword($service, 'a long enough password');
        self::assertNull(Auth::verifyCredentials('webshop@service.invalid', 'a long enough password'));

        $token = (new ApiTokenRepository($this->db))->create($service, 'Production');
        self::assertSame($service, (int) ((new ApiTokenRepository($this->db))->userFor($token)['id'] ?? 0));
        self::assertSame(1, (int) $users->services()[0]['token_count']);
    }

    public function testItCannotBeGivenWork(): void
    {
        $me = $this->person();
        $service = (new UserRepository($this->db))->createService('Webshop');

        $this->expectException(ValidationError::class);
        (new TicketService($this->db))->create(['project_id' => $this->project(), 'title' => 'x', 'assignee_id' => $service], $me);
    }

    public function testItMakesTicketsAndIsNeverNotified(): void
    {
        Activity::forgetListeners();
        Activity::listen(fn(array $t, ?int $a, string $k, ?string $f, ?string $o, ?string $n) =>
            (new Notifier($this->db))->handle($t, $a, $k, $f, $o, $n));
        Markdown::forget();

        $anna = $this->person('Anna Kovács');
        $service = (new UserRepository($this->db))->createService('Webshop');
        $ticket = (new TicketService($this->db))->create(['project_id' => $this->project(), 'title' => 'Order #1042 failed'], $service);

        // Anna says something on the webshop's ticket, and mentions it: the
        // webshop, its reporter and now a follower, hears nothing.
        $handle = (string) (new UserRepository($this->db))->find($service)['handle'];
        (new CommentService($this->db))->add($ticket, $anna, 'Looking into it, @' . $handle);

        self::assertSame([], (new NotificationRepository($this->db))->forUser($service));
    }
}
