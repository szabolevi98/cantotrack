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
        $user = (new UserRepository())->findByEmail($email);

        // The hash is verified even when there is no such user, so that a wrong
        // address and a wrong password take the same time to answer. Otherwise
        // the login form says which addresses exist.
        $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';

        if (!password_verify($password, $hash) || $user === null || (int) $user['is_active'] !== 1) {
            return false;
        }

        Session::regenerate();
        Session::put(self::KEY, (int) $user['id']);
        self::$user = $user;

        (new UserRepository())->touchLastLogin((int) $user['id']);

        return true;
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

        return self::$user = $user;
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
        Session::put('_intended', Router::normalise($_SERVER['REQUEST_URI'] ?? '/'));

        header('Location: ' . Config::get('app.base_url') . '/login');
        exit;
    }

    /** As above, and then refuses anyone who is not an admin. */
    public static function requireAdmin(): void
    {
        self::require();

        if (!self::isAdmin()) {
            http_response_code(403);
            exit('This page is for administrators.');
        }
    }
}
