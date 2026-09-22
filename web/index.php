<?php

/**
 * The front controller: everything that is not a real file on disk arrives here.
 *
 * The order below matters and is deliberate — configuration, then error
 * handling, then the session, then the one CSRF gate, and only then the routes.
 */

use CantoTrack\Controller\DashboardController;
use CantoTrack\Controller\EpicController;
use CantoTrack\Controller\LoginController;
use CantoTrack\Controller\ProjectController;
use CantoTrack\Controller\TicketController;
use CantoTrack\Controller\TimesheetController;
use CantoTrack\Controller\WorklogController;
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

// ---------------------------------------------------------------------------
// Projects and the epics inside them
//
// The create routes come before the {id} ones: "create" would otherwise match
// as an id, and the form would answer "there is no such project".
// ---------------------------------------------------------------------------
$router->get('/projects', static fn() => (new ProjectController())->index());
$router->get('/projects/create', static fn() => (new ProjectController())->createForm());
$router->post('/projects/create', static fn() => (new ProjectController())->create());
$router->get('/projects/{id}', static fn($id) => (new ProjectController())->show((int) $id));
$router->get('/projects/{id}/edit', static fn($id) => (new ProjectController())->editForm((int) $id));
$router->post('/projects/{id}', static fn($id) => (new ProjectController())->update((int) $id));
$router->post('/projects/{id}/delete', static fn($id) => (new ProjectController())->delete((int) $id));

$router->get('/projects/{id}/epics/create', static fn($id) => (new EpicController())->createForm((int) $id));
$router->post('/projects/{id}/epics/create', static fn($id) => (new EpicController())->create((int) $id));
$router->get('/epics/{id}', static fn($id) => (new EpicController())->show((int) $id));
$router->get('/epics/{id}/edit', static fn($id) => (new EpicController())->editForm((int) $id));
$router->post('/epics/{id}', static fn($id) => (new EpicController())->update((int) $id));
$router->post('/epics/{id}/delete', static fn($id) => (new EpicController())->delete((int) $id));

// ---------------------------------------------------------------------------
// Tickets
// ---------------------------------------------------------------------------
$router->get('/tickets', static fn() => (new TicketController())->index());
$router->get('/tickets/create', static fn() => (new TicketController())->createForm());
$router->post('/tickets/create', static fn() => (new TicketController())->create());
$router->get('/tickets/{id}', static fn($id) => (new TicketController())->show((int) $id));
$router->get('/tickets/{id}/edit', static fn($id) => (new TicketController())->editForm((int) $id));
$router->post('/tickets/{id}', static fn($id) => (new TicketController())->update((int) $id));
$router->post('/tickets/{id}/status', static fn($id) => (new TicketController())->changeStatus((int) $id));
$router->post('/tickets/{id}/delete', static fn($id) => (new TicketController())->delete((int) $id));

// ---------------------------------------------------------------------------
// The hours
// ---------------------------------------------------------------------------
$router->post('/tickets/{id}/log', static fn($id) => (new WorklogController())->create((int) $id));
$router->post('/worklogs/{id}', static fn($id) => (new WorklogController())->update((int) $id));
$router->post('/worklogs/{id}/delete', static fn($id) => (new WorklogController())->delete((int) $id));

$router->get('/timesheet', static fn() => (new TimesheetController())->index());

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
