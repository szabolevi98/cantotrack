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

use CantoTrack\Controller\ApiController;
use CantoTrack\Controller\ApiTokenController;
use CantoTrack\Controller\AttachmentController;
use CantoTrack\Controller\AutomationController;
use CantoTrack\Controller\AvatarController;
use CantoTrack\Controller\CommentController;
use CantoTrack\Controller\DashboardController;
use CantoTrack\Controller\EpicController;
use CantoTrack\Controller\FieldController;
use CantoTrack\Controller\ImportController;
use CantoTrack\Controller\IntegrationController;
use CantoTrack\Controller\LinkController;
use CantoTrack\Controller\LocaleController;
use CantoTrack\Controller\LoginController;
use CantoTrack\Controller\NotificationController;
use CantoTrack\Controller\PageController;
use CantoTrack\Controller\PasswordResetController;
use CantoTrack\Controller\PeopleController;
use CantoTrack\Controller\PlanningController;
use CantoTrack\Controller\ProfileController;
use CantoTrack\Controller\ProjectController;
use CantoTrack\Controller\ReleaseController;
use CantoTrack\Controller\ReportController;
use CantoTrack\Controller\RoadmapController;
use CantoTrack\Controller\SearchController;
use CantoTrack\Controller\SettingsController;
use CantoTrack\Controller\SprintController;
use CantoTrack\Controller\TicketController;
use CantoTrack\Controller\TimerController;
use CantoTrack\Controller\TimesheetController;
use CantoTrack\Controller\TwoFactorController;
use CantoTrack\Controller\WebhookController;
use CantoTrack\Controller\WorklogController;
use CantoTrack\Controller\WorkTypeController;

// ---------------------------------------------------------------------------
// Signing in and out
// ---------------------------------------------------------------------------
$router->get('/login', static fn() => (new LoginController())->show());
$router->post('/login', static fn() => (new LoginController())->submit());
$router->get('/login/code', static fn() => (new LoginController())->showCode());
$router->post('/login/code', static fn() => (new LoginController())->submitCode());
// Signing out is a post: a GET that ends a session is one any page on the
// internet can trigger with an <img> pointing at it.
$router->post('/logout', static fn() => (new LoginController())->logout());
// The language, signed in or not — the sign-in page has the switch too.
$router->post('/locale', static fn() => (new LocaleController())->change());

$router->get('/password/forgot', static fn() => (new PasswordResetController())->form());
$router->post('/password/forgot', static fn() => (new PasswordResetController())->send());
$router->get('/password/reset/{token}', static fn($token) => (new PasswordResetController())->resetForm((string) $token));
$router->post('/password/reset/{token}', static fn($token) => (new PasswordResetController())->reset((string) $token));

// ---------------------------------------------------------------------------
// The application
// ---------------------------------------------------------------------------
$router->get('/', static fn() => (new DashboardController())->index());

$router->get('/notifications', static fn() => (new NotificationController())->index());
$router->post('/notifications/read', static fn() => (new NotificationController())->readAll());
$router->get('/notifications/{id}', static fn($id) => (new NotificationController())->open((int) $id));
$router->post('/tickets/{id}/subtasks', static fn($id) => (new TicketController())->addSubtask((int) $id));
$router->post('/tickets/{id}/favourite', static fn($id) => (new TicketController())->favourite((int) $id));
$router->post('/tickets/{id}/watch', static fn($id) => (new NotificationController())->toggleWatch((int) $id));

$router->get('/search', static fn() => (new SearchController())->search());
$router->post('/filters', static fn() => (new SearchController())->saveFilter());
$router->post('/filters/{id}/delete', static fn($id) => (new SearchController())->deleteFilter((int) $id));

