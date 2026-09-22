<?php

namespace CantoTrack\Core;

/**
 * The token every form carries, checked for every post in one place.
 *
 * One token per session rather than one per form: a tracker is a tab people
 * leave open for hours with several forms on the page, and a per-form token
 * turns every such page into a "your session expired" the moment it is used
 * twice. The session lifetime is the limit either way.
 */
class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::put(self::KEY, $token);
        }

        return $token;
    }

    public static function validate(?string $given): bool
    {
        $token = Session::get(self::KEY);

        // hash_equals rather than ===: a comparison that stops at the first
        // wrong byte says how much of the token was right.
        return is_string($token) && is_string($given) && $given !== '' && hash_equals($token, $given);
    }
}
