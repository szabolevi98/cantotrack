<?php

/**
 * Fills an installation with something to look at: two projects written out
 * by hand — the tracker itself, and a client's website — with their epics,
 * tickets, sprints, comments and links, a few colleagues and a client's
 * guest, two weeks of hours, a handed-in week waiting for approval, and the
 * year's public holidays. Then, from seed_demo_more.php, the agency around
 * them: four more projects and nine weeks of their work.
 *
 * For a development machine, the pictures in the README, and a public demo
 * — not for anything anyone works in. It refuses to run unless `app.env` is
 * `dev`, because it creates accounts that can sign in, and an account nobody
 * meant to create is the kind of thing that survives to a live server.
 *
 *   php database/seed_demo.php
 *   php database/seed_demo.php --reset    (removes what it made last time first)
 *
 * Everything goes through the same services the forms use, so the tickets
 * have a history, the sprints their numbers and the comments their mentions.
 * Then the dates are moved back to when the work would have happened: a demo
 * where every ticket was created a second ago has a burndown that says so.
 *
 * The passwords of the accounts it makes are printed, because a demo account
 * nobody can sign in to is not much of a demo.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use CantoTrack\Core\Config;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Model\BoardRepository;
use CantoTrack\Model\ClientRepository;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\SavedFilterRepository;
use CantoTrack\Model\SprintRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\Calendar;
use CantoTrack\Service\CommentService;
use CantoTrack\Service\LinkService;
use CantoTrack\Service\SprintService;
use CantoTrack\Service\TicketService;
use CantoTrack\Service\WeekReview;

$root = dirname(__DIR__);
$configPath = $root . '/config/config.ini';
$reset = in_array('--reset', $argv ?? [], true);

foreach ($argv ?? [] as $argument) {
    if (str_starts_with($argument, '--config=')) {
        $given = substr($argument, strlen('--config='));
        $configPath = str_starts_with($given, '/') || preg_match('/^[A-Za-z]:/', $given) === 1
            ? $given
            : $root . '/' . $given;
    }
}

Config::load($configPath);
date_default_timezone_set((string) Config::get('app.timezone', 'Europe/Budapest'));

if (Config::get('app.env') !== 'dev') {
    fwrite(STDERR, "This only runs where app.env is dev. It makes accounts that can sign in.\n");
    exit(1);
}

$database = DatabaseConnection::get();
$projects = new ProjectRepository();
$tickets = new TicketRepository();
$users = new UserRepository();
$worklogs = new WorklogRepository();
$epics = new EpicRepository();
$sprints = new SprintRepository();
$ticketService = new TicketService();
$comments = new CommentService();
$links = new LinkService();
$sprintService = new SprintService();
$calendar = new Calendar();

$codes = ['CT', 'WEB', 'BIKE', 'CLINIC', 'WINE', 'OPS', 'HELP'];
$demoEmails = [
    'anna@cantotrack.demo', 'mark@cantotrack.demo', 'julia@cantotrack.demo', 'eszter@nordic.demo',
    'bence@cantotrack.demo', 'zsofia@cantotrack.demo', 'dora@cantotrack.demo', 'reka@cantotrack.demo',
    'gergo@cantotrack.demo', 'tamas@cantotrack.demo', 'peter@balatonbikes.demo', 'kata@mecsekclinic.demo',
];

if ($reset) {
    // The shared boards it made, with their sprints: a board is nobody's
    // project, so deleting the projects would leave them there, empty.
    $database->exec("DELETE FROM boards WHERE project_id IS NULL AND name IN ('Studio Scrum Board', 'Client bugs')");

    foreach ($codes as $code) {
        $existing = $projects->findByCode($code);

        if ($existing !== null) {
            // The hours first, and deliberately: the database does not let a
            // ticket go while hours point at it, because hours are what gets
            // invoiced. Here they are demo hours, and going is the point.
            $database->prepare(
                'DELETE w FROM worklogs w JOIN tickets t ON t.id = w.ticket_id WHERE t.project_id = :project'
            )->execute(['project' => (int) $existing['id']]);

            // And its client's statements, which were made of those hours.
            $database->prepare('DELETE s FROM statements s JOIN projects p ON p.client_id = s.client_id WHERE p.id = :project')
                ->execute(['project' => (int) $existing['id']]);

            // The project then takes its epics, sprints and tickets with it.
            $projects->delete((int) $existing['id']);
            printf('  removed %s and everything in it%s', $code, PHP_EOL);
        }
    }

    // The demo people's handed-in weeks and days away go too; their accounts
    // stay, with the passwords they were given the first time.
    $in = implode(',', array_fill(0, count($demoEmails), '?'));
    $database->prepare("DELETE tw FROM timesheet_weeks tw JOIN users u ON u.id = tw.user_id WHERE u.email IN ($in)")->execute($demoEmails);
    $database->prepare("DELETE a FROM absences a JOIN users u ON u.id = a.user_id WHERE u.email IN ($in)")->execute($demoEmails);
    $database->prepare("DELETE f FROM saved_filters f JOIN users u ON u.id = f.user_id WHERE u.email IN ($in)")->execute($demoEmails);
}

foreach ($codes as $code) {
    if ($projects->findByCode($code) !== null) {
        fwrite(STDERR, "Project $code is already here. Run with --reset to make it again.\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// The people
// ---------------------------------------------------------------------------
$team = [
    ['name' => 'Anna Kovács', 'email' => 'anna@cantotrack.demo', 'role' => 'member'],
    ['name' => 'Márk Tóth', 'email' => 'mark@cantotrack.demo', 'role' => 'member'],
    ['name' => 'Júlia Nagy', 'email' => 'julia@cantotrack.demo', 'role' => 'admin'],
    // Somebody from the client, who reads and comments on their own project.
    ['name' => 'Eszter Varga', 'email' => 'eszter@nordic.demo', 'role' => 'guest'],
];

$people = [];
$madePasswords = [];

foreach ($team as $member) {
    $existing = $users->findByEmail($member['email']);

    if ($existing !== null) {
        $people[$member['email']] = (int) $existing['id'];
        continue;
    }

    $password = UserRepository::newPassword();
    $people[$member['email']] = $users->create($member['name'], $member['email'], $password, $member['role']);
    $madePasswords[$member['email']] = $password;
}

// Whoever set the installation up gets work of their own as well, so the
// dashboard and the timesheet have something on them for the person most
// likely to be looking.
$firstAdmin = $database->query("SELECT id FROM users WHERE role = 'admin' AND email NOT LIKE '%.demo' ORDER BY id LIMIT 1");
$me = (int) ($firstAdmin === false ? 0 : $firstAdmin->fetchColumn());
$anna = $people['anna@cantotrack.demo'];
$mark = $people['mark@cantotrack.demo'];
$julia = $people['julia@cantotrack.demo'];
$eszter = $people['eszter@nordic.demo'];
$me = $me ?: $julia;

// Anna works four days a week: her Fridays are not expected to be full.
$users->setWorkingWeek($anna, [480, 480, 480, 480, 0, 0, 0]);

// ---------------------------------------------------------------------------
// The calendar: this year's and next year's public holidays
// ---------------------------------------------------------------------------
foreach ([(int) date('Y'), (int) date('Y') + 1] as $year) {
    $have = array_column($calendar->holidays($year), 'day');

    foreach (Calendar::hungarianHolidays($year) as $date => $name) {
        if (!in_array($date, $have, true)) {
            $calendar->addHoliday($date, $name);
        }
    }
}

// ---------------------------------------------------------------------------
// The weeks everything happens in
// ---------------------------------------------------------------------------
$thisMonday = new DateTimeImmutable('monday this week');
$lastMonday = $thisMonday->modify('-7 days');
$sprintOneStart = $thisMonday->modify('-14 days');
$today = new DateTimeImmutable('today');

/** A day relative to this Monday, as Y-m-d. */
$day = static fn(int $offset): string => $thisMonday->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

