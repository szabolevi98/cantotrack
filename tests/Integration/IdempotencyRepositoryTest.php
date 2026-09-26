<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Model\IdempotencyRepository;

final class IdempotencyRepositoryTest extends DatabaseTestCase
{
    public function testTheSameRequestAgainGetsTheFirstAnswer(): void
    {
        $me = $this->person();
        $keys = new IdempotencyRepository($this->db);

        $first = $keys->claim($me, 'log-45m', 'aaa');
        self::assertSame('new', $first['state']);

        // Sent again while the first is still being worked on.
        self::assertSame('busy', $keys->claim($me, 'log-45m', 'aaa')['state']);

        $keys->complete((int) $first['id'], 201, '{"data":{"id":7}}', 'https://tracker.example/api/v1/tickets/CT-7');
        $again = $keys->claim($me, 'log-45m', 'aaa');

        self::assertSame('replay', $again['state']);
        self::assertSame(201, (int) $again['row']['status']);
        self::assertSame('{"data":{"id":7}}', $again['row']['body']);
        self::assertSame('https://tracker.example/api/v1/tickets/CT-7', $again['row']['location']);
    }

    public function testAKeyIsOnePersonsAndOneRequests(): void
    {
        $me = $this->person();
        $somebody = $this->person('Béla Nagy');
        $keys = new IdempotencyRepository($this->db);

        $keys->complete((int) $keys->claim($me, 'k1', 'aaa')['id'], 204, '', null);

        self::assertSame('mismatch', $keys->claim($me, 'k1', 'bbb')['state'], 'The same key for another request was taken as a resend.');
        self::assertSame('new', $keys->claim($somebody, 'k1', 'bbb')['state'], 'Somebody else’s key was mine.');
        self::assertSame('new', $keys->claim($me, 'K1', 'aaa')['state'], 'Keys differing in case were taken as one.');
    }

    public function testAFailedRequestLetsItsKeyGo(): void
    {
        $me = $this->person();
        $keys = new IdempotencyRepository($this->db);

        $claim = $keys->claim($me, 'k', 'aaa');
        $keys->release((int) $claim['id']);

        self::assertSame('new', $keys->claim($me, 'k', 'bbb')['state']);
    }

    public function testAnAnsweredKeyIsNotLetGo(): void
    {
        $me = $this->person();
        $keys = new IdempotencyRepository($this->db);

        $claim = $keys->claim($me, 'k', 'aaa');
        $keys->complete((int) $claim['id'], 201, '{}', null);
        $keys->release((int) $claim['id']);

        self::assertSame('replay', $keys->claim($me, 'k', 'aaa')['state']);
    }

    public function testAnAbandonedClaimIsTakenOverAndAnOldKeyForgotten(): void
    {
        $me = $this->person();
        $keys = new IdempotencyRepository($this->db);

        $claim = $keys->claim($me, 'stuck', 'aaa');
        $this->db->exec('UPDATE api_idempotency_keys SET created_at = NOW() - INTERVAL 5 MINUTE WHERE id = ' . (int) $claim['id']);
        self::assertSame(['state' => 'new', 'id' => (int) $claim['id']], $keys->claim($me, 'stuck', 'aaa'));

        $old = $keys->claim($me, 'yesterday', 'aaa');
        $keys->complete((int) $old['id'], 201, '{}', null);
        $this->db->exec('UPDATE api_idempotency_keys SET created_at = NOW() - INTERVAL 25 HOUR WHERE id = ' . (int) $old['id']);
        self::assertSame('new', $keys->claim($me, 'yesterday', 'bbb')['state']);

        $this->db->exec('UPDATE api_idempotency_keys SET created_at = NOW() - INTERVAL 25 HOUR');
        self::assertSame(2, $keys->prune());
    }
}
