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

    public function testEndingTheSessionsSignsTheAppsOutAndLeavesTheScripts(): void
    {
        $me = $this->person();
        $somebody = $this->person('Béla Nagy');
        $tokens = new ApiTokenRepository($this->db);

        $script = $tokens->create($me, 'Deploy script');
        $phone = $tokens->create($me, 'App — Pixel 8', null, true);
        $theirPhone = $tokens->create($somebody, 'App — iPhone', null, true);

        self::assertSame([1, 0], array_map(static fn(array $t): int => (int) $t['from_sign_in'], $tokens->forUser($me)));

        (new UserRepository($this->db))->endSessions($me);

        self::assertNull($tokens->userFor($phone), 'The phone stayed signed in after the sessions ended.');
        self::assertNotNull($tokens->userFor($script), 'A script token was revoked with the sessions.');
        self::assertNotNull($tokens->userFor($theirPhone), 'Somebody else’s phone was signed out.');
    }

    public function testAnAppSignsOutWithItsOwnToken(): void
    {
        $me = $this->person();
        $tokens = new ApiTokenRepository($this->db);
        $phone = $tokens->create($me, 'App', null, true);
        $tablet = $tokens->create($me, 'App', null, true);

        self::assertTrue($tokens->revokeToken($phone));
        self::assertFalse($tokens->revokeToken($phone));
        self::assertNull($tokens->userFor($phone));
        self::assertNotNull($tokens->userFor($tablet));
    }
}
