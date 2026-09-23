<?php

namespace CantoTrack\Core;

use CantoTrack\Model\UserRepository;

/**
 * Who is signed in, and what they are allowed to do.
 *
 * Two roles, no more: an **admin** may set up projects and people, a **member**
 * works on tickets and logs time against them. A tracker this size does not earn
 * a permission matrix, and one that cannot be explained in a sentence is one
 * nobody configures correctly.
 */
class Auth
{
    private const KEY = '_user_id';

    private static ?array $user = null;

    /** Checks the password and signs the user in. */
    public static function attempt(string $email, string $password): bool
    {
        $user = self::verifyCredentials($email, $password);

        if ($user === null) {
            return false;
        }

        self::signIn($user);

        return true;
    }

    /**
     * The account an address and password belong to, or null — without
     * signing anybody in. Separate from signIn() because an account with a
     * second factor is not signed in until that has been checked too.
     */
    public static function verifyCredentials(string $email, string $password): ?array
    {
        $users = new UserRepository();
        $user = $users->findByEmail($email);

        // The hash is verified even when there is no such user (against a
        // stand-in of the same cost), so that a wrong address and a wrong
        // password take the same time to answer. Otherwise the login form says
        // which addresses exist.
        if (!Password::verify($password, $user['password_hash'] ?? null) || $user === null) {
            return null;
        }

        if ((int) $user['is_active'] !== 1) {
            return null;
        }

        // A hash made at a lower cost — before the cost was fixed, or on an
        // older PHP — is replaced now, while the password is known.
        if (Password::needsRehash((string) $user['password_hash'])) {
            $users->rehash((int) $user['id'], $password);
        }

        return $user;
    }

    public static function signIn(array $user): void
    {
        Session::regenerate();
        Session::put(self::KEY, (int) $user['id']);
        self::$user = $user;

        (new UserRepository())->touchLastLogin((int) $user['id']);
    }

    /**
     * Acts as a person for this one request, without a session: an API
     * request proves who it is with a token each time, and leaves nothing
     * behind that a browser could pick up.
     */
    public static function actAs(array $user): void
    {
        self::$user = $user;

        if (!empty($user['locale'])) {
            I18n::setLocale((string) $user['locale']);
        }
    }

    public static function logout(): void
    {
        self::$user = null;
        Session::destroy();
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** The signed-in user's row, or nothing. Read once per request. */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $id = Session::get(self::KEY);
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }

        $user = (new UserRepository())->find((int) $id);

        // A user who was deactivated while signed in is signed out on their next
        // click rather than at the next login.
        if ($user === null || (int) $user['is_active'] !== 1) {
            Session::forget(self::KEY);

            return null;
        }

        // Their language, from the first moment anything is translated.
        if (!empty($user['locale'])) {
            I18n::setLocale((string) $user['locale']);
        }

        return self::$user = $user;
    }

    /** Drops what was read this request, after the row changed underneath it. */
    public static function refresh(): void
    {
        self::$user = null;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    /** Sends anyone who is not signed in to the login page, and stops. */
    public static function require(): void
    {
        if (self::check()) {
            return;
        }

        // Where they were going, so the login can put them back there. Only the
        // path is kept: a full URL from the request would let a crafted link
        // bounce somebody to another site after a real login.
        // The query string too: a filtered list sent in a message is the URL
        // somebody wants to land on after signing in, not the unfiltered one.
        $query = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY);
        Session::put('_intended', Router::normalise($_SERVER['REQUEST_URI'] ?? '/') . ($query !== '' ? '?' . $query : ''));

        header('Location: ' . Config::get('app.base_url') . '/login');
        exit;
    }

    /** As above, and then refuses anyone who is not an admin. */
    public static function requireAdmin(): void
    {
        self::require();

        if (!self::isAdmin()) {
            throw HttpError::forbidden(__('This page is for administrators.'));
        }
    }
}
