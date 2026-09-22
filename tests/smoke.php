<?php

/**
 * Walks the application over real HTTP: the login page, a wrong password, a
 * right one, and what a signed-out visitor gets.
 *
 * Over HTTP rather than by calling the controllers, because most of what can
 * break here lives outside PHP — the rewrite rules, the session cookie, the
 * redirect after a login. A test that calls the controller directly passes
 * happily while the .htaccess sends every address to the same page.
 *
 *   php tests/smoke.php
 *   php tests/smoke.php --url=http://localhost/cantotrack/web --email=… --password=…
 *
 * The password is given on the command line and never stored here: this file is
 * in the repository, and the seeded one is not the same on two installations.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use CantoTrack\Core\Config;

$root = dirname(__DIR__);
Config::load($root . '/config/config.ini');

$baseUrl = rtrim((string) Config::get('app.base_url'), '/');
$email = null;
$password = null;

foreach ($argv ?? [] as $argument) {
    if (str_starts_with($argument, '--url=')) {
        $baseUrl = rtrim(substr($argument, strlen('--url=')), '/');
    }

    if (str_starts_with($argument, '--email=')) {
        $email = substr($argument, strlen('--email='));
    }

    if (str_starts_with($argument, '--password=')) {
        $password = substr($argument, strlen('--password='));
    }
}

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        printf("PASS  %s%s", $name, PHP_EOL);

        return;
    }

    $failed++;
    printf("FAIL  %s%s%s", $name, $detail === '' ? '' : ' (' . $detail . ')', PHP_EOL);
}

/** One request. Returns the status, the headers and the body; follows nothing. */
function request(string $url, array $post = [], string $cookieJar = ''): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
    ]);

    if ($cookieJar !== '') {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieJar);
    }

    if ($post !== []) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $response = curl_exec($handle);

    if ($response === false) {
        fwrite(STDERR, 'Request failed: ' . curl_error($handle) . ' — is Apache running?' . PHP_EOL);
        exit(1);
    }

    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);

    return [
        'status' => $status,
        'headers' => substr((string) $response, 0, $headerSize),
        'body' => substr((string) $response, $headerSize),
    ];
}

printf('Checking %s%s%s', $baseUrl, PHP_EOL, PHP_EOL);

// The login page, and the token every form on it has to carry.
$jar = tempnam(sys_get_temp_dir(), 'ct-smoke-');
$login = request($baseUrl . '/login', [], $jar);

check('the login page answers', $login['status'] === 200, 'status ' . $login['status']);
check('it is the sign-in form', str_contains($login['body'], 'Sign in'));
check('the stylesheet is linked, so the build ran', str_contains($login['body'], 'assets/css/app.css'));

preg_match('/name="_token" value="([^"]+)"/', $login['body'], $tokenMatch);
$token = $tokenMatch[1] ?? '';
check('the form carries a CSRF token', $token !== '');

// A post without one has to be refused, or the gate is not a gate.
$noToken = request($baseUrl . '/login', ['email' => 'x@example.com', 'password' => 'x'], $jar);
check('a post without a token is refused', $noToken['status'] === 403, 'status ' . $noToken['status']);

// Signed out, the application sends you to the login page rather than showing
// anything.
$guest = request($baseUrl . '/', [], tempnam(sys_get_temp_dir(), 'ct-smoke-'));
check('a signed-out visitor is sent to the login page',
    $guest['status'] === 302 && str_contains($guest['headers'], '/login'),
    'status ' . $guest['status']);

// An address that does not exist.
$missing = request($baseUrl . '/nothing-here', [], $jar);
check('an unknown address answers 404', $missing['status'] === 404, 'status ' . $missing['status']);