$router->get('/profile', static fn() => (new ProfileController())->show());
$router->post('/profile', static fn() => (new ProfileController())->update());
$router->post('/profile/password', static fn() => (new ProfileController())->changePassword());
$router->post('/profile/theme', static fn() => (new ProfileController())->toggleTheme());
$router->post('/profile/calendar', static fn() => (new ProfileController())->calendarFeed());
$router->get('/profile/tokens', static fn() => (new ApiTokenController())->index());
$router->post('/profile/avatar', static fn() => (new AvatarController())->upload());
$router->post('/profile/avatar/delete', static fn() => (new AvatarController())->remove());
$router->get('/avatars/{id}/{file}', static fn($id, $file) => (new AvatarController())->show((int) $id, (string) $file));
$router->get('/profile/two-factor', static fn() => (new TwoFactorController())->show());
$router->post('/profile/two-factor/start', static fn() => (new TwoFactorController())->start());
$router->post('/profile/two-factor/confirm', static fn() => (new TwoFactorController())->confirm());
$router->post('/profile/two-factor/cancel', static fn() => (new TwoFactorController())->cancel());
$router->post('/profile/two-factor/disable', static fn() => (new TwoFactorController())->disable());
$router->post('/profile/two-factor/recovery', static fn() => (new TwoFactorController())->recoveryCodes());
$router->post('/profile/tokens', static fn() => (new ApiTokenController())->create());
$router->post('/profile/tokens/{id}/delete', static fn($id) => (new ApiTokenController())->revoke((int) $id));

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
$router->get('/projects/{id}/import', static fn($id) => (new ImportController())->form((int) $id));
$router->post('/projects/{id}/import', static fn($id) => (new ImportController())->upload((int) $id));
$router->get('/projects/{id}/import/preview', static fn($id) => (new ImportController())->preview((int) $id));
$router->post('/projects/{id}/import/confirm', static fn($id) => (new ImportController())->confirm((int) $id));
$router->post('/projects/{id}/import/cancel', static fn($id) => (new ImportController())->cancel((int) $id));
$router->post('/projects/{id}/members', static fn($id) => (new ProjectController())->addMember((int) $id));
$router->post('/projects/{id}/members/{user}/delete', static fn($id, $user) => (new ProjectController())->removeMember((int) $id, (int) $user));
$router->post('/projects/{id}/delete', static fn($id) => (new ProjectController())->delete((int) $id));

// Planning: the backlog, the sprints, and each sprint's report.
$router->get('/projects/{id}/backlog', static fn($id) => (new SprintController())->backlog((int) $id));
$router->post('/projects/{id}/sprints', static fn($id) => (new SprintController())->create((int) $id));
$router->get('/sprints/{id}', static fn($id) => (new SprintController())->show((int) $id));
$router->post('/sprints/{id}', static fn($id) => (new SprintController())->update((int) $id));
$router->post('/sprints/{id}/start', static fn($id) => (new SprintController())->start((int) $id));
$router->post('/sprints/{id}/close', static fn($id) => (new SprintController())->close((int) $id));
$router->post('/sprints/{id}/delete', static fn($id) => (new SprintController())->delete((int) $id));

// A project's columns, from its settings page.
$router->post('/projects/{id}/statuses', static fn($id) => (new ProjectController())->createStatus((int) $id));
$router->post('/statuses/{id}', static fn($id) => (new ProjectController())->updateStatus((int) $id));
$router->post('/statuses/{id}/move', static fn($id) => (new ProjectController())->moveStatus((int) $id));
$router->post('/statuses/{id}/delete', static fn($id) => (new ProjectController())->deleteStatus((int) $id));

