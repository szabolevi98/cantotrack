<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\Auth;
use CantoTrack\Model\UserRepository;

/** A year signed in, and the ways out of it — see the 0049 migration. */
final class SignedInSessionsTest extends DatabaseTestCase
{
    private int $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person();
        $_SESSION = [];
        Auth::actAs(null);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Auth::actAs(null);
    }

    public function testASessionSignedInBeforeSigningOutEverywhereIsOver(): void
    {
        $this->signedIn(time() - 60);
        self::assertNotNull(Auth::user());

        (new UserRepository($this->db))->endSessions($this->me);
        Auth::refresh();
        $this->forgetUser();

        self::assertNull(Auth::user());
    }

    public function testSigningOutEverywhereElseKeepsThisBrowser(): void
    {
        $this->signedIn(time() - 60);
        self::assertNotNull(Auth::user());

        Auth::endOtherSessions();
        $this->forgetUser();
        self::assertNotNull(Auth::user(), 'this one stays');

        // Another browser, signed in before it.
        $_SESSION['_signed_in_at'] = time() - 60;
        $this->forgetUser();
        self::assertNull(Auth::user());
    }

    public function testASessionFromBeforeTheStampIsFineUntilSomebodySignsOutEverywhere(): void
    {
        // Signed in before sessions remembered when: nothing to compare yet.
        $_SESSION = ['_user_id' => $this->me];
        self::assertNotNull(Auth::user());

        (new UserRepository($this->db))->endSessions($this->me);
        $this->forgetUser();
        self::assertNull(Auth::user());
    }

    private function signedIn(int $at): void
    {
        $_SESSION = ['_user_id' => $this->me, '_signed_in_at' => $at];
        $this->forgetUser();
    }

    /** What was read this request, gone — as on the next click. */
    private function forgetUser(): void
    {
        Auth::actAs(null);
    }
}