if ($email === null || $password === null) {
    printf('%sSkipping the sign-in checks: pass --email= and --password= to run them.%s', PHP_EOL, PHP_EOL);
} else {
    $wrong = request($baseUrl . '/login', [
        '_token' => $token,
        'email' => $email,
        'password' => $password . '-wrong',
    ], $jar);

    check('a wrong password is refused', $wrong['status'] === 401, 'status ' . $wrong['status']);
    check('and the page does not say which half was wrong',
        !str_contains($wrong['body'], 'No such') && str_contains($wrong['body'], 'do not match'));

    $right = request($baseUrl . '/login', [
        '_token' => $token,
        'email' => $email,
        'password' => $password,
    ], $jar);

    check('the right password signs in',
        $right['status'] === 302 && !str_contains($right['headers'], '/login'),
        'status ' . $right['status']);

    $dashboard = request($baseUrl . '/', [], $jar);
    check('and lands on the dashboard', $dashboard['status'] === 200, 'status ' . $dashboard['status']);
    // The signed-in shell rather than the greeting itself: the heading says
    // hello by first name, which this script does not know.
    check('which is the signed-in shell',
        str_contains($dashboard['body'], '<title>Dashboard')
        && str_contains($dashboard['body'], 'Sign out')
        && str_contains($dashboard['body'], 'Timesheet'));

    // ---------------------------------------------------------------------
    // The work: a project, an epic in it, a ticket in the epic, and the moves
    // that follow. Done over HTTP as well, because what is being checked is
    // that the forms, the routes and the redirects agree with each other.
    // ---------------------------------------------------------------------
    $code = 'T' . random_int(100, 999);

    $projectForm = request($baseUrl . '/projects/create', [], $jar);
    preg_match('/name="_token" value="([^"]+)"/', $projectForm['body'], $m);
    $token = $m[1] ?? $token;

    $madeProject = request($baseUrl . '/projects/create', [
        '_token' => $token,
        'code' => $code,
        'name' => 'Smoke test project',
        'description' => 'Created by tests/smoke.php.',
    ], $jar);

    check('a project can be created',
        $madeProject['status'] === 302, 'status ' . $madeProject['status']);

    preg_match('#/projects/(\d+)#', $madeProject['headers'], $m);
    $projectId = (int) ($m[1] ?? 0);
    check('and the redirect points at it', $projectId > 0);

    // A code that is already taken has to be refused, or two projects end up
    // sharing the prefix their tickets are named after.
    $duplicate = request($baseUrl . '/projects/create', [
        '_token' => $token,
        'code' => $code,
        'name' => 'The same code again',
    ], $jar);
    check('a code that is taken is refused',
        str_contains($duplicate['body'], 'already a project with that code'));

    $madeEpic = request($baseUrl . '/projects/' . $projectId . '/epics/create', [
        '_token' => $token,
        'title' => 'Smoke test epic',
    ], $jar);
    check('an epic can be created', $madeEpic['status'] === 302, 'status ' . $madeEpic['status']);

    preg_match('#/epics/(\d+)#', $madeEpic['headers'], $m);
    $epicId = (int) ($m[1] ?? 0);

    $madeTicket = request($baseUrl . '/tickets/create', [
        '_token' => $token,
        'project_id' => $projectId,
        'epic_id' => $epicId,
        'title' => 'Smoke test ticket',
        'description' => 'Created by tests/smoke.php.',
        'status' => 'todo',
        'priority' => 'high',
        'estimate' => '1h 30m',
    ], $jar);
    check('a ticket can be created', $madeTicket['status'] === 302, 'status ' . $madeTicket['status']);

    preg_match('#/tickets/(\d+)#', $madeTicket['headers'], $m);
    $ticketId = (int) ($m[1] ?? 0);

    $ticketPage = request($baseUrl . '/tickets/' . $ticketId, [], $jar);
    check('the ticket page carries its name and project',
        str_contains($ticketPage['body'], $code . '-1') && str_contains($ticketPage['body'], 'Smoke test ticket'),
        'the first ticket of a project should be ' . $code . '-1');
    check('and the estimate was read as an hour and a half',
        str_contains($ticketPage['body'], '1h 30m'));

    // An estimate nobody can parse is refused rather than quietly dropped.
    $badEstimate = request($baseUrl . '/tickets/create', [
        '_token' => $token,
        'project_id' => $projectId,
        'title' => 'Unreadable estimate',
        'estimate' => 'three apples',
    ], $jar);
    check('an estimate that cannot be read is refused',
        str_contains($badEstimate['body'], 'should read like'));

    $moved = request($baseUrl . '/tickets/' . $ticketId . '/status', [
        '_token' => $token,
        'status' => 'done',
        'back' => '/projects/' . $projectId,
    ], $jar);
    check('a ticket can be moved along the board', $moved['status'] === 302);
    check('and the move goes back where it was clicked',
        str_contains($moved['headers'], '/projects/' . $projectId));

    $board = request($baseUrl . '/projects/' . $projectId, [], $jar);
    check('the board shows the ticket in its new column',
        str_contains($board['body'], 'Smoke test ticket') && str_contains($board['body'], 'Smoke test epic'));

    $list = request($baseUrl . '/tickets?q=' . $code . '-1', [], $jar);
    check('the ticket can be found by its name', str_contains($list['body'], 'Smoke test ticket'));

    // ---------------------------------------------------------------------
    // The hours
    // ---------------------------------------------------------------------
    $logged = request($baseUrl . '/tickets/' . $ticketId . '/log', [
        '_token' => $token,
        'time' => '1h 30m',
        'work_date' => date('Y-m-d'),
        'note' => 'Logged by tests/smoke.php.',
    ], $jar);
    check('time can be logged against a ticket', $logged['status'] === 302, 'status ' . $logged['status']);

    $ticketPage = request($baseUrl . '/tickets/' . $ticketId, [], $jar);
    check('the ticket shows what was logged on it',
        str_contains($ticketPage['body'], 'Logged by tests/smoke.php.')
        && str_contains($ticketPage['body'], '1h 30m'));

    // Two entries on the same ticket have to add up rather than replace each
    // other, which is the whole point of keeping them as rows.
    request($baseUrl . '/tickets/' . $ticketId . '/log', [
        '_token' => $token,
        'time' => '45m',
        'work_date' => date('Y-m-d'),
    ], $jar);

    $ticketPage = request($baseUrl . '/tickets/' . $ticketId, [], $jar);
    check('a second entry adds to the first', str_contains($ticketPage['body'], '2h 15m'));

    // A time nobody can read, and a day that has not happened, are both refused
    // — and the refusal says so rather than silently logging nothing.
    $badTime = request($baseUrl . '/tickets/' . $ticketId . '/log', [
        '_token' => $token,
        'time' => 'ages',
        'work_date' => date('Y-m-d'),
    ], $jar);
    check('a time that cannot be read is refused',
        str_contains(request($baseUrl . '/tickets/' . $ticketId, [], $jar)['body'], 'should read like')
        && $badTime['status'] === 302);

    request($baseUrl . '/tickets/' . $ticketId . '/log', [
        '_token' => $token,
        'time' => '1h',
        'work_date' => (new DateTimeImmutable('+2 days'))->format('Y-m-d'),
    ], $jar);
    check('a day that has not happened is refused',
        str_contains(request($baseUrl . '/tickets/' . $ticketId, [], $jar)['body'], 'has not happened'));

    $timesheet = request($baseUrl . '/timesheet', [], $jar);
    check('the timesheet answers', $timesheet['status'] === 200, 'status ' . $timesheet['status']);
    check('and this week shows the ticket the time went on',
        str_contains($timesheet['body'], $code . '-1')
        && str_contains($timesheet['body'], 'Logged by tests/smoke.php.'));
    check('with the week totalled', str_contains($timesheet['body'], '2h 15m'));

    // The entries belong to whoever worked them, so this account can remove its
    // own; the timesheet has to lose it with them.
    //
    // The id comes from the ticket page rather than from the first delete link
    // on the timesheet: the week may well hold other work of this account's,
    // and a check that takes whichever entry happens to be first deletes
    // somebody's real afternoon and then passes.
    preg_match('#/worklogs/(\d+)/delete#', $ticketPage['body'], $m);
    $worklogId = (int) ($m[1] ?? 0);
    check('the ticket offers to delete an entry of yours', $worklogId > 0);
    check('and the timesheet offers the same one',
        str_contains($timesheet['body'], '/worklogs/' . $worklogId . '/delete'));

    $removedLog = request($baseUrl . '/worklogs/' . $worklogId . '/delete', [
        '_token' => $token,
        'back' => '/timesheet',
    ], $jar);
    check('an entry can be deleted', $removedLog['status'] === 302);
    check('and the ticket total drops with it',
        !str_contains(request($baseUrl . '/tickets/' . $ticketId, [], $jar)['body'], '2h 15m'));

    // Tidy up after itself: the project takes the epic and the ticket with it.
    $removed = request($baseUrl . '/projects/' . $projectId . '/delete', ['_token' => $token], $jar);
    check('the project can be deleted again', $removed['status'] === 302);
    check('and its ticket is gone with it',
        request($baseUrl . '/tickets/' . $ticketId, [], $jar)['status'] === 404);

    // ---------------------------------------------------------------------
    // The people. Accounts are never deleted by design, so this leaves a
    // deactivated one behind on every run — which is the honest cost of
    // checking the thing that matters: that a colleague can be given an
    // account and a password.
    // ---------------------------------------------------------------------
    $people = request($baseUrl . '/people', [], $jar);
    check('the people page is there for an administrator', $people['status'] === 200);

    $colleague = 'smoke-' . random_int(1000, 9999) . '@example.test';
    $added = request($baseUrl . '/people/create', [
        '_token' => $token,
        'name' => 'Smoke Test Colleague',
        'email' => $colleague,
        'role' => 'member',
    ], $jar);
    check('a colleague can be given an account', $added['status'] === 302);

    $afterAdd = request($baseUrl . '/people', [], $jar);
    check('and the password is shown once, to hand over',
        str_contains($afterAdd['body'], 'Hand this over') && str_contains($afterAdd['body'], $colleague));
    check('but not again on the next look',
        !str_contains(request($baseUrl . '/people', [], $jar)['body'], 'Hand this over'));

    $duplicate = request($baseUrl . '/people/create', [
        '_token' => $token,
        'name' => 'The same address',
        'email' => $colleague,
        'role' => 'member',
    ], $jar);
    check('an address that already signs somebody in is refused',
        str_contains($duplicate['body'], 'already signs in with that address'));

    /*
     * Which row is whose. Taken from the row that carries the address rather
     * than from its position: the table is sorted by name, so the first row is
     * whoever happens to sort first — which is how the first version of these
     * checks ended up editing the wrong person.
     */
    $rowId = static function (string $html, string $address): int {
        preg_match('#<tr[^>]*>(?:(?!</tr>).)*?' . preg_quote($address, '#') . '(?:(?!</tr>).)*?/people/(\d+)/edit#s',
            $html, $found);

        return (int) ($found[1] ?? 0);
    };

    $listing = request($baseUrl . '/people', [], $jar);
    $myId = $rowId($listing['body'], $email);
    $colleagueId = $rowId($listing['body'], $colleague);

    check('each person can be picked out of the list by their address',
        $myId > 0 && $colleagueId > 0 && $myId !== $colleagueId,
        'mine ' . $myId . ', theirs ' . $colleagueId);

    // The two ways an administrator could lock themselves out of their own
    // installation. Both are refused with a sentence rather than by leaving
    // them to find out.
    $selfDemote = request($baseUrl . '/people/' . $myId, [
        '_token' => $token,
        'name' => 'Still an administrator',
        'email' => $email,
        'role' => 'member',
        'is_active' => '1',
    ], $jar);
    check('an administrator cannot take the role off themselves',
        str_contains($selfDemote['body'], 'cannot take the administrator role off yourself'));

    $selfOff = request($baseUrl . '/people/' . $myId, [
        '_token' => $token,
        'name' => 'Still an administrator',
        'email' => $email,
        'role' => 'admin',
    ], $jar);
    check('nor deactivate their own account',
        str_contains($selfOff['body'], 'cannot deactivate your own account'));

    // Somebody else, on the other hand, can be deactivated — which is what
    // happens instead of deleting them.
    $deactivated = request($baseUrl . '/people/' . $colleagueId, [
        '_token' => $token,
        'name' => 'Smoke Test Colleague',
        'email' => $colleague,
        'role' => 'member',
    ], $jar);
    check('somebody else can be deactivated rather than deleted', $deactivated['status'] === 302);
    check('and then reads as deactivated',
        str_contains(request($baseUrl . '/people', [], $jar)['body'], 'Deactivated'));

    // A deactivated colleague is no longer offered as an assignee, which is the
    // point of deactivating them.
    // By id rather than by name: an earlier run of this script leaves a
    // deactivated colleague behind with the same name, and a check that reads
    // names would be answered by somebody else's leftovers.
    check('a deactivated person is off the assignee list',
        !str_contains(request($baseUrl . '/tickets/create', [], $jar)['body'], 'value="' . $colleagueId . '"'));

    $out = request($baseUrl . '/logout', [], $jar);
    check('signing out redirects to the login page',
        $out['status'] === 302 && str_contains($out['headers'], '/login'));

    $after = request($baseUrl . '/', [], $jar);
    check('and the session no longer opens the dashboard', $after['status'] === 302);
}

@unlink($jar);

printf('%s%d passed, %d failed%s', PHP_EOL, $passed, $failed, PHP_EOL);
exit($failed === 0 ? 0 : 1);