$router->get('/projects/{id}/epics/create', static fn($id) => (new EpicController())->createForm((int) $id));
$router->post('/projects/{id}/epics/create', static fn($id) => (new EpicController())->create((int) $id));
$router->get('/epics/{id}', static fn($id) => (new EpicController())->show((int) $id));
$router->get('/roadmap', static fn() => (new RoadmapController())->all());
$router->get('/projects/{id}/roadmap', static fn($id) => (new RoadmapController())->project((int) $id));
$router->post('/epics/{id}/days', static fn($id) => (new EpicController())->moveDays((int) $id));
$router->get('/projects/{id}/fields', static fn($id) => (new FieldController())->index((int) $id));
$router->post('/projects/{id}/fields', static fn($id) => (new FieldController())->create((int) $id));
$router->post('/fields/{id}', static fn($id) => (new FieldController())->update((int) $id));
$router->post('/fields/{id}/move', static fn($id) => (new FieldController())->move((int) $id));
$router->post('/fields/{id}/delete', static fn($id) => (new FieldController())->delete((int) $id));
$router->get('/settings/automation', static fn() => (new AutomationController())->index());
$router->get('/settings/automation/create', static fn() => (new AutomationController())->createForm());
$router->post('/settings/automation', static fn() => (new AutomationController())->create());
$router->get('/settings/automation/{id}', static fn($id) => (new AutomationController())->editForm((int) $id));
$router->post('/settings/automation/{id}', static fn($id) => (new AutomationController())->update((int) $id));
$router->post('/settings/automation/{id}/toggle', static fn($id) => (new AutomationController())->toggle((int) $id));
$router->post('/settings/automation/{id}/delete', static fn($id) => (new AutomationController())->delete((int) $id));
$router->get('/projects/{id}/pages', static fn($id) => (new PageController())->index((int) $id));
$router->get('/projects/{id}/pages/create', static fn($id) => (new PageController())->createForm((int) $id));
$router->post('/projects/{id}/pages/create', static fn($id) => (new PageController())->create((int) $id));
$router->post('/projects/{id}/pages/preview', static fn($id) => (new PageController())->preview((int) $id));
$router->get('/pages/{id}', static fn($id) => (new PageController())->show((int) $id));
$router->get('/pages/{id}/edit', static fn($id) => (new PageController())->editForm((int) $id));
$router->post('/pages/{id}', static fn($id) => (new PageController())->update((int) $id));
$router->post('/pages/{id}/delete', static fn($id) => (new PageController())->delete((int) $id));
$router->get('/pages/{id}/history', static fn($id) => (new PageController())->history((int) $id));
$router->post('/pages/{id}/restore', static fn($id) => (new PageController())->restore((int) $id));
$router->post('/releases/{id}/page', static fn($id) => (new PageController())->fromRelease((int) $id));
$router->get('/projects/{id}/releases', static fn($id) => (new ReleaseController())->index((int) $id));
$router->post('/projects/{id}/releases', static fn($id) => (new ReleaseController())->create((int) $id));
$router->get('/releases/{id}', static fn($id) => (new ReleaseController())->show((int) $id));
$router->get('/releases/{id}/edit', static fn($id) => (new ReleaseController())->editForm((int) $id));
$router->post('/releases/{id}', static fn($id) => (new ReleaseController())->update((int) $id));
$router->post('/releases/{id}/release', static fn($id) => (new ReleaseController())->release((int) $id));
$router->post('/releases/{id}/unrelease', static fn($id) => (new ReleaseController())->unrelease((int) $id));
$router->post('/releases/{id}/delete', static fn($id) => (new ReleaseController())->delete((int) $id));
$router->get('/epics/{id}/edit', static fn($id) => (new EpicController())->editForm((int) $id));
$router->post('/epics/{id}', static fn($id) => (new EpicController())->update((int) $id));
$router->post('/epics/{id}/delete', static fn($id) => (new EpicController())->delete((int) $id));

