<?php

namespace CantoTrack\Service;

use CantoTrack\Core\I18n;
use CantoTrack\Model\SettingRepository;

/**
 * Amounts of money: the currency the team bills in (a setting), a rate as
 * somebody types it, and an amount as it is shown.
 */
final class Money
{
    public const SETTING = 'currency';

    /** The currencies on offer, and how each is written: before or after, and with how many decimals. */
    public const CURRENCIES = [
        'EUR' => ['€', true, 2],
        'HUF' => ['Ft', false, 0],
        'USD' => ['$', true, 2],
        'GBP' => ['£', true, 2],
        'CHF' => ['CHF', false, 2],
    ];

    public static function currency(): string
    {
        $chosen = (string) ((new SettingRepository())->get(self::SETTING) ?? 'EUR');

        return isset(self::CURRENCIES[$chosen]) ? $chosen : 'EUR';
    }

    /**
     * A rate or an amount as typed — "12.50", "12,5", "12 500" — or null for
     * none. Something that is not a number is refused, not read as none.
     *
     * @throws \CantoTrack\Core\ValidationError
     */
    public static function parse(string $typed): ?float
    {
        $typed = str_replace([' ', "\u{a0}"], '', trim($typed));

        if ($typed === '') {
            return null;
        }

        $typed = str_replace(',', '.', $typed);

        if (preg_match('/^\d+(\.\d{1,2})?$/', $typed) !== 1) {
            throw new \CantoTrack\Core\ValidationError(__('“{value}” is not an amount, such as 45 or 12.50.', ['value' => $typed]));
        }

        return (float) $typed;
    }

    /** An amount the way the currency and the page's language write it: €1,250.00, 1 250 Ft. */
    public static function format(float $amount, ?string $currency = null, ?string $locale = null): string
    {
        $currency ??= self::currency();
        [$symbol, $before, $decimals] = self::CURRENCIES[$currency] ?? self::CURRENCIES['EUR'];
        $hungarian = ($locale ?? I18n::locale()) === 'hu';

        $number = number_format($amount, $decimals, $hungarian ? ',' : '.', $hungarian ? "\u{a0}" : ',');

        return $before ? $symbol . $number : $number . "\u{a0}" . $symbol;
    }
}