// ---------------------------------------------------------------------------
// The projects
// ---------------------------------------------------------------------------
$ctId = $projects->create('CT', 'CantoTrack', 'The tracker itself, tracked in itself.');
$webId = $projects->create('WEB', 'Nordic Coffee website', 'A new site for a coffee roaster: new copy, a shop, and a move off the old host.');

// The tracker is internal work; the website is billed to the client, and
// only the people on it see it.
$projects->setBilling($ctId, null, false);
$projects->setBilling($webId, (new ClientRepository())->findOrCreate('Nordic Coffee Roasters'), true);
$projects->setVisibility($webId, 'private');

foreach ([$me, $anna, $mark, $julia, $eszter] as $member) {
    $projects->addMember($webId, $member);
}

$boardEpic = $epics->create($ctId, 'Board and tickets', 'Projects, epics, tickets, and the board they sit on.');
$timeEpic = $epics->create($ctId, 'Time logging', 'Worklogs, the timesheet, and what a week adds up to.');
$designEpic = $epics->create($ctId, 'Design and accessibility', 'The palette, the contrast figures, and the keyboard.');
$contentEpic = $epics->create($webId, 'Content', 'Everything that has to be written before anything can be built.');
$buildEpic = $epics->create($webId, 'Build', 'Templates, the shop and the move.');

// ---------------------------------------------------------------------------
// The tickets
// ---------------------------------------------------------------------------
$made = [];

