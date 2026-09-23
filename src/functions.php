<?php

/*
 * The two functions every other file uses to put words on the screen. Global,
 * and short, because they are called in every controller and a flash message
 * wrapped in `I18n::translate()` is one people stop wrapping.
 */

use CantoTrack\Core\I18n;

if (!function_exists('__')) {
    /** @param array<string, scalar|null> $params */
    function __(string $text, array $params = []): string
    {
        return I18n::translate($text, $params);
    }
}

if (!function_exists('__n')) {
    /** @param array<string, scalar|null> $params */
    function __n(string $one, string $many, int $count, array $params = []): string
    {
        return I18n::plural($one, $many, $count, $params);
    }
}
