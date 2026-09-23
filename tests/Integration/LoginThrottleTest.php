<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\LoginThrottle;

final class LoginThrottleTest extends DatabaseTestCase
{
    public function testAnAddressIsBlockedAfterFiveFailures(): void
    {
        $throttle = new LoginThrottle($this->db);

        for ($i = 0; $i < LoginThrottle::PER_EMAIL - 1; $i++) {
            $throttle->recordFailure('Anna@Example.test', '10.0.0.1');
        }
        self::assertFalse($throttle->isBlocked('anna@example.test', '10.0.0.2'));

        $throttle->recordFailure('anna@example.test', '10.0.0.3');
        self::assertTrue($throttle->isBlocked('anna@example.test', '10.0.0.2'), 'from any connection');
        self::assertFalse($throttle->isBlocked('mark@example.test', '10.0.0.2'), 'but not other addresses');
    }

    public function testAConnectionIsBlockedAfterThirtyFailures(): void
    {
        $throttle = new LoginThrottle($this->db);

        for ($i = 0; $i < LoginThrottle::PER_IP; $i++) {
            $throttle->recordFailure('guess' . $i . '@example.test', '10.0.0.9');
        }

        self::assertTrue($throttle->isBlocked('somebody.new@example.test', '10.0.0.9'));
        self::assertFalse($throttle->isBlocked('somebody.new@example.test', '10.0.0.10'));
    }

    public function testASuccessClearsTheAddress(): void
    {
        $throttle = new LoginThrottle($this->db);

        for ($i = 0; $i < LoginThrottle::PER_EMAIL; $i++) {
            $throttle->recordFailure('anna@example.test', '10.0.0.1');
        }

        $throttle->clear('anna@example.test');

        self::assertFalse($throttle->isBlocked('anna@example.test', '10.0.0.1'));
    }
}