/** Makes a ticket through the service, as its reporter would. */
$make = static function (string $name, int $reporter, array $input) use (&$made, $ticketService): int {
    return $made[$name] = $ticketService->create($input, $reporter);
};

$make('board', $me, [
    'project_id' => $ctId, 'epic_id' => $boardEpic, 'type' => 'story', 'title' => 'Board with a column per status',
    'description' => "Five columns, and a ticket moved along with one control.\n\nIt has to work with a keyboard and on a phone, which rules out dragging as the only way.\n\n- [x] columns from the project's settings\n- [x] drag and drop between them\n- [x] a select on every card for the keyboard",
    'status' => 'done', 'priority' => 'high', 'assignee_id' => $me, 'estimate' => '1d', 'story_points' => 5, 'labels' => 'board, ui',
]);
$make('numbering', $julia, [
    'project_id' => $ctId, 'epic_id' => $boardEpic, 'type' => 'bug', 'title' => 'Two tickets got the same number under load',
    'description' => "Created at the same moment from two browsers, both came out as CT-7.\n\nThe counter now lives on the project and is raised in the same transaction as the insert.",
    'status' => 'done', 'priority' => 'urgent', 'assignee_id' => $me, 'estimate' => '3h', 'story_points' => 3, 'labels' => 'database',
]);
$make('filters', $me, [
    'project_id' => $ctId, 'epic_id' => $boardEpic, 'type' => 'story', 'title' => 'Filters that live in the URL',
    'description' => 'So a filtered list can be bookmarked, saved for the team, and sent to somebody.',
    'status' => 'done', 'assignee_id' => $anna, 'estimate' => '2h', 'story_points' => 2, 'labels' => 'ui',
]);
$make('worklog', $me, [
    'project_id' => $ctId, 'epic_id' => $timeEpic, 'type' => 'story', 'title' => 'Log time from the ticket page',
    'description' => "The day the work happened, not the day it was typed.\n\nFriday afternoon is regularly written down on Monday.",
    'status' => 'done', 'priority' => 'high', 'assignee_id' => $me, 'estimate' => '5h', 'story_points' => 3, 'labels' => 'time',
]);
$make('timesheet', $julia, [
    'project_id' => $ctId, 'epic_id' => $timeEpic, 'type' => 'story', 'title' => 'The week, day by day, with what it went on',
    'description' => "One row per day, the entries under it, and the day's total beside it.\n\nBeside the week: where the hours went by project, and what everybody else logged.",
    'status' => 'review', 'priority' => 'high', 'assignee_id' => $me, 'estimate' => '6h', 'story_points' => 5, 'labels' => 'time, ui',
]);
$make('approval', $julia, [
    'project_id' => $ctId, 'epic_id' => $timeEpic, 'type' => 'story', 'title' => 'Hand a week in, and have it approved',
    'description' => "Once handed in, a week's hours stay as they are until an administrator approves it or sends it back with a reason.",
    'status' => 'in_progress', 'priority' => 'high', 'assignee_id' => $me, 'estimate' => '1d', 'story_points' => 5, 'labels' => 'time',
    'due_on' => $day(4),
]);
$make('export', $julia, [
    'project_id' => $ctId, 'epic_id' => $timeEpic, 'type' => 'task', 'title' => 'Export a month as CSV and Excel',
    'description' => 'For whoever does the invoicing. Hours as a decimal, one row per worklog, and the billable ones marked.',
    'status' => 'todo', 'assignee_id' => $mark, 'estimate' => '3h', 'story_points' => 3, 'labels' => 'time, reports',
]);
$make('rounding', $mark, [
    'project_id' => $ctId, 'epic_id' => $timeEpic, 'type' => 'bug', 'title' => '5 minutes logged shows as 0.1h in the report',
    'description' => "The report rounds to one decimal, so a five-minute entry reads as `0.1` and a column of them does not add up to its total.\n\nSteps: log 5m three times on CT-6, open Reports for the week.",
    'status' => 'todo', 'priority' => 'high', 'assignee_id' => $mark, 'estimate' => '2h', 'story_points' => 2, 'labels' => 'reports',
]);
$make('contrast', $anna, [
    'project_id' => $ctId, 'epic_id' => $designEpic, 'type' => 'task', 'title' => 'Measure the contrast rather than claim it',
    'description' => 'Every figure in the stylesheet comes from a calculation, and the comments say which.',
    'status' => 'done', 'assignee_id' => $anna, 'estimate' => '90m', 'story_points' => 1, 'labels' => 'accessibility',
]);
$make('keyboard', $me, [
    'project_id' => $ctId, 'epic_id' => $designEpic, 'type' => 'story', 'title' => 'Walk the whole application on a keyboard',
    'description' => 'Every control reachable by Tab, in an order that matches the page — and the shortcuts: `c` for a new ticket, `/` to search, `g` then `b` for the board.',
    'status' => 'in_progress', 'assignee_id' => $anna, 'estimate' => '4h', 'story_points' => 3, 'labels' => 'accessibility',
]);
$make('dark', $anna, [
    'project_id' => $ctId, 'epic_id' => $designEpic, 'type' => 'story', 'title' => 'A dark theme that keeps the contrast',
    'description' => 'The same variables, darker — and the same 4.5:1 for every piece of text.',
    'status' => 'done', 'assignee_id' => $anna, 'estimate' => '3h', 'story_points' => 2, 'labels' => 'ui, accessibility',
]);
$make('mobile', $anna, [
    'project_id' => $ctId, 'epic_id' => $designEpic, 'type' => 'bug', 'title' => 'Board cards overflow on a 375px screen',
    'description' => 'The columns scroll sideways, but a long title pushes the card wider than its column.',
    'status' => 'in_progress', 'assignee_id' => $mark, 'estimate' => '2h', 'story_points' => 1, 'labels' => 'board, mobile',
]);
$make('api', $me, [
    'project_id' => $ctId, 'type' => 'story', 'title' => 'A JSON API with personal access tokens',
    'description' => 'Tickets, comments and hours for scripts — with the same rules the forms have.',
    'status' => 'todo', 'assignee_id' => $me, 'estimate' => '1d 4h', 'story_points' => 8, 'labels' => 'api',
]);
$make('webhooks', $julia, [
    'project_id' => $ctId, 'type' => 'story', 'title' => 'Tell the team chat when a ticket moves',
    'description' => 'Signed webhooks, retried when the other end is down.',
    'status' => 'backlog', 'priority' => 'low', 'estimate' => '5h', 'story_points' => 5, 'labels' => 'api',
]);
$make('import', $mark, [
    'project_id' => $ctId, 'type' => 'task', 'title' => 'Import tickets from a spreadsheet',
    'status' => 'backlog', 'priority' => 'low', 'story_points' => 3,
]);

