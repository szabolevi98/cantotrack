<?php

/**
 * Fills an installation with something to look at: two projects, their epics
 * and tickets, a few colleagues, and a week of hours behind them.
 *
 * For a development machine and for the pictures in the README — not for
 * anything anyone works in. It refuses to run unless `app.env` is `dev`,
 * because it creates accounts that can sign in, and an account nobody meant to
 * create is the kind of thing that survives to a live server.
 *
 *   php database/seed_demo.php
 *   php database/seed_demo.php --reset    (removes what it made last time first)
 *
 * The passwords are printed, because a demo account nobody can sign in to is
 * not much of a demo.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use CantoTrack\Core\Config;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Model\WorklogRepository;

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

if (Config::get('app.env') !== 'dev') {
    fwrite(STDERR, "This only runs where app.env is dev. It makes accounts that can sign in.\n");
    exit(1);
}

$database = DatabaseConnection::get();
$projects = new ProjectRepository();
$epics = new EpicRepository();
$tickets = new TicketRepository();
$users = new UserRepository();
$worklogs = new WorklogRepository();

$codes = ['CT', 'WEB'];

if ($reset) {
    foreach ($codes as $code) {
        $existing = $projects->findByCode($code);

        if ($existing !== null) {
            // Deleting the project takes its epics, tickets and worklogs with
            // it — which is the whole point of the cascade being where it is.
            $projects->delete((int) $existing['id']);
            printf("  removed %s and everything in it%s", $code, PHP_EOL);
        }
    }
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

// Whoever set the installation up gets the work assigned to them as well, so
// the dashboard and the timesheet have something on them for the person who
// is most likely to be looking.
$firstAdmin = $database->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$me = (int) $firstAdmin;
$anna = $people['anna@cantotrack.demo'];
$mark = $people['mark@cantotrack.demo'];
$julia = $people['julia@cantotrack.demo'];

// ---------------------------------------------------------------------------
// The projects
// ---------------------------------------------------------------------------
$ctId = $projects->create('CT', 'CantoTrack', 'The tracker itself, tracked in itself.');
$webId = $projects->create('WEB', 'Website relaunch', 'New site, new copy, and a move off the old host.');

$boardEpic = $epics->create($ctId, 'Board and tickets', 'Projects, epics, tickets, and the board they sit on.');
$timeEpic = $epics->create($ctId, 'Time logging', 'Worklogs, the timesheet, and what a week adds up to.');
$designEpic = $epics->create($ctId, 'Design and accessibility', 'The palette, the contrast figures, and the keyboard.');
$contentEpic = $epics->create($webId, 'Content', 'Everything that has to be written before anything can be built.');
$buildEpic = $epics->create($webId, 'Build', 'Templates, the CMS and the move.');

/** Makes a ticket and hands back its id. */
$make = static function (array $ticket) use ($tickets): int {
    return $tickets->create($ticket + [
        'epic_id' => null,
        'description' => null,
        'status' => 'backlog',
        'priority' => 'normal',
        'assignee_id' => null,
        'reporter_id' => null,
        'estimate_minutes' => null,
    ]);
};

$made = [];

$made['board'] = $make([
    'project_id' => $ctId, 'epic_id' => $boardEpic, 'title' => 'Board with a column per status',
    'description' => "Five columns, and a ticket moved along with one control.\n\nIt has to work with a keyboard and on a phone, which rules out dragging as the only way.",
    'status' => 'done', 'priority' => 'high', 'assignee_id' => $me, 'reporter_id' => $me,
    'estimate_minutes' => 8 * 60,
]);

$made['numbering'] = $make([
    'project_id' => $ctId, 'epic_id' => $boardEpic, 'title' => 'Ticket numbers that survive two people at once',
    'description' => 'The counter lives on the project and is raised in the same transaction as the insert.',
    'status' => 'done', 'priority' => 'urgent', 'assignee_id' => $me, 'reporter_id' => $julia,
    'estimate_minutes' => 3 * 60,
]);

