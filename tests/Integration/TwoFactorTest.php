<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\Totp;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\TwoFactor;

final class TwoFactorTest extends DatabaseTestCase
{
    public function testAWrongFirstCodeLeavesItOff(): void
    {
        $me = $this->person();
        $service = new TwoFactor($this->db);

        self::assertNull($service->enable($me, Totp::secret(), '000000'));
        self::assertFalse(TwoFactor::isOn((array) (new UserRepository($this->db))->find($me)));
    }

    public function testOnWithAProvenCodeAndEachCodeWorksOnce(): void
    {
        $me = $this->person();
        $service = new TwoFactor($this->db);
        $secret = Totp::secret();

        // The code of the step before: the one after enabling is still to come.
        // Worked out once, so a step that ends mid-test does not make it another code.
        $code = Totp::code($secret, Totp::step() - 1);
        $codes = $service->enable($me, $secret, $code);
        self::assertIsArray($codes);
        self::assertCount(TwoFactor::RECOVERY_CODES, $codes);

        $user = (array) (new UserRepository($this->db))->find($me);
        self::assertTrue(TwoFactor::isOn($user));

        // The same code that turned it on does not sign anybody in.
        self::assertFalse($service->check($user, $code));

        // A recovery code works once, typed with or without its dash.
        self::assertTrue($service->check($user, strtoupper(str_replace('-', '', $codes[0]))));
        self::assertFalse($service->check($user, $codes[0]));
        self::assertSame(TwoFactor::RECOVERY_CODES - 1, $service->recoveryCodesLeft($me));

        $service->disable($me);
        self::assertFalse(TwoFactor::isOn((array) (new UserRepository($this->db))->find($me)));
        self::assertSame(0, $service->recoveryCodesLeft($me));
    }
}
