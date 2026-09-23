<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Core\ClientIp;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    public function testMatchesIpv4Ranges(): void
    {
        self::assertTrue(ClientIp::inRange('173.245.48.10', '173.245.48.0/20'));
        self::assertTrue(ClientIp::inRange('173.245.63.255', '173.245.48.0/20'));
        self::assertFalse(ClientIp::inRange('173.245.64.0', '173.245.48.0/20'));
        self::assertTrue(ClientIp::inRange('10.0.0.1', '10.0.0.1'));
        self::assertFalse(ClientIp::inRange('10.0.0.2', '10.0.0.1'));
    }

    public function testMatchesIpv6Ranges(): void
    {
        self::assertTrue(ClientIp::inRange('2606:4700:10::1', '2606:4700::/32'));
        self::assertFalse(ClientIp::inRange('2607:4700::1', '2606:4700::/32'));
    }

    public function testNeverMatchesAcrossFamilies(): void
    {
        self::assertFalse(ClientIp::inRange('127.0.0.1', '::1/128'));
        self::assertFalse(ClientIp::inRange('not an address', '10.0.0.0/8'));
    }
}