$made['filters'] = $make([
    'project_id' => $ctId, 'epic_id' => $boardEpic, 'title' => 'Filters that live in the URL',
    'description' => 'So a filtered list can be bookmarked and sent to somebody.',
    'status' => 'done', 'priority' => 'normal', 'assignee_id' => $anna, 'reporter_id' => $me,
    'estimate_minutes' => 2 * 60,
]);

$made['worklog'] = $make([
    'project_id' => $ctId, 'epic_id' => $timeEpic, 'title' => 'Log time from the ticket page',
    'description' => "The day the work happened, not the day it was typed.\n\nFriday afternoon is regularly written down on Monday.",
    'status' => 'done', 'priority' => 'high', 'assignee_id' => $me, 'reporter_id' => $me,
    'estimate_minutes' => 5 * 60,
]);

$made['timesheet'] = $make([
    'project_id' => $ctId, 'epic_id' => $timeEpic, 'title' => 'The week, day by day, with what it went on',
    'description' => "One row per day, the entries under it, and the day's total beside it.

"
        . 'Beside the week: where the hours went by project, and what everybody else logged.',
    'status' => 'in_progress', 'priority' => 'high', 'assignee_id' => $me, 'reporter_id' => $julia,
    'estimate_minutes' => 6 * 60,
]);

$made['export'] = $make([
    'project_id' => $ctId, 'epic_id' => $timeEpic, 'title' => 'Export a month as CSV',
    'description' => 'For whoever does the invoicing. Minutes as a decimal, one row per worklog.',
    'status' => 'todo', 'priority' => 'normal', 'assignee_id' => $mark, 'reporter_id' => $julia,
    'estimate_minutes' => 3 * 60,
]);

$made['contrast'] = $make([
    'project_id' => $ctId, 'epic_id' => $designEpic, 'title' => 'Measure the contrast rather than claim it',
    'description' => 'Every figure in the stylesheet comes from a calculation, and the comments say which.',
    'status' => 'done', 'priority' => 'normal', 'assignee_id' => $anna, 'reporter_id' => $anna,
    'estimate_minutes' => 90,
]);

$made['keyboard'] = $make([
    'project_id' => $ctId, 'epic_id' => $designEpic, 'title' => 'Walk the whole application on a keyboard',
    'description' => 'Every control reachable by Tab, in an order that matches the page.',
    'status' => 'review', 'priority' => 'normal', 'assignee_id' => $anna, 'reporter_id' => $me,
    'estimate_minutes' => 2 * 60,
]);

$made['mobile'] = $make([
    'project_id' => $ctId, 'epic_id' => $designEpic, 'title' => 'The board on a phone',
    'description' => 'The columns scroll sideways; the cards must stay readable.',
    'status' => 'in_progress', 'priority' => 'normal', 'assignee_id' => $mark, 'reporter_id' => $anna,
    'estimate_minutes' => 4 * 60,
]);

$made['notifications'] = $make([
    'project_id' => $ctId, 'title' => 'Say something when a ticket is assigned to you',
    'description' => 'Email, or a list on the dashboard. Probably the list first.',
    'status' => 'backlog', 'priority' => 'low', 'reporter_id' => $julia,
]);

$made['attachments'] = $make([
    'project_id' => $ctId, 'title' => 'Attachments on a ticket',
    'description' => 'Screenshots, mostly. Wherever they are put, they have to be backed up with the database.',
    'status' => 'backlog', 'priority' => 'low', 'reporter_id' => $mark,
]);

$made['copy'] = $make([
    'project_id' => $webId, 'epic_id' => $contentEpic, 'title' => 'Rewrite the services pages',
    'description' => 'Six pages, one voice. The old ones were written by four people over three years.',
    'status' => 'in_progress', 'priority' => 'high', 'assignee_id' => $julia, 'reporter_id' => $julia,
    'estimate_minutes' => 10 * 60,
]);

