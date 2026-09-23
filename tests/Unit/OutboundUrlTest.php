<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Core\OutboundUrl;
use CantoTrack\Core\ValidationError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutboundUrlTest extends TestCase
{
    /** @return iterable<array{string}> */
    public static function inside(): iterable
    {
        yield ['127.0.0.1'];
        yield ['10.1.2.3'];
        yield ['172.16.0.9'];
        yield ['192.168.1.1'];
        yield ['169.254.169.254'];
        yield ['100.64.0.1'];
        yield ['0.0.0.0'];
        yield ['::1'];
        yield ['fd00::1'];
        yield ['fe80::1'];
        yield ['::ffff:127.0.0.1'];
    }

    #[DataProvider('inside')]
    public function testAddressesInsideTheNetworkAreNotPublic(string $ip): void
    {
        self::assertFalse(OutboundUrl::isPublic($ip));
    }

    public function testPublicAddressesArePublic(): void
    {
        self::assertTrue(OutboundUrl::isPublic('8.8.8.8'));
        self::assertTrue(OutboundUrl::isPublic('2606:4700:4700::1111'));
    }

    public function testAPrivateAddressIsRefusedAsAWebhook(): void
    {
        $this->expectException(ValidationError::class);
        OutboundUrl::check('http://169.254.169.254/latest/meta-data');
    }

    public function testOnlyHttpIsSpoken(): void
    {
        $this->expectException(ValidationError::class);
        OutboundUrl::check('gopher://8.8.8.8/');
    }

    public function testAPasswordInTheAddressIsRefused(): void
    {
        $this->expectException(ValidationError::class);
        OutboundUrl::check('https://user:secret@8.8.8.8/');
    }

    public function testAPublicAddressIsPinned(): void
    {
        self::assertSame(['host' => '8.8.8.8', 'port' => 8443, 'ip' => '8.8.8.8'], OutboundUrl::check('https://8.8.8.8:8443/hook'));
    }
}
