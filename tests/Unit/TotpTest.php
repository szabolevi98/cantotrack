<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Core\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** The RFC 6238 test secret, "12345678901234567890", in base32. */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return iterable<array{int, string}> RFC 6238's SHA-1 vectors, cut to six digits */
    public static function vectors(): iterable
    {
        yield [59, '287082'];
        yield [1111111109, '081804'];
        yield [1111111111, '050471'];
        yield [1234567890, '005924'];
        yield [2000000000, '279037'];
    }

    #[DataProvider('vectors')]
    public function testTheCodesAreTheOnesTheRfcGives(int $time, string $code): void
    {
        self::assertSame($code, Totp::code(self::SECRET, Totp::step($time)));
    }

    public function testBase32GoesBothWays(): void
    {
        self::assertSame('12345678901234567890', Totp::unbase32(self::SECRET));
        self::assertSame(self::SECRET, Totp::base32('12345678901234567890'));

        $secret = Totp::secret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertSame(20, strlen(Totp::unbase32($secret)));
    }

    public function testACodeFromTheStepBeforeOrAfterIsTakenButNotOlder(): void
    {
        $time = 1234567890;
        $step = Totp::step($time);

        self::assertSame($step - 1, Totp::verify(self::SECRET, Totp::code(self::SECRET, $step - 1), null, $time));
        self::assertSame($step + 1, Totp::verify(self::SECRET, Totp::code(self::SECRET, $step + 1), null, $time));
        self::assertNull(Totp::verify(self::SECRET, Totp::code(self::SECRET, $step - 2), null, $time));
    }

    public function testACodeCannotBeUsedTwice(): void
    {
        $time = 1234567890;
        $code = Totp::code(self::SECRET, Totp::step($time));
        $used = Totp::verify(self::SECRET, $code, null, $time);

        self::assertNotNull($used);
        self::assertNull(Totp::verify(self::SECRET, $code, $used, $time));
    }

    public function testNonsenseIsRefused(): void
    {
        self::assertNull(Totp::verify(self::SECRET, 'abcdef'));
        self::assertNull(Totp::verify(self::SECRET, '12345'));
    }

    public function testTheAddressCarriesTheIssuerAndAccount(): void
    {
        self::assertSame(
            'otpauth://totp/CantoTrack%3Aanna%40example.test?secret=' . self::SECRET . '&issuer=CantoTrack&digits=6&period=30',
            Totp::uri('CantoTrack', 'anna@example.test', self::SECRET)
        );
    }
}
