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
    /** How often the cookie's expiry is moved forward, in seconds. */
    private const REFRESH_EVERY = 300;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = Config::int('session.lifetime', 28800);

        /*
         * The session files live in the application's own var/sessions, with
         * their own clean-up.
         *
         * The configured lifetime used to be a promise only the cookie kept.
         * PHP's default store is shared by every site on the server, and on
         * Debian and Ubuntu a cron job empties it of anything idle for longer
         * than php.ini's gc_maxlifetime — 24 minutes — whatever an application
         * sets for itself at run time, because the job reads php.ini and not
         * us. So "eight hours" meant: until a coffee break. A folder of our own
         * is one that job never looks in, and the collection that does run on
         * it uses this application's lifetime.
         */
        $savePath = dirname(__DIR__, 2) . '/var/sessions';

        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }

        ini_set('session.gc_maxlifetime', (string) $lifetime);

        // An id the server did not hand out is refused rather than adopted,
        // which is the other half of the protection regenerate() gives.
        ini_set('session.use_strict_mode', '1');

        session_name((string) Config::get('session.name', 'cantotrack_session'));
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            // Only over HTTPS when the site is served over HTTPS. Hard-coding
            // this to true would lock development out entirely.
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        self::keepAlive($lifetime);
    }

    /**
     * Moves the cookie's expiry forward while the session is in use, so that
     * the lifetime counts from the last click rather than from the sign-in.
     * PHP sends the cookie once, when the session starts; without this, a
     * tracker left open all day signed its user out mid-afternoon however busy
     * they were.
     */
    private static function keepAlive(int $lifetime): void
    {
        $last = (int) ($_SESSION['_cookie_sent_at'] ?? 0);

        $name = session_name();

        if ($name === false || !isset($_COOKIE[$name]) || time() - $last < self::REFRESH_EVERY) {
            return;
        }

        $params = session_get_cookie_params();
        setcookie($name, (string) session_id(), [
            'expires' => time() + $lifetime,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);

        $_SESSION['_cookie_sent_at'] = time();
    }

    public static function isHttps(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
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
        $name = session_name();

        if (ini_get('session.use_cookies') && $name !== false) {
            $params = session_get_cookie_params();
            setcookie($name, '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        session_destroy();
    }
}
