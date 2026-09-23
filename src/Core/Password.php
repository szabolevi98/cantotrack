<?php

namespace CantoTrack\Core;

/**
 * Hashing and checking passwords, with the cost written down rather than left
 * to the PHP version.
 *
 * PASSWORD_DEFAULT is bcrypt at cost 10 on PHP 8.2 and cost 12 on 8.4. Left to
 * the default, an installation's hashes change strength with its PHP upgrade,
 * and — the part that mattered — the stand-in hash used for an unknown address
 * stopped taking as long as a real one. A cost fixed here keeps both the same,
 * and hashes made at a lower one are upgraded at the next sign-in.
 */
class Password
{
    public const OPTIONS = ['cost' => 12];

    /** The shortest password somebody may choose for themselves. */
    public const MIN_LENGTH = 10;

    /**
     * A real bcrypt hash, at the same cost as every other, of a random string
     * nobody knows. It is verified against when an address has no account, so
     * that "no such address" and "wrong password" take the same time to say.
     *
     * The first version of this was a made-up string that only looked like a
     * hash. PHP did not recognise it, and answered four times slower than for
     * a real account — which told anybody with a stopwatch which addresses had
     * one.
     */
    private const STAND_IN = '$2y$12$txfXF9HY4WO2HmwJd6kApe5dlobgwn/SF.6AhxE2ZG7SIUWHa/cZG';

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, self::OPTIONS);
    }

    /** Checks a password against a hash, or against the stand-in when there is none. */
    public static function verify(string $password, ?string $hash): bool
    {
        $matches = password_verify($password, $hash ?? self::STAND_IN);

        return $hash !== null && $matches;
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, self::OPTIONS);
    }

    /** Why a new password will not do, or null when it will. */
    public static function problem(string $password, string $email): ?string
    {
        return match (true) {
            mb_strlen($password) < self::MIN_LENGTH =>
                __('A password needs at least {count} characters.', ['count' => self::MIN_LENGTH]),
            mb_strtolower($password) === mb_strtolower($email) => __('A password cannot be your email address.'),
            default => null,
        };
    }
}
