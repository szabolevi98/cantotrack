<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Model\ApiTokenRepository;
use CantoTrack\Model\UserRepository;

final class ApiTokenRepositoryTest extends DatabaseTestCase
{
    public function testATokenActsAsItsOwnerAndOnlyItsHashIsKept(): void
    {
        $me = $this->person();
        $tokens = new ApiTokenRepository($this->db);
        $token = $tokens->create($me, 'Script');

        self::assertMatchesRegularExpression('/^ct_[0-9a-f]{40}$/', $token);
        self::assertSame($me, (int) ($tokens->userFor($token)['id'] ?? 0));
        self::assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM api_tokens WHERE token_hash = '" . $token . "'")->fetchColumn());

        $listed = $tokens->forUser($me);
        self::assertSame(substr($token, 0, 10), $listed[0]['prefix']);
        self::assertArrayNotHasKey('token_hash', $listed[0]);
    }

    public function testExpiredRevokedAndDeactivatedTokensAreRefused(): void
    {
        $me = $this->person();
        $tokens = new ApiTokenRepository($this->db);

        $expired = $tokens->create($me, 'Old', date('Y-m-d', strtotime('-1 day')));
        self::assertNull($tokens->userFor($expired));

        $revoked = $tokens->create($me, 'Gone');
        $id = (int) $tokens->forUser($me)[0]['id'];
        self::assertFalse($tokens->revoke($id, $me + 1), 'Somebody else revoked my token.');
        self::assertTrue($tokens->revoke($id, $me));
        self::assertNull($tokens->userFor($revoked));

        $live = $tokens->create($me, 'Live');
        $users = new UserRepository($this->db);
        $person = (array) $users->find($me);
        $users->update($me, (string) $person['name'], (string) $person['email'], 'member', false);
        self::assertNull($tokens->userFor($live));

        self::assertNull($tokens->userFor('ct_nonsense'));
    }
}