$make('copy', $julia, [
    'project_id' => $webId, 'epic_id' => $contentEpic, 'type' => 'task', 'title' => 'Rewrite the product pages',
    'description' => 'Twelve coffees, one voice. The old pages were written by four people over three years.',
    'status' => 'in_progress', 'priority' => 'high', 'assignee_id' => $julia, 'estimate' => '2d', 'labels' => 'copy', 'due_on' => $day(9),
]);
$make('photos', $eszter, [
    'project_id' => $webId, 'epic_id' => $contentEpic, 'type' => 'task', 'title' => 'Photograph the roastery',
    'description' => 'One morning, the roaster running, everybody in the same light.',
    'status' => 'todo', 'assignee_id' => $anna, 'estimate' => '4h', 'labels' => 'photos',
]);
$make('templates', $me, [
    'project_id' => $webId, 'epic_id' => $buildEpic, 'type' => 'story', 'title' => 'Templates for the three page kinds',
    'description' => 'Landing, product, article. Everything else is one of those three with different copy.',
    'status' => 'in_progress', 'priority' => 'high', 'assignee_id' => $mark, 'estimate' => '1d 4h', 'labels' => 'frontend',
]);
$make('shop', $eszter, [
    'project_id' => $webId, 'epic_id' => $buildEpic, 'type' => 'story', 'title' => 'Subscriptions in the shop',
    'description' => 'A bag every two or four weeks, paused or cancelled by the customer without writing to us.',
    'status' => 'todo', 'priority' => 'normal', 'assignee_id' => $mark, 'estimate' => '3d', 'labels' => 'shop',
]);
$make('redirects', $me, [
    'project_id' => $webId, 'epic_id' => $buildEpic, 'type' => 'task', 'title' => 'Redirects from every old address',
    'description' => 'A relaunch that loses its addresses loses its search results with them.',
    'status' => 'backlog', 'priority' => 'urgent', 'estimate' => '3h', 'labels' => 'seo', 'due_on' => $day(16),
]);
$make('checkout', $eszter, [
    'project_id' => $webId, 'epic_id' => $buildEpic, 'type' => 'bug', 'title' => 'The old checkout adds shipping twice',
    'description' => 'Seen on two orders last week. Worth knowing before the new shop copies the rule.',
    'status' => 'done', 'priority' => 'high', 'assignee_id' => $mark, 'estimate' => '2h', 'labels' => 'shop',
]);

