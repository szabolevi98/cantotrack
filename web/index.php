<?php

/**
 * The front controller: everything that is not a real file on disk arrives here.
 *
 * The order below matters and is deliberate — configuration, then error
 * handling, then the session, then the one CSRF gate, and only then the routes.
 */

use CantoTrack\Controller\DashboardController;
use CantoTrack\Controller\LoginController;
use CantoTrack\Core\Config;
use CantoTrack\Core\Csrf;
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

set_exception_handler(static function (\Throwable $e): void {
    Logger::error('Uncaught: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);

    http_response_code(500);
    echo 'Something went wrong. It has been written to the log.';
});

/*
 * No page of this application has any business inside somebody else's frame.
 * Framed, every signed-in page is a clickjacking surface: through a transparent
 * frame a colleague would delete, approve or reassign in their own name while
 * believing they clicked something else.
 */
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

Session::start();

/*
 * One CSRF gate for every post, rather than a check in each controller. A check
 * that has to be remembered in thirty places is a check that is missing in one
 * of them, and that one is the form that deletes something.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !Csrf::validate($_POST['_token'] ?? null)) {
    // 403 rather than one of the codes a framework invented for this: an
    // unknown status code is answered by Apache with a 500 of its own, which
    // turns "your session expired" into "something is broken".
    http_response_code(403);
    exit('Your session expired. Go back, reload the page and try again.');
}

$router = new Router();

// ---------------------------------------------------------------------------
// Signing in
// ---------------------------------------------------------------------------
$router->get('/login', static fn() => (new LoginController())->show());
$router->post('/login', static fn() => (new LoginController())->submit());
$router->get('/logout', static fn() => (new LoginController())->logout());

// ---------------------------------------------------------------------------
// The application
// ---------------------------------------------------------------------------
$router->get('/', static fn() => (new DashboardController())->index());

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
