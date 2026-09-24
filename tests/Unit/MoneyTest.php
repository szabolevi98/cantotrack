<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Core\ValidationError;
use CantoTrack\Service\Budget;
use CantoTrack\Service\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testARateReadsTheWayPeopleTypeIt(): void
    {
        self::assertSame(12.5, Money::parse('12,50'));
        self::assertSame(12500.0, Money::parse('12 500'));
        self::assertSame(45.0, Money::parse('45'));
        self::assertNull(Money::parse(''));

        $this->expectException(ValidationError::class);
        Money::parse('lots');
    }

    public function testAnAmountIsWrittenInItsCurrencyAndTheLanguageOfThePage(): void
    {
        self::assertSame('€1,250.50', Money::format(1250.5, 'EUR', 'en'));
        self::assertSame("1\u{a0}250\u{a0}Ft", Money::format(1250.4, 'HUF', 'hu'));
        self::assertSame("€1\u{a0}250,50", Money::format(1250.5, 'EUR', 'hu'));
    }

    public function testABudgetSaysHowMuchIsUsedAndWhenToWarn(): void
    {
        $half = Budget::shares(100.0, 50.0, null, 0.0, 80);
        self::assertSame(50, $half['hours_share']);
        self::assertNull($half['amount_share']);
        self::assertFalse($half['warn']);

        $near = Budget::shares(100.0, 10.0, 1000.0, 850.0, 80);
        self::assertTrue($near['warn'], 'the money is the nearer to running out');
        self::assertFalse($near['over']);

        self::assertTrue(Budget::shares(10.0, 12.0, null, 0.0, 80)['over']);
    }
}
