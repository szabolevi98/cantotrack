<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Core\Password;
use PHPUnit\Framework\TestCase;

final class PasswordTest extends TestCase
{
    public function testAHashIsCheckedAgainstItsPassword(): void
    {
        $hash = Password::hash('correct horse battery staple');

        self::assertTrue(Password::verify('correct horse battery staple', $hash));
        self::assertFalse(Password::verify('wrong', $hash));
        self::assertFalse(Password::needsRehash($hash));
    }

    public function testNoAccountIsNeverAMatch(): void
    {
        self::assertFalse(Password::verify('anything', null));
        self::assertFalse(Password::verify('', null));
    }

    /**
     * The whole point of the stand-in: an address without an account has to
     * cost as much to refuse as a wrong password on a real one. The first
     * version was not a real hash and answered four times slower.
     */
    public function testAnUnknownAddressTakesAsLongAsAWrongPassword(): void
    {
        $real = Password::hash('something');

        $time = static function (?string $hash): float {
            $start = hrtime(true);
            for ($i = 0; $i < 3; $i++) {
                Password::verify('wrong', $hash);
            }

            return (hrtime(true) - $start) / 3;
        };

        $time($real); // warm up
        $known = $time($real);
        $unknown = $time(null);

        self::assertLessThan(
            1.5,
            max($known, $unknown) / min($known, $unknown),
            sprintf('known %.1f ms, unknown %.1f ms', $known / 1e6, $unknown / 1e6)
        );
    }

    public function testAWeakerHashIsMarkedForRehashing(): void
    {
        $old = password_hash('pw', PASSWORD_BCRYPT, ['cost' => 10]);

        self::assertTrue(Password::needsRehash($old));
    }

    public function testNewPasswordsHaveAFloor(): void
    {
        self::assertNotNull(Password::problem('short', 'me@example.test'));
        self::assertNotNull(Password::problem('me@example.test', 'ME@example.test'));
        self::assertNull(Password::problem('correct horse battery staple', 'me@example.test'));
    }
}