// Two tickets broken into their steps, made last so the numbers above stay
// the ones the descriptions and the comments mention.
foreach ([
    ['approval', 'approval-hand', 'The hand-in button, and what it asks first', 'done', $me, '2h'],
    ['approval', 'approval-queue', 'The approvals page for the administrators', 'in_progress', $me, '4h'],
    ['approval', 'approval-back', 'Tell the person why a week was sent back', 'todo', $julia, '1h'],
    ['templates', 'templates-landing', 'Landing page template', 'done', $mark, '1d'],
    ['templates', 'templates-product', 'Product page template', 'review', $mark, '1d'],
    ['templates', 'templates-article', 'Article template', 'in_progress', $mark, '4h'],
] as [$parentName, $name, $title, $status, $who, $estimate]) {
    $parentTicket = (array) $tickets->find($made[$parentName]);
    $make($name, $me, [
        'project_id' => (int) $parentTicket['project_id'], 'parent_id' => $made[$parentName], 'type' => 'task',
        'title' => $title, 'status' => $status, 'assignee_id' => $who, 'estimate' => $estimate,
    ]);
}

// ---------------------------------------------------------------------------
// Links, comments
// ---------------------------------------------------------------------------
$key = static fn(string $name): string => (string) (($t = $tickets->find($made[$name])) === null ? '' : $t['project_code'] . '-' . $t['number']);

$links->link($made['approval'], 'blocked_by', $key('timesheet'), $me);
$links->link($made['rounding'], 'relates', $key('export'), $mark);
$links->link($made['templates'], 'blocks', $key('shop'), $me);
$links->link($made['checkout'], 'relates', $key('shop'), $eszter);

$said = [];
$say = static function (string $ticket, int $who, string $body, string $when) use (&$said, $comments, $made): void {
    $said[] = [$comments->add($made[$ticket], $who, $body), $when];
};

$say('timesheet', $me, "Days as rows work well. @anna can you check the totals against {$key('worklog')}?\n\n- [x] rows per day\n- [ ] totals per project", $day(-2) . ' 15:10');
$say('timesheet', $anna, 'Totals match. One thing: a holiday should not read as a short day — it should say it is a holiday.', $day(-1) . ' 09:40');
$say('timesheet', $me, 'Good catch — holidays and days away now expect nothing, and the day says why.', $day(0) . ' 11:05');
$say('rounding', $julia, "Reproduced. Three 5m entries show as `0.1 + 0.1 + 0.1` and a total of `0.3`, but the minutes add up to 15m = 0.25h.\n\n@mark let's show minutes in the table and keep the decimal for the export only.", $day(1) . ' 10:20');
$say('mobile', $mark, 'The title needs `overflow-wrap: anywhere`; the column itself is fine.', $day(1) . ' 14:30');
$say('copy', $eszter, 'The first four pages read really well. Could the Ethiopian one say where the farm is? Our customers ask.', $day(1) . ' 16:45');
$say('copy', $julia, '@eszter added it, with the altitude too. The next four are coming on Friday.', $day(2) . ' 09:15');
$say('checkout', $mark, "Found it: the old shop adds shipping per item for subscriptions. Fixed on the live site, and {$key('shop')} will not copy the rule.", $day(-4) . ' 13:00');

// ---------------------------------------------------------------------------
// The sprints: one finished, one running
// ---------------------------------------------------------------------------
$sprintOne = $sprintService->create((int) (new BoardRepository())->ownOf($ctId)['id'], 'CT Sprint 1', 'Tickets on a board, and time on tickets.', $sprintOneStart->format('Y-m-d'), $sprintOneStart->modify('+11 days')->format('Y-m-d'));
$sprintTwo = $sprintService->create((int) (new BoardRepository())->ownOf($ctId)['id'], 'CT Sprint 2', 'A week you can hand in, and see where it went.', $thisMonday->format('Y-m-d'), $thisMonday->modify('+11 days')->format('Y-m-d'));

$sprintService->assign([$made['board'], $made['numbering'], $made['filters'], $made['worklog'], $made['contrast'], $made['timesheet']], $sprintOne, $me);
$sprintService->start((array) $sprints->find($sprintOne));
$sprintService->close((array) $sprints->find($sprintOne), $sprintTwo, $me);
$sprintService->assign([$made['approval'], $made['export'], $made['rounding'], $made['keyboard'], $made['dark'], $made['mobile']], $sprintTwo, $me);
$sprintService->start((array) $sprints->find($sprintTwo));

// ---------------------------------------------------------------------------
// The releases: one out, one coming with the running sprint, one further off
// ---------------------------------------------------------------------------
$releaseService = new CantoTrack\Service\ReleaseService();
$releaseRepository = new CantoTrack\Model\ReleaseRepository();
$putIn = static function (int $release, array $names) use ($releaseRepository, $made): void {
    $releaseRepository->assign(array_map(static fn(string $name): int => $made[$name], $names), $release);
};

