<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Service\NotifySettings;
use PHPUnit\Framework\TestCase;

final class NotifySettingsTest extends TestCase
{
    public function testNobodyWhoNeverChoseMissesAnything(): void
    {
        self::assertSame(array_fill_keys(NotifySettings::KINDS, 'email'), NotifySettings::of(['notify_prefs' => null, 'notify_email' => 1]));
    }

    public function testEmailTurnedOffBeforeThereWasAChoiceMeansHereOnly(): void
    {
        self::assertSame('app', NotifySettings::way(['notify_prefs' => null, 'notify_email' => 0], 'watching', 'status'));
    }

    public function testEachNotificationHasItsKind(): void
    {
        self::assertSame('assigned', NotifySettings::kindOf('assigned', 'changed'));
        self::assertSame('mentioned', NotifySettings::kindOf('mentioned', 'commented'));
        self::assertSame('status', NotifySettings::kindOf('watching', 'status'));
        self::assertSame('commented', NotifySettings::kindOf('watching', 'commented'));
        self::assertSame('changes', NotifySettings::kindOf('watching', 'attached'));
    }

    public function testOnlyKnownWaysAreKept(): void
    {
        $kept = json_decode(NotifySettings::encode(['status' => 'off', 'commented' => 'loudly']), true);

        self::assertSame('off', $kept['status']);
        self::assertSame('email', $kept['commented']);
        self::assertSame(NotifySettings::KINDS, array_keys($kept));
    }
}
