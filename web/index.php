<?php

/**
 * The front controller: everything that is not a real file on disk arrives here.
 *
 * The order below matters and is deliberate — configuration, then error
 * handling, then the session, then the one CSRF gate, and only then the routes.
 * The routes themselves are in src/routes.php.
 */

use CantoTrack\Core\Config;
use CantoTrack\Core\Csrf;
use CantoTrack\Core\ErrorPage;
use CantoTrack\Core\HttpError;
use CantoTrack\Core\Logger;
use CantoTrack\Core\Router;
use CantoTrack\Core\Session;

require dirname(__DIR__) . '/vendor/autoload.php';

Config::load(dirname(__DIR__) . '/config/config.ini');
date_default_timezone_set((string) Config::get('app.timezone', 'Europe/Budapest'));

/*
 * A PHP notice must never reach the response. It would break a redirect (the
 * headers are already sent by then), corrupt any file this application hands
 * back, and on a live server it prints paths and variable names to whoever
 * asked for the page. Everything goes to var/log instead.
 */
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
    Logger::error($message, ['file' => $file, 'line' => $line]);

    return true;
});

/*
 * One place every failure is answered from. An HttpError is a request that
 * asked for something that is not there or not theirs — expected, and not
 * logged. Anything else is a fault, and goes to the log with where it happened.
 */
set_exception_handler(static function (\Throwable $e): void {
    if ($e instanceof HttpError) {
        ErrorPage::render($e->status, $e->getMessage());

        return;
    }

    Logger::error('Uncaught: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'path' => $_SERVER['REQUEST_URI'] ?? '',
    ]);

    ErrorPage::render(500, __('Something went wrong. It has been written to the log.'));
});

/*
 * No page of this application has any business inside somebody else's frame,
 * and none runs a script it did not ship itself.
 *
 * `script-src 'self'` is the one that matters most: there is not a single
 * inline script or event handler in the markup, so text that somebody manages
 * to get onto a page — a ticket title, a comment, a name — cannot run even if
 * it slipped past the escaping. Styles allow inline attributes, because the
 * progress bars are a width, and a width is not an attack.
 */
header('X-Frame-Options: DENY');
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; form-action 'self'; "
    . "base-uri 'self'; object-src 'none'; frame-ancestors 'none'"
);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

Session::start();

/*
 * One CSRF gate for every post, rather than a check in each controller. A check
 * that has to be remembered in thirty places is a check that is missing in one
 * of them, and that one is the form that deletes something.
 *
 * The token comes in the form, or — for the few requests the page's own
 * script sends — in a header. The API and the incoming integrations are the
 * exceptions: they carry no session cookie at all, and prove who they are with
 * a token or a signature of their own, which is checked where they arrive.
 */
$path = Router::normalise($_SERVER['REQUEST_URI'] ?? '/');
$exempt = str_starts_with($path, '/api/') || str_starts_with($path, '/integrations/');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$exempt) {
    $given = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (!Csrf::validate(is_string($given) ? $given : null)) {
        // 403 rather than one of the codes a framework invented for this: an
        // unknown status code is answered by Apache with a 500 of its own,
        // which turns "your session expired" into "something is broken".
        throw HttpError::forbidden(__('Your session expired. Go back, reload the page and try again.'));
    }
}

$router = new Router();
require dirname(__DIR__) . '/src/routes.php';

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