$firstRelease = $releaseService->create($ctId, '1.0', 'A board, tickets on it, and time on the tickets.', $sprintOneStart->format('Y-m-d'), $sprintOneStart->modify('+11 days')->format('Y-m-d'));
$putIn($firstRelease, ['board', 'numbering', 'filters', 'worklog', 'contrast']);
$releaseService->release((array) $releaseRepository->find($firstRelease), null, $me);

$nextRelease = $releaseService->create($ctId, '1.1', 'A week you can hand in, and see where it went.', $thisMonday->format('Y-m-d'), $day(9));
$putIn($nextRelease, ['approval', 'timesheet', 'export', 'rounding', 'keyboard', 'mobile', 'dark']);

$laterRelease = $releaseService->create($ctId, '1.2', 'For other programs: the API, webhooks, and an import.', '', $day(30));
$putIn($laterRelease, ['api', 'webhooks', 'import']);

$relaunch = $releaseService->create($webId, 'Relaunch', 'The new site, the shop, and every old address still working.', $thisMonday->modify('-14 days')->format('Y-m-d'), $day(16));
$putIn($relaunch, ['copy', 'photos', 'templates', 'shop', 'redirects']);

// ---------------------------------------------------------------------------
// The hours: last week and this one
// ---------------------------------------------------------------------------
$entries = [
    // Last week
    [$me, 'board', -7, 180, 'Columns from the project settings.'],
    [$me, 'numbering', -7, 150, 'Reproduced with two browsers; counter moved onto the project.'],
    [$me, 'worklog', -6, 240, 'The form on the ticket page, and parsing "1h 30m".'],
    [$me, 'board', -5, 210, 'Drag and drop, and the select for the keyboard.'],
    [$me, 'timesheet', -4, 270, 'Days as rows, entries under them.'],
    [$me, 'worklog', -3, 120, 'Rounding up to the smallest slice.'],
    [$me, 'filters', -3, 60, 'Reviewed the saved filters.'],
    [$anna, 'filters', -7, 300, 'Filters in the query string.'],
    [$anna, 'contrast', -6, 180, 'Measured every pair in the palette.'],
    [$anna, 'dark', -5, 240, 'The dark palette, measured again.'],
    [$anna, 'keyboard', -4, 210, 'Tab order through the board.'],
    [$mark, 'templates-landing', -7, 420, 'Landing template.'],
    [$mark, 'templates-product', -6, 390, 'Product template.'],
    [$mark, 'checkout', -5, 150, 'Found the double shipping.'],
    [$mark, 'mobile', -4, 240, 'Board columns on a 375px screen.'],
    [$mark, 'shop', -3, 360, 'Subscription rules, on paper first.'],
    [$julia, 'copy', -7, 300, 'First pass on the product pages.'],
    [$julia, 'copy', -5, 240, null],
    [$julia, 'timesheet', -3, 90, 'Reviewed the week view.'],
    // This week
    [$me, 'approval', 0, 30, 'Meeting: planning the sprint.'],
    [$me, 'timesheet', 0, 165, 'Holidays and days away expect nothing.'],
    [$me, 'approval-hand', 0, 120, 'Handing a week in.'],
    [$me, 'approval-queue', 1, 210, 'Approvals page, and the lock date.'],
    [$me, 'api', 1, 90, 'Sketched the endpoints.'],
    [$me, 'approval', 2, 240, 'Sending a week back with a reason.'],
    [$me, 'rounding', 3, 60, null],
    [$anna, 'keyboard', 0, 240, 'Focus rings on the cards.'],
    [$anna, 'keyboard', 1, 180, 'Shortcuts dialog.'],
    [$anna, 'photos', 2, 300, 'At the roastery.'],
    [$mark, 'mobile', 0, 150, 'overflow-wrap on the titles.'],
    [$mark, 'templates-article', 0, 270, 'Article template.'],
    [$mark, 'rounding', 1, 180, 'Minutes in the table, decimals in the export.'],
    [$mark, 'shop', 2, 330, 'Subscriptions: pausing.'],
    [$julia, 'copy', 0, 360, 'Pages five to eight.'],
    [$julia, 'copy', 2, 240, null],
];

// The kinds of work, and which one each ticket's hours mostly are.
$types = new CantoTrack\Model\WorkTypeRepository();
$typeIds = [];
foreach (['Development', 'Design', 'Content', 'Review', 'Meeting'] as $name) {
    $existing = $types->resolve($name);
    $typeIds[$name] = $existing === null ? $types->create($name) : (int) $existing['id'];
}
$kindOf = static function (string $ticket, ?string $note) use ($typeIds): int {
    if ($note !== null && preg_match('/^Review|reviewed/i', $note) === 1) {
        return $typeIds['Review'];
    }

    if ($note !== null && str_starts_with($note, 'Meeting')) {
        return $typeIds['Meeting'];
    }

    return match ($ticket) {
        'copy', 'photos' => $typeIds['Content'],
        'contrast', 'dark', 'mobile', 'keyboard', 'templates' => $typeIds['Design'],
        default => $typeIds['Development'],
    };
};

