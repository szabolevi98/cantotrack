<?php

/**
 * The routing table, read top to bottom.
 *
 * Included by web/index.php with `$router` in scope. Within a group the fixed
 * paths come before the ones with a parameter: "create" would otherwise match
 * as an id, and the form would answer "there is no such project".
 *
 * @var \CantoTrack\Core\Router $router
 */

use CantoTrack\Controller\CommentController;
use CantoTrack\Controller\DashboardController;
use CantoTrack\Controller\EpicController;
use CantoTrack\Controller\LoginController;
use CantoTrack\Controller\PeopleController;
use CantoTrack\Controller\ProfileController;
use CantoTrack\Controller\ProjectController;
use CantoTrack\Controller\TicketController;
use CantoTrack\Controller\TimesheetController;
use CantoTrack\Controller\WorklogController;

// ---------------------------------------------------------------------------
// Signing in and out
// ---------------------------------------------------------------------------
$router->get('/login', static fn() => (new LoginController())->show());
$router->post('/login', static fn() => (new LoginController())->submit());
// Signing out is a post: a GET that ends a session is one any page on the
// internet can trigger with an <img> pointing at it.
$router->post('/logout', static fn() => (new LoginController())->logout());

// ---------------------------------------------------------------------------
// The application
// ---------------------------------------------------------------------------
$router->get('/', static fn() => (new DashboardController())->index());

$router->get('/profile', static fn() => (new ProfileController())->show());
$router->post('/profile', static fn() => (new ProfileController())->update());
$router->post('/profile/password', static fn() => (new ProfileController())->changePassword());

// ---------------------------------------------------------------------------
// Projects and the epics inside them
// ---------------------------------------------------------------------------
$router->get('/projects', static fn() => (new ProjectController())->index());
$router->get('/projects/create', static fn() => (new ProjectController())->createForm());
$router->post('/projects/create', static fn() => (new ProjectController())->create());
$router->get('/projects/{id}', static fn($id) => (new ProjectController())->show((int) $id));
$router->get('/projects/{id}/edit', static fn($id) => (new ProjectController())->editForm((int) $id));
$router->get('/projects/{id}/options', static fn($id) => (new ProjectController())->options((int) $id));
$router->post('/projects/{id}', static fn($id) => (new ProjectController())->update((int) $id));
$router->post('/projects/{id}/delete', static fn($id) => (new ProjectController())->delete((int) $id));

// A project's columns, from its settings page.
$router->post('/projects/{id}/statuses', static fn($id) => (new ProjectController())->createStatus((int) $id));
$router->post('/statuses/{id}', static fn($id) => (new ProjectController())->updateStatus((int) $id));
$router->post('/statuses/{id}/move', static fn($id) => (new ProjectController())->moveStatus((int) $id));
$router->post('/statuses/{id}/delete', static fn($id) => (new ProjectController())->deleteStatus((int) $id));

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

// A ticket by its name, which is where "CT-14" in a comment links to.
$router->get('/t/{key}', static fn($key) => (new TicketController())->byKey((string) $key));

$router->post('/tickets/{id}/comments', static fn($id) => (new CommentController())->create((int) $id));
$router->post('/comments/{id}', static fn($id) => (new CommentController())->update((int) $id));
$router->post('/comments/{id}/delete', static fn($id) => (new CommentController())->delete((int) $id));

// ---------------------------------------------------------------------------
// The hours
// ---------------------------------------------------------------------------
$router->post('/tickets/{id}/log', static fn($id) => (new WorklogController())->create((int) $id));
$router->post('/worklogs/{id}', static fn($id) => (new WorklogController())->update((int) $id));
$router->post('/worklogs/{id}/delete', static fn($id) => (new WorklogController())->delete((int) $id));

$router->get('/timesheet', static fn() => (new TimesheetController())->index());

// ---------------------------------------------------------------------------
// The people, for administrators
// ---------------------------------------------------------------------------
$router->get('/people', static fn() => (new PeopleController())->index());
$router->get('/people/create', static fn() => (new PeopleController())->createForm());
$router->post('/people/create', static fn() => (new PeopleController())->create());
$router->get('/people/{id}/edit', static fn($id) => (new PeopleController())->editForm((int) $id));
$router->post('/people/{id}', static fn($id) => (new PeopleController())->update((int) $id));
$router->post('/people/{id}/password', static fn($id) => (new PeopleController())->resetPassword((int) $id));