$made['photos'] = $make([
    'project_id' => $webId, 'epic_id' => $contentEpic, 'title' => 'Photograph the team',
    'description' => 'One morning, one studio, everybody in the same light.',
    'status' => 'todo', 'priority' => 'normal', 'assignee_id' => $anna, 'reporter_id' => $julia,
    'estimate_minutes' => 4 * 60,
]);

$made['templates'] = $make([
    'project_id' => $webId, 'epic_id' => $buildEpic, 'title' => 'Templates for the three page kinds',
    'description' => 'Landing, service, article. Everything else is one of those three with different copy.',
    'status' => 'todo', 'priority' => 'high', 'assignee_id' => $mark, 'reporter_id' => $me,
    'estimate_minutes' => 12 * 60,
]);

$made['redirects'] = $make([
    'project_id' => $webId, 'epic_id' => $buildEpic, 'title' => 'Redirects from every old address',
    'description' => 'A relaunch that loses its addresses loses its search results with them.',
    'status' => 'backlog', 'priority' => 'urgent', 'reporter_id' => $me,
    'estimate_minutes' => 3 * 60,
]);

// ---------------------------------------------------------------------------
// The hours, across this week
// ---------------------------------------------------------------------------
$monday = new DateTimeImmutable('monday this week');
$today = new DateTimeImmutable('today');

/** The date of a weekday of this week, never later than today. */
$day = static function (int $offset) use ($monday, $today): ?string {
    $date = $monday->modify('+' . $offset . ' days');

    return $date > $today ? null : $date->format('Y-m-d');
};

$entries = [
    [$me, 'timesheet', 0, 165, 'Days as rows, entries under them.'],
    [$me, 'worklog', 0, 120, 'The form on the ticket page.'],
    [$me, 'board', 0, 90, 'Column widths on a narrow window.'],
    [$me, 'worklog', 1, 210, 'Parsing "1h 30m", "90m" and "1:30".'],
    [$me, 'numbering', 1, 135, 'The counter, and the transaction around it.'],
    [$me, 'timesheet', 2, 240, 'Totals per project beside the week.'],
    [$me, 'mobile', 2, 75, 'Reviewed the board on a phone.'],
    [$me, 'timesheet', 3, 180, null],
    [$me, 'export', 3, 60, 'Worked out what the CSV has to hold.'],
    [$me, 'keyboard', 4, 150, 'Tab order through the board.'],

    [$anna, 'contrast', 0, 90, 'Measured every pair in the palette.'],
    [$anna, 'filters', 1, 180, null],
    [$anna, 'keyboard', 2, 120, 'Focus rings on the cards.'],
    [$anna, 'photos', 3, 240, 'Booked the studio.'],

    [$mark, 'mobile', 0, 300, 'Board columns on a 375px screen.'],
    [$mark, 'templates', 1, 360, null],
    [$mark, 'export', 2, 120, 'Worked out the columns the invoicing needs.'],
    [$mark, 'templates', 3, 300, null],

    [$julia, 'copy', 0, 240, 'First pass on the services pages.'],
    [$julia, 'copy', 2, 180, null],
];

$logged = 0;

foreach ($entries as [$userId, $ticket, $offset, $minutes, $note]) {
    $date = $day($offset);

    // A week that has only just started has no Thursday yet, and logging
    // against a day that has not happened is exactly what the application
    // refuses elsewhere.
    if ($date === null) {
        continue;
    }

    $worklogs->create($made[$ticket], $userId, $date, $minutes, $note);
    $logged += $minutes;
}

printf('%sMade 2 projects, %d epics, %d tickets and %s of logged time.%s',
    PHP_EOL, 5, count($made), floor($logged / 60) . 'h', PHP_EOL);

if ($madePasswords !== []) {
    printf('%sThe demo accounts:%s', PHP_EOL, PHP_EOL);

    foreach ($madePasswords as $email => $password) {
        printf('  %-28s %s%s', $email, $password, PHP_EOL);
    }
}

printf('%sRun again with --reset to start these two projects over.%s', PHP_EOL, PHP_EOL);