$logged = 0;

// Each day's entries one after the other from nine, a quarter of an hour
// apart and an hour off at noon — so the week reads as a calendar too. One
// in five is written down without a start, the way plenty of hours are.
$clock = [];

foreach ($entries as [$userId, $ticket, $offset, $minutes, $note]) {
    $date = $day($offset);

    // A week that has only just started has no Thursday yet, and hours on a
    // day that has not happened are what the application refuses elsewhere.
    if ($date > $today->format('Y-m-d')) {
        continue;
    }

    $row = $tickets->find($made[$ticket]);
    $at = $clock[$userId . $date] ?? 9 * 60;
    if ($at < 13 * 60 && $at + $minutes > 12 * 60 + 30) {
        $at = max($at, 13 * 60);
    }
    $clock[$userId . $date] = $at + $minutes + 15;
    $start = ($logged / 15) % 5 === 3 || $at + $minutes > 22 * 60 ? null : sprintf('%02d:%02d:00', intdiv($at, 60), $at % 60);
    $worklogs->create($made[$ticket], $userId, $date, $minutes, $note, (int) ($row['project_billable'] ?? 1) === 1, $start, $kindOf($ticket, $note));
    $logged += $minutes;
}

// Júlia was ill last Tuesday, and Márk is off next Friday.
$calendar->addAbsence($julia, $day(-6), $day(-6), 'sick', '');
$calendar->addAbsence($mark, $day(11), $day(11), 'vacation', 'Long weekend');

// Last week: Anna's approved, Márk's waiting, Júlia's sent back with a note.
$review = new WeekReview();
$review->submit($anna, $lastMonday->format('Y-m-d'));
$review->review($anna, $lastMonday->format('Y-m-d'), $julia, true, '');
$review->submit($mark, $lastMonday->format('Y-m-d'));
$review->submit($julia, $lastMonday->format('Y-m-d'));
$review->review($julia, $lastMonday->format('Y-m-d'), $me, false, 'Thursday has nothing on it — was that the copy review?');

// What is planned for this week and the next.
$planning = new CantoTrack\Service\Planning();
$plan = static function (int $who, string $ticket, int $fromOffset, int $toOffset, string $perDay, string $note = '') use ($planning, $key, $day, $me): void {
    $planning->add($who, $key($ticket), null, $day($fromOffset), $day($toOffset), $perDay, $note, $me);
};
$plan($me, 'approval', 0, 4, '5h', 'Hand-in and approval');
$plan($me, 'api', 7, 11, '6h');
$plan($anna, 'keyboard', 0, 3, '6h');
$plan($anna, 'photos', 7, 8, '4h', 'At the roastery');
$plan($mark, 'rounding', 0, 2, '3h');
$plan($mark, 'shop', 0, 11, '4h', 'Subscriptions');
$plan($julia, 'copy', 0, 11, '5h');

// The filters the team shares — once each, however often the demo is reset.
// Written in the query language where they can be: "me" is whoever opens
// one, so a shared filter is everybody's own list.
$sharedFilters = [
    [$me, 'Open bugs', 'type=bug&open=1'],
    [$me, 'Mine, still open', http_build_query(['query' => 'assignee = me AND category != done ORDER BY priority DESC, due'])],
    [$anna, 'Due this week', http_build_query(['query' => 'due <= endOfWeek() AND category != done ORDER BY due'])],
    [$anna, 'Urgent and high', http_build_query(['query' => 'priority IN (urgent, high) AND category != done ORDER BY priority DESC'])],
    [$mark, 'Waiting for a review', http_build_query(['query' => 'status = Review ORDER BY updated'])],
    [$me, 'Nobody’s yet', http_build_query(['query' => 'assignee IS EMPTY AND category != done ORDER BY created DESC'])],
];
foreach ($sharedFilters as [$owner, $filterName, $filterQuery]) {
    $database->prepare('DELETE FROM saved_filters WHERE user_id = :owner AND name = :name')->execute(['owner' => $owner, 'name' => $filterName]);
    (new SavedFilterRepository())->create($owner, $filterName, $filterQuery, true);
}

// ---------------------------------------------------------------------------
// And the dates moved back to when it all would have happened
// ---------------------------------------------------------------------------
$at = static fn(DateTimeImmutable $when, string $time): string => $when->format('Y-m-d') . ' ' . $time;
$update = static function (string $sql, array $params) use ($database): void {
    $database->prepare($sql)->execute($params);
};

