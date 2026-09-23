<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Core\Recaptcha;
use PHPUnit\Framework\TestCase;

final class RecaptchaTest extends TestCase
{
    private const PERSON = ['success' => true, 'action' => 'login', 'hostname' => 'cantotrack.levente.net', 'score' => 0.9];

    public function testAPersonSigningInOnThisSitePasses(): void
    {
        self::assertTrue(Recaptcha::judge(self::PERSON, 'login', 'cantotrack.levente.net', 0.5));
    }

    public function testAScriptIsRefusedByItsScore(): void
    {
        self::assertFalse(Recaptcha::judge(['score' => 0.1] + self::PERSON, 'login', 'cantotrack.levente.net', 0.5));
    }

    public function testATokenMadeForAnotherFormOrSiteIsRefused(): void
    {
        self::assertFalse(Recaptcha::judge(self::PERSON, 'password_reset', 'cantotrack.levente.net', 0.5));
        self::assertFalse(Recaptcha::judge(['hostname' => 'elsewhere.example'] + self::PERSON, 'login', 'cantotrack.levente.net', 0.5));
        self::assertFalse(Recaptcha::judge(['success' => false] + self::PERSON, 'login', 'cantotrack.levente.net', 0.5));
    }

    public function testWithoutKeysTheCheckIsOff(): void
    {
        // The test configuration has no keys: nothing is asked of Google.
        self::assertFalse(Recaptcha::enabled());
        self::assertTrue(Recaptcha::verify('', 'login'));
    }
}