// ---------------------------------------------------------------------------
// Tickets
// ---------------------------------------------------------------------------
$router->get('/tickets', static fn() => (new TicketController())->index());
$router->get('/tickets/create', static fn() => (new TicketController())->createForm());
$router->post('/tickets/create', static fn() => (new TicketController())->create());
$router->get('/tickets/query/values', static fn() => (new TicketController())->queryValues());
$router->post('/tickets/bulk', static fn() => (new TicketController())->bulk());
$router->get('/tickets/{id}', static fn($id) => (new TicketController())->show((int) $id));
$router->get('/tickets/{id}/edit', static fn($id) => (new TicketController())->editForm((int) $id));
$router->post('/tickets/{id}', static fn($id) => (new TicketController())->update((int) $id));
$router->post('/tickets/{id}/status', static fn($id) => (new TicketController())->changeStatus((int) $id));
$router->post('/tickets/{id}/move', static fn($id) => (new TicketController())->move((int) $id));
$router->post('/tickets/{id}/delete', static fn($id) => (new TicketController())->delete((int) $id));

// A ticket by its name, which is where "CT-14" in a comment links to.
$router->get('/t/{key}', static fn($key) => (new TicketController())->byKey((string) $key));

$router->post('/tickets/{id}/attachments', static fn($id) => (new AttachmentController())->upload((int) $id));
$router->get('/attachments/{id}', static fn($id) => (new AttachmentController())->download((int) $id));
$router->post('/attachments/{id}/delete', static fn($id) => (new AttachmentController())->delete((int) $id));

$router->post('/tickets/{id}/links', static fn($id) => (new LinkController())->create((int) $id));
$router->post('/links/{id}/delete', static fn($id) => (new LinkController())->delete((int) $id));

$router->post('/tickets/{id}/sprint', static fn($id) => (new SprintController())->assign((int) $id));

$router->post('/tickets/{id}/comments', static fn($id) => (new CommentController())->create((int) $id));
$router->post('/comments/{id}', static fn($id) => (new CommentController())->update((int) $id));
$router->post('/comments/{id}/delete', static fn($id) => (new CommentController())->delete((int) $id));

// ---------------------------------------------------------------------------
// The hours
// ---------------------------------------------------------------------------
$router->post('/tickets/{id}/log', static fn($id) => (new WorklogController())->create((int) $id));
// Logged from anywhere: the top bar's "Log time", and the tickets it offers.
$router->post('/log', static fn() => (new WorklogController())->quick());
$router->get('/log/suggest', static fn() => (new WorklogController())->suggest());
$router->post('/worklogs/{id}', static fn($id) => (new WorklogController())->update((int) $id));
$router->post('/worklogs/{id}/place', static fn($id) => (new WorklogController())->place((int) $id));
$router->post('/worklogs/{id}/delete', static fn($id) => (new WorklogController())->delete((int) $id));

$router->get('/timesheet', static fn() => (new TimesheetController())->index());
$router->post('/timesheet/grid', static fn() => (new TimesheetController())->saveGrid());
$router->post('/timesheet/submit', static fn() => (new TimesheetController())->submit());
$router->get('/timesheet/approvals', static fn() => (new TimesheetController())->approvals());
$router->get('/timesheet/team', static fn() => (new TimesheetController())->team());
$router->get('/planning', static fn() => (new PlanningController())->index());
$router->post('/planning', static fn() => (new PlanningController())->create());
$router->post('/planning/{id}/delete', static fn($id) => (new PlanningController())->delete((int) $id));
$router->post('/timesheet/review', static fn() => (new TimesheetController())->review());
$router->post('/absences', static fn() => (new TimesheetController())->addAbsence());
$router->post('/absences/{id}/delete', static fn($id) => (new TimesheetController())->removeAbsence((int) $id));

$router->get('/reports', static fn() => (new ReportController())->index());
$router->get('/reports/export', static fn() => (new ReportController())->export());
$router->get('/reports/missing', static fn() => (new ReportController())->missing());

$router->post('/tickets/{id}/timer', static fn($id) => (new TimerController())->start((int) $id));
$router->post('/timer/stop', static fn() => (new TimerController())->stop());
$router->post('/timer/discard', static fn() => (new TimerController())->discard());