// Every ticket was written down before the first sprint started, a few an
// hour apart, and its "created" line in the history with it.
$i = 0;
foreach ($made as $id) {
    $created = $sprintOneStart->modify('-3 days')->format('Y-m-d') . sprintf(' %02d:%02d:00', 9 + intdiv($i, 4), ($i % 4) * 13);
    $update('UPDATE tickets SET created_at = :at, updated_at = GREATEST(updated_at, :at2) WHERE id = :id', ['at' => $created, 'at2' => $created, 'id' => $id]);
    $update("UPDATE ticket_events SET created_at = :at WHERE ticket_id = :id AND kind = 'created'", ['at' => $created, 'id' => $id]);
    $i++;
}

// The first sprint's tickets were finished during it, a day or two apart,
// so its burndown goes down the way a real one does.
$finished = ['numbering' => 1, 'board' => 3, 'filters' => 4, 'contrast' => 7, 'worklog' => 8, 'checkout' => 9, 'dark' => 15];

// 1.0 went out the day its sprint ended.
$update('UPDATE releases SET released_at = :at WHERE id = :id', ['at' => $at($sprintOneStart->modify('+11 days'), '17:00:00'), 'id' => $firstRelease]);
foreach ($finished as $name => $offset) {
    $when = $at($sprintOneStart->modify('+' . $offset . ' days'), '16:30:00');
    $update('UPDATE tickets SET closed_at = :at WHERE id = :id', ['at' => $when, 'id' => $made[$name]]);
    $update("UPDATE ticket_events SET created_at = :at WHERE ticket_id = :id AND kind = 'status'", ['at' => $when, 'id' => $made[$name]]);
}

$update('UPDATE sprints SET started_at = :started, closed_at = :closed WHERE id = :id', [
    'started' => $at($sprintOneStart, '09:00:00'),
    'closed' => $at($sprintOneStart->modify('+11 days'), '17:00:00'),
    'id' => $sprintOne,
]);
$update('UPDATE sprints SET started_at = :started WHERE id = :id', ['started' => $at($thisMonday, '09:00:00'), 'id' => $sprintTwo]);
// Put in the first sprint as it started, handed on when it closed, and the
// second sprint's tickets put in on its first morning.
$inMade = ' AND ticket_id IN (' . implode(',', array_map('intval', $made)) . ')';
$update("UPDATE ticket_events SET created_at = :at WHERE kind = 'sprint' AND old_value IS NULL AND new_value = 'CT Sprint 1'" . $inMade, ['at' => $at($sprintOneStart, '08:45:00')]);
$update("UPDATE ticket_events SET created_at = :at WHERE kind = 'sprint' AND old_value = 'CT Sprint 1'" . $inMade, ['at' => $at($sprintOneStart->modify('+11 days'), '17:00:00')]);
$update("UPDATE ticket_events SET created_at = :at WHERE kind = 'sprint' AND old_value IS NULL AND new_value = 'CT Sprint 2'" . $inMade, ['at' => $at($thisMonday, '08:45:00')]);

foreach ($said as [$commentId, $when]) {
    $update('UPDATE comments SET created_at = :at WHERE id = :id', ['at' => $when . ':00', 'id' => $commentId]);
}

$update("UPDATE ticket_events SET created_at = :at WHERE kind = 'linked' AND ticket_id IN (" . implode(',', array_map('intval', $made)) . ')', ['at' => $at($sprintOneStart->modify('-2 days'), '10:00:00')]);
$update('UPDATE timesheet_weeks SET submitted_at = :at WHERE week_start = :week', ['at' => $at($thisMonday, '08:30:00'), 'week' => $lastMonday->format('Y-m-d')]);
$update("UPDATE timesheet_weeks SET reviewed_at = :at WHERE week_start = :week AND state <> 'submitted'", ['at' => $at($thisMonday, '10:15:00'), 'week' => $lastMonday->format('Y-m-d')]);

// The rest of the agency: four more projects and three months of them.
require __DIR__ . '/seed_demo_more.php';
/** @var array{projects: int, tickets: int, comments: int, hours: int} $moreSummary */

printf(
    '%sMade %d projects, %d tickets, %d comments and %sh of logged time.%s',
    PHP_EOL,
    2 + $moreSummary['projects'],
    count($made) + $moreSummary['tickets'],
    count($said) + $moreSummary['comments'],
    intdiv($logged, 60) + $moreSummary['hours'],
    PHP_EOL
);

if ($madePasswords !== []) {
    printf('%sThe demo accounts:%s', PHP_EOL, PHP_EOL);

    foreach ($madePasswords as $email => $password) {
        printf('  %-28s %s%s', $email, $password, PHP_EOL);
    }
}

printf('%sRun again with --reset to start the demo projects over.%s', PHP_EOL, PHP_EOL);
