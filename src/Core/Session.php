<?php

namespace CantoTrack\Core;

/**
 * The session, started with the cookie settings this application wants rather
 * than whatever php.ini happens to say.
 *
 * `HttpOnly` and `SameSite=Lax` are the two that matter here: the first keeps
 * the session cookie away from any script that gets onto a page, the second
 * keeps it off requests that another site starts.
 */
class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = Config::int('session.lifetime', 28800);

        session_name((string) Config::get('session.name', 'cantotrack_session'));
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            // Only over HTTPS when the site is served over HTTPS. Hard-coding
            // this to true would lock development out entirely.
            'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * A message for the next page, shown once and then gone. Used for "saved",
     * "deleted" and the like, which belong to the redirect that follows a post
     * rather than to the post itself.
     */
    public static function flash(string $message, string $kind = 'success'): void
    {
        $_SESSION['_flash'] = ['message' => $message, 'kind' => $kind];
    }

    public static function takeFlash(): ?array
    {
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        return $flash;
    }

    /**
     * Starts a new session id while keeping the contents. Called when somebody
     * logs in, so that a session id an attacker planted before the login cannot
     * be used after it.
     */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        // Emptying the array leaves the cookie in the browser, and with it a
        // session id that is still valid for whatever comes next.
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }
}