// ---------------------------------------------------------------------------
// The people, for administrators
// ---------------------------------------------------------------------------
$router->get('/people', static fn() => (new PeopleController())->index());
$router->get('/people/create', static fn() => (new PeopleController())->createForm());
$router->post('/people/create', static fn() => (new PeopleController())->create());
$router->get('/people/{id}/edit', static fn($id) => (new PeopleController())->editForm((int) $id));
$router->post('/people/{id}', static fn($id) => (new PeopleController())->update((int) $id));
$router->post('/people/{id}/password', static fn($id) => (new PeopleController())->resetPassword((int) $id));
$router->post('/people/{id}/two-factor/reset', static fn($id) => (new TwoFactorController())->reset((int) $id));

$router->get('/settings', static fn() => (new SettingsController())->index());
$router->post('/settings/lock', static fn() => (new SettingsController())->lock());
$router->post('/settings/holidays', static fn() => (new SettingsController())->addHoliday());
$router->post('/settings/holidays/national', static fn() => (new SettingsController())->addNational());
$router->post('/settings/holidays/delete', static fn() => (new SettingsController())->removeHoliday());

$router->get('/settings/work-types', static fn() => (new WorkTypeController())->index());
$router->post('/settings/work-types', static fn() => (new WorkTypeController())->create());
$router->post('/settings/work-types/{id}', static fn($id) => (new WorkTypeController())->update((int) $id));

$router->get('/settings/webhooks', static fn() => (new WebhookController())->index());
$router->post('/settings/webhooks', static fn() => (new WebhookController())->create());
$router->post('/settings/github', static fn() => (new WebhookController())->githubSecret());
$router->get('/settings/webhooks/{id}', static fn($id) => (new WebhookController())->show((int) $id));
$router->post('/settings/webhooks/{id}', static fn($id) => (new WebhookController())->update((int) $id));
$router->post('/settings/webhooks/{id}/delete', static fn($id) => (new WebhookController())->delete((int) $id));
$router->post('/settings/webhooks/{id}/secret', static fn($id) => (new WebhookController())->newSecret((int) $id));
$router->post('/settings/webhooks/{id}/ping', static fn($id) => (new WebhookController())->ping((int) $id));
$router->post('/settings/webhooks/{id}/deliveries/{delivery}', static fn($id, $delivery) => (new WebhookController())->redeliver((int) $id, (int) $delivery));

// ---------------------------------------------------------------------------
// The API, version 1 — JSON, with a personal access token as a bearer token
// ---------------------------------------------------------------------------
$router->get('/api/v1/me', static fn() => (new ApiController())->me());
$router->get('/api/v1/users', static fn() => (new ApiController())->users());
$router->get('/api/v1/projects', static fn() => (new ApiController())->projects());
$router->get('/api/v1/projects/{code}', static fn($code) => (new ApiController())->project((string) $code));
$router->get('/api/v1/tickets', static fn() => (new ApiController())->tickets());
$router->post('/api/v1/tickets', static fn() => (new ApiController())->createTicket());
$router->get('/api/v1/tickets/{key}', static fn($key) => (new ApiController())->ticket((string) $key));
$router->patch('/api/v1/tickets/{key}', static fn($key) => (new ApiController())->updateTicket((string) $key));
$router->get('/api/v1/tickets/{key}/comments', static fn($key) => (new ApiController())->comments((string) $key));
$router->post('/api/v1/tickets/{key}/comments', static fn($key) => (new ApiController())->addComment((string) $key));
$router->post('/api/v1/tickets/{key}/worklogs', static fn($key) => (new ApiController())->logWork((string) $key));
$router->get('/api/v1/worklogs', static fn() => (new ApiController())->worklogs());
$router->delete('/api/v1/worklogs/{id}', static fn($id) => (new ApiController())->deleteWorklog((int) $id));

// ---------------------------------------------------------------------------
// News from other services, each signed with its own secret
// ---------------------------------------------------------------------------
$router->post('/integrations/github', static fn() => (new IntegrationController())->github());
