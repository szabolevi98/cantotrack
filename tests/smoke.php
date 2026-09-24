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

// The sign-in limit is checked only when asked for: it costs six failed
// sign-ins from this machine, and the limit per connection is thirty in a
// quarter of an hour — five runs in a row and the real sign-in is refused too.
// A fresh CI database runs it every time.
$checkThrottle = in_array('--throttle', $argv ?? [], true);

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
        printf('PASS  %s%s', $name, PHP_EOL);

        return;
    }

    $failed++;
    printf('FAIL  %s%s%s', $name, $detail === '' ? '' : ' (' . $detail . ')', PHP_EOL);
}

/**
 * One request. Returns the status, the headers and the body; follows nothing.
 *
 * @param list<string> $headers
 */
function request(string $url, array $post = [], string $cookieJar = '', array $headers = [], ?string $method = null): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($method !== null) {
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
    }

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

/**
 * A file upload, the way a browser's form sends one — or the page's own
 * script, which asks for JSON and carries the token in a header.
 *
 * @param list<array{path: string, name: string, type: string}> $files
 */
function upload(string $url, string $token, array $files, string $cookieJar, bool $asScript = false): array
{
    $fields = $asScript ? [] : ['_token' => $token];

    foreach ($files as $index => $file) {
        $fields['files[' . $index . ']'] = new CURLFile($file['path'], $file['type'], $file['name']);
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_HTTPHEADER => $asScript ? ['Accept: application/json', 'X-CSRF-Token: ' . $token] : [],
    ]);

    $response = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);

    return ['status' => $status, 'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
}

/**
 * One API request: JSON in, JSON out, the token as a bearer token.
 *
 * @return array{status: int, headers: string, body: string, json: mixed}
 */
function api(string $url, string $apiToken, string $method = 'GET', ?array $body = null): array
{
    $handle = curl_init($url);
    $headers = ['Accept: application/json'];

    if ($apiToken !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiToken;
    }

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body));
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    $text = substr($response, $headerSize);

    return ['status' => $status, 'headers' => substr($response, 0, $headerSize), 'body' => $text, 'json' => json_decode($text, true)];
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
check(
    'a signed-out visitor is sent to the login page',
    $guest['status'] === 302 && str_contains($guest['headers'], '/login'),
    'status ' . $guest['status']
);

// An address that does not exist.
$missing = request($baseUrl . '/nothing-here', [], $jar);
check('an unknown address answers 404', $missing['status'] === 404, 'status ' . $missing['status']);
check(
    'with the error page rather than a line of text',
    str_contains($missing['body'], 'error-page') && str_contains($missing['body'], 'assets/css/app.css')
);

// No page runs a script it did not ship: that is what makes an escaping
// mistake survivable.
check(
    'every page says it runs only its own scripts',
    str_contains($login['headers'], "script-src 'self'")
);
check(
    'and the login page links the script it does ship',
    str_contains($login['body'], 'assets/js/app.js?v=')
);

// A lost password: the same answer whether or not the address has an account,
// and a made-up link that leads nowhere.
$forgot = request($baseUrl . '/password/forgot', [], $jar);
check('the lost-password page answers', $forgot['status'] === 200);
$asked = request($baseUrl . '/password/forgot', ['_token' => $token, 'email' => 'nobody-' . random_int(1000, 9999) . '@example.test'], $jar);
check(
    'and says the same thing about an address nobody has',
    $asked['status'] === 200 && (str_contains($asked['body'], 'a link to choose a new password is on its way') || str_contains($asked['body'], 'does not send email'))
);
check(
    'a reset link that was never sent opens nothing',
    !str_contains(request($baseUrl . '/password/reset/' . str_repeat('ab', 32), [], $jar)['body'], 'name="new_password"')
);

if ($email === null || $password === null) {
    printf('%sSkipping the sign-in checks: pass --email= and --password= to run them.%s', PHP_EOL, PHP_EOL);
} else {
    $wrong = request($baseUrl . '/login', [
        '_token' => $token,
        'email' => $email,
        'password' => $password . '-wrong',
    ], $jar);

    check('a wrong password is refused', $wrong['status'] === 401, 'status ' . $wrong['status']);
    check(
        'and the page does not say which half was wrong',
        !str_contains($wrong['body'], 'No such') && str_contains($wrong['body'], 'do not match')
    );

    $right = request($baseUrl . '/login', [
        '_token' => $token,
        'email' => $email,
        'password' => $password,
    ], $jar);

    check(
        'the right password signs in',
        $right['status'] === 302 && !str_contains($right['headers'], '/login'),
        'status ' . $right['status']
    );

    $dashboard = request($baseUrl . '/', [], $jar);
    check('and lands on the dashboard', $dashboard['status'] === 200, 'status ' . $dashboard['status']);
    check('with the notifications a click away', request($baseUrl . '/notifications', [], $jar)['status'] === 200);
    // The signed-in shell rather than the greeting itself: the heading says
    // hello by first name, which this script does not know.
    check(
        'which is the signed-in shell',
        str_contains($dashboard['body'], '<title>Dashboard')
        && str_contains($dashboard['body'], 'Sign out')
        && str_contains($dashboard['body'], 'Timesheet')
    );

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

    check(
        'a project can be created',
        $madeProject['status'] === 302,
        'status ' . $madeProject['status']
    );

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
    check(
        'a code that is taken is refused',
        str_contains($duplicate['body'], 'already a project with that code')
    );

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
    check(
        'the ticket page carries its name and project',
        str_contains($ticketPage['body'], $code . '-1') && str_contains($ticketPage['body'], 'Smoke test ticket'),
        'the first ticket of a project should be ' . $code . '-1'
    );
    check(
        'and the estimate was read as an hour and a half',
        str_contains($ticketPage['body'], '1h 30m')
    );

    // An estimate nobody can parse is refused rather than quietly dropped.
    $badEstimate = request($baseUrl . '/tickets/create', [
        '_token' => $token,
        'project_id' => $projectId,
        'title' => 'Unreadable estimate',
        'estimate' => 'three apples',
    ], $jar);
    check(
        'an estimate that cannot be read is refused',
        str_contains($badEstimate['body'], 'should read like')
    );

    $moved = request($baseUrl . '/tickets/' . $ticketId . '/status', [
        '_token' => $token,
        'status' => 'done',
        'back' => '/projects/' . $projectId,
    ], $jar);
    check('a ticket can be moved along the board', $moved['status'] === 302);
    check(
        'and the move goes back where it was clicked',
        str_contains($moved['headers'], '/projects/' . $projectId)
    );

    $board = request($baseUrl . '/projects/' . $projectId, [], $jar);
    check(
        'the board shows the ticket in its new column',
        str_contains($board['body'], 'Smoke test ticket') && str_contains($board['body'], 'Smoke test epic')
    );

    // A drag on the board, as the board's script sends it: JSON back, the
    // token in a header, and the card in the column it was dropped in.
    preg_match('/data-status-id="(\d+)"/', $board['body'], $m);
    $firstColumn = $m[1] ?? '';
    $dragged = request($baseUrl . '/tickets/' . $ticketId . '/move', ['status' => $firstColumn, 'above' => '', 'below' => ''], $jar, [
        'Accept: application/json',
        'X-CSRF-Token: ' . $token,
    ]);
    check(
        'a card dragged on the board lands in its column',
        $dragged['status'] === 200 && str_contains($dragged['body'], '"ok":true')
        && str_contains(request($baseUrl . '/tickets/' . $ticketId, [], $jar)['body'], 'moved it from Done to Backlog'),
        'status ' . $dragged['status']
    );
    request($baseUrl . '/tickets/' . $ticketId . '/status', ['_token' => $token, 'status' => 'done'], $jar);

    $lanes = request($baseUrl . '/projects/' . $projectId . '?lanes=epic&type=task', [], $jar);
    check('the board can be split into lanes and narrowed', $lanes['status'] === 200 && str_contains($lanes['body'], 'board__lane-title'));

    $list = request($baseUrl . '/tickets?q=' . $code . '-1', [], $jar);
    check('the ticket can be found by its name', str_contains($list['body'], 'Smoke test ticket'));
    check('and the list says how many it found', str_contains($list['body'], 'Showing 1–1 of 1'));

    // The search box: a ticket's name is a jump to it, anything else a search.
    $jump = request($baseUrl . '/search?q=' . strtolower($code) . '-1', [], $jar);
    check(
        'typing a ticket’s name in the search box goes straight to it',
        $jump['status'] === 302 && str_contains($jump['headers'], '/tickets/' . $ticketId)
    );
    $words = request($baseUrl . '/search?q=' . urlencode('Smoke test'), [], $jar);
    check('anything else searches the list', $words['status'] === 302 && str_contains($words['headers'], '/tickets?q=Smoke'));

    // A list kept by name, in the sidebar from then on.
    $saved = request($baseUrl . '/filters', ['_token' => $token, 'name' => 'Smoke list ' . $code, 'query' => 'project=' . $projectId . '&open=1&evil=x'], $jar);
    $withSidebar = request($baseUrl . '/', [], $jar)['body'];
    check(
        'a filtered list can be saved, and appears in the sidebar',
        $saved['status'] === 302 && str_contains($withSidebar, 'Smoke list ' . $code) && !str_contains($withSidebar, 'evil=x')
    );
    preg_match('#filter=(\d+)#', $saved['headers'], $m);
    $filterId = (int) ($m[1] ?? 0);

    // Many tickets at once, each through its own rules and into its history.
    $bulk = request($baseUrl . '/tickets/bulk', [
        '_token' => $token,
        'ids' => [$ticketId, 999999],
        'set_priority' => 'low',
        'set_assignee' => '999999',
        'back' => '/tickets?project=' . $projectId,
    ], $jar);
    $afterBulk = request($baseUrl . '/tickets?project=' . $projectId, [], $jar)['body'];
    check(
        'a change for many tickets is refused, ticket by ticket, where it does not fit',
        $bulk['status'] === 302 && str_contains($afterBulk, 'was left as it was') && str_contains($afterBulk, 'no such active account')
    );
    request($baseUrl . '/tickets/bulk', ['_token' => $token, 'ids' => [$ticketId], 'set_priority' => 'low', 'add_label' => 'bulk'], $jar);
    $afterBulk = request($baseUrl . '/tickets/' . $ticketId . '?activity=history', [], $jar)['body'];
    check(
        'and made where it does, with the history to say so',
        str_contains($afterBulk, 'changed the priority from High to Low') && str_contains($afterBulk, '>bulk</a>')
    );

    request($baseUrl . '/filters/' . $filterId . '/delete', ['_token' => $token], $jar);

    // ---------------------------------------------------------------------
    // What a ticket may not be made with. Each of these used to be written
    // as sent — the first as a foreign-key error and a 500.
    // ---------------------------------------------------------------------
    $ghost = request($baseUrl . '/tickets/create', [
        '_token' => $token,
        'project_id' => $projectId,
        'title' => 'Assigned to nobody who exists',
        'assignee_id' => 999999,
    ], $jar);
    check(
        'an assignee that does not exist is refused, not a 500',
        $ghost['status'] === 422 && str_contains($ghost['body'], 'no such active account'),
        'status ' . $ghost['status']
    );

    $otherCode = 'U' . random_int(100, 999);
    $other = request($baseUrl . '/projects/create', [
        '_token' => $token,
        'code' => $otherCode,
        'name' => 'Second smoke test project',
    ], $jar);
    preg_match('#/projects/(\d+)#', $other['headers'], $m);
    $otherId = (int) ($m[1] ?? 0);

    $otherEpic = request($baseUrl . '/projects/' . $otherId . '/epics/create', [
        '_token' => $token,
        'title' => 'An epic of the other project',
    ], $jar);
    preg_match('#/epics/(\d+)#', $otherEpic['headers'], $m);
    $otherEpicId = (int) ($m[1] ?? 0);

    $foreign = request($baseUrl . '/tickets/create', [
        '_token' => $token,
        'project_id' => $projectId,
        'title' => 'In the wrong epic',
        'epic_id' => $otherEpicId,
    ], $jar);
    check(
        'an epic from another project is refused',
        $foreign['status'] === 422 && str_contains($foreign['body'], 'not in this project'),
        'status ' . $foreign['status']
    );

    request($baseUrl . '/projects/' . $otherId, [
        '_token' => $token,
        'name' => 'Second smoke test project',
        'is_archived' => '1',
    ], $jar);
    $archived = request($baseUrl . '/tickets/create', [
        '_token' => $token,
        'project_id' => $otherId,
        'title' => 'Into the archive',
    ], $jar);
    check(
        'an archived project takes no new tickets',
        $archived['status'] === 422 && str_contains($archived['body'], 'archived'),
        'status ' . $archived['status']
    );

    $otherGone = request($baseUrl . '/projects/' . $otherId . '/delete', ['_token' => $token], $jar);
    check('a project without hours can still be deleted', $otherGone['status'] === 302
        && request($baseUrl . '/projects/' . $otherId, [], $jar)['status'] === 404);

    // Two people editing the same ticket: the second save, made over the
    // version the first one replaced, is refused rather than undoing it.
    $editForm = request($baseUrl . '/tickets/' . $ticketId . '/edit', [], $jar);
    preg_match('/name="version" value="(\d+)"/', $editForm['body'], $m);
    $version = $m[1] ?? '';
    check('the edit form carries the version it was drawn from', $version !== '');

    $first = request($baseUrl . '/tickets/' . $ticketId, [
        '_token' => $token,
        'version' => $version,
        'title' => 'Smoke test ticket',
        'description' => 'The first person’s edit.',
        'priority' => 'high',
        'epic_id' => $epicId,
    ], $jar);
    check('the first save goes through', $first['status'] === 302, 'status ' . $first['status']);

    $second = request($baseUrl . '/tickets/' . $ticketId, [
        '_token' => $token,
        'version' => $version,
        'title' => 'Smoke test ticket',
        'description' => 'The second person’s edit, made over the old version.',
        'priority' => 'low',
        'epic_id' => $epicId,
    ], $jar);
    check(
        'the second save over the same version is refused',
        $second['status'] === 409 && str_contains($second['body'], 'The first person’s edit.'),
        'status ' . $second['status']
    );
    check(
        'and the first person’s edit is still what is saved',
        str_contains(request($baseUrl . '/tickets/' . $ticketId, [], $jar)['body'], 'The first person’s edit.')
    );

    // ---------------------------------------------------------------------
    // The details and the conversation: a kind, labels, a due day, points,
    // comments in Markdown, and the history of all of it.
    // ---------------------------------------------------------------------
    $editForm = request($baseUrl . '/tickets/' . $ticketId . '/edit', [], $jar);
    preg_match('/name="version" value="(\d+)"/', $editForm['body'], $m);
    $detailed = request($baseUrl . '/tickets/' . $ticketId, [
        '_token' => $token,
        'version' => $m[1] ?? '',
        'type' => 'bug',
        'title' => 'Smoke test ticket',
        'description' => 'The first person’s edit.',
        'priority' => 'high',
        'epic_id' => $epicId,
        'labels' => 'smoke, Second Label, smoke',
        'due_on' => date('Y-m-d', strtotime('-1 day')),
        'story_points' => '5',
    ], $jar);
    $ticketPage = request($baseUrl . '/tickets/' . $ticketId, [], $jar);
    check(
        'a ticket takes a kind, labels, a due day and points',
        $detailed['status'] === 302
        && str_contains($ticketPage['body'], 'type-icon--bug')
        && str_contains($ticketPage['body'], '>smoke</a>')
        && str_contains($ticketPage['body'], 'Second Label'),
        'status ' . $detailed['status']
    );
    check('and a label given twice is one label', substr_count($ticketPage['body'], '>smoke</a>') === 1);
    // It was moved to done above, and something finished is never late.
    check('a finished ticket is not shown as overdue', !str_contains($ticketPage['body'], 'due--late'));

    $labelled = request($baseUrl . '/tickets?label=smoke&project=' . $projectId, [], $jar);
    check('tickets can be listed by label', str_contains($labelled['body'], 'Smoke test ticket'));

    $commented = request($baseUrl . '/tickets/' . $ticketId . '/comments', [
        '_token' => $token,
        'body' => "This is **important** — see $code-1.\n\n<script>alert('comment')</script>",
    ], $jar);
    $ticketPage = request($baseUrl . '/tickets/' . $ticketId, [], $jar);
    check('a comment can be added', $commented['status'] === 302 && str_contains($commented['headers'], '#comment-'));
    check(
        'and its Markdown is rendered, with the ticket name as a link',
        str_contains($ticketPage['body'], '<strong>important</strong>')
        && str_contains($ticketPage['body'], '/t/' . $code . '-1')
    );
    check(
        'but HTML in it is shown, not run',
        str_contains($ticketPage['body'], '&lt;script&gt;') && !str_contains($ticketPage['body'], "<script>alert('comment')")
    );

    $byKey = request($baseUrl . '/t/' . $code . '-1', [], $jar);
    check(
        'a ticket’s name as an address leads to the ticket',
        $byKey['status'] === 302 && str_contains($byKey['headers'], '/tickets/' . $ticketId)
    );

    $history = request($baseUrl . '/tickets/' . $ticketId . '?activity=history', [], $jar);
    check(
        'the history says what happened, in words',
        str_contains($history['body'], 'created the ticket')
        && str_contains($history['body'], 'moved it from')
        && str_contains($history['body'], 'changed the type from')
    );

    $dashboard = request($baseUrl . '/', [], $jar);
    check(
        'and the dashboard shows it among what happened lately',
        str_contains($dashboard['body'], 'commented') && str_contains($dashboard['body'], $code . '-1')
    );

    // ---------------------------------------------------------------------
    // Files: a picture is taken and shown in place, a web page is refused,
    // and what is handed back cannot act as a page of this application.
    // ---------------------------------------------------------------------
    $png = tempnam(sys_get_temp_dir(), 'ct-png-');
    // The smallest valid PNG there is: one transparent pixel.
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $html = tempnam(sys_get_temp_dir(), 'ct-html-');
    file_put_contents($html, '<!DOCTYPE html><html><body><script>alert(document.domain)</script></body></html>');

    $uploaded = upload($baseUrl . '/tickets/' . $ticketId . '/attachments', $token, [
        ['path' => $png, 'name' => 'pixel.png', 'type' => 'image/png'],
    ], $jar, true);
    $files = json_decode($uploaded['body'], true)['files'] ?? [];
    check('a picture can be attached', $uploaded['status'] === 200 && count($files) === 1, 'status ' . $uploaded['status']);

    $refused = upload($baseUrl . '/tickets/' . $ticketId . '/attachments', $token, [
        ['path' => $html, 'name' => 'harmless.png', 'type' => 'image/png'],
    ], $jar, true);
    check(
        'a web page is refused, whatever it calls itself',
        $refused['status'] === 422 && str_contains($refused['body'], 'not a kind of file')
    );

    if ($files !== []) {
        $served = request($files[0]['url'], [], $jar);
        check(
            'and the picture is handed back as a picture that cannot run anything',
            $served['status'] === 200
            && str_contains($served['headers'], 'Content-Type: image/png')
            && str_contains($served['headers'], 'X-Content-Type-Options: nosniff')
            && str_contains($served['headers'], 'sandbox')
        );
        check(
            'only to somebody signed in',
            request($files[0]['url'], [], tempnam(sys_get_temp_dir(), 'ct-smoke-'))['status'] === 302
        );

        $gone = request($baseUrl . '/attachments/' . $files[0]['id'] . '/delete', ['_token' => $token], $jar);
        check('an attachment can be removed', $gone['status'] === 302 && request($files[0]['url'], [], $jar)['status'] === 404);
    }

    @unlink($png);
    @unlink($html);

    // ---------------------------------------------------------------------
    // Links and sprints: one ticket blocking another, a sprint planned,
    // started, burned down and closed.
    // ---------------------------------------------------------------------
    $blocker = request($baseUrl . '/tickets/create', [
        '_token' => $token,
        'project_id' => $projectId,
        'title' => 'The ticket in the way',
        'story_points' => '3',
    ], $jar);
    preg_match('#/tickets/(\d+)#', $blocker['headers'], $m);
    $blockerId = (int) ($m[1] ?? 0);

    request($baseUrl . '/tickets/' . $blockerId . '/links', ['_token' => $token, 'kind' => 'blocks', 'key' => $code . '-1'], $jar);
    $blockedPage = request($baseUrl . '/tickets/' . $ticketId, [], $jar)['body'];
    check(
        'a ticket can block another, and the other one reads it from its end',
        str_contains($blockedPage, 'is blocked by') && str_contains($blockedPage, $code . '-2')
    );

    $selfLink = request($baseUrl . '/tickets/' . $blockerId . '/links', ['_token' => $token, 'kind' => 'relates', 'key' => $code . '-2'], $jar);
    check('a ticket cannot be linked to itself', str_contains(request($baseUrl . '/tickets/' . $blockerId, [], $jar)['body'], 'cannot be linked to itself'));

    $planned = request($baseUrl . '/projects/' . $projectId . '/sprints', [
        '_token' => $token,
        'name' => 'Smoke sprint',
        'starts_on' => date('Y-m-d', strtotime('-3 days')),
        'ends_on' => date('Y-m-d', strtotime('+10 days')),
        'goal' => 'Get the smoke test through',
    ], $jar);
    $backlog = request($baseUrl . '/projects/' . $projectId . '/backlog', [], $jar);
    preg_match('#/sprints/(\d+)/start#', $backlog['body'], $m);
    $sprintId = (int) ($m[1] ?? 0);
    check('a sprint can be planned', $planned['status'] === 302 && $sprintId > 0 && str_contains($backlog['body'], 'Smoke sprint'));

    request($baseUrl . '/tickets/' . $blockerId . '/sprint', ['_token' => $token, 'sprint_id' => $sprintId], $jar);
    $started = request($baseUrl . '/sprints/' . $sprintId . '/start', ['_token' => $token], $jar);
    $sprintBoard = request($baseUrl . '/projects/' . $projectId, [], $jar)['body'];
    check(
        'and started, after which the board is that sprint’s',
        $started['status'] === 302
        && str_contains($sprintBoard, 'sprint-bar')
        && str_contains($sprintBoard, 'The ticket in the way')
        && !str_contains($sprintBoard, '>Smoke test ticket<')
    );
    check(
        'while everything is still one click away',
        str_contains(request($baseUrl . '/projects/' . $projectId . '?scope=all', [], $jar)['body'], 'Smoke test ticket')
    );

    request($baseUrl . '/tickets/' . $blockerId . '/status', ['_token' => $token, 'status' => 'done'], $jar);
    $report = request($baseUrl . '/sprints/' . $sprintId, [], $jar)['body'];
    check('the sprint draws its burndown', str_contains($report, '<svg class="chart"') && str_contains($report, 'chart__line'));

    $closed = request($baseUrl . '/sprints/' . $sprintId . '/close', ['_token' => $token, 'carry_to' => ''], $jar);
    check(
        'and closes, recording what it did',
        $closed['status'] === 302
        && str_contains(request($baseUrl . '/projects/' . $projectId . '/backlog', [], $jar)['body'], 'Velocity')
        && !str_contains(request($baseUrl . '/projects/' . $projectId, [], $jar)['body'], 'sprint-bar')
    );

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
    check(
        'the ticket shows what was logged on it',
        str_contains($ticketPage['body'], 'Logged by tests/smoke.php.')
        && str_contains($ticketPage['body'], '1h 30m')
    );

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
    check(
        'a time that cannot be read is refused',
        str_contains(request($baseUrl . '/tickets/' . $ticketId, [], $jar)['body'], 'should read like')
        && $badTime['status'] === 302
    );

    request($baseUrl . '/tickets/' . $ticketId . '/log', [
        '_token' => $token,
        'time' => '1h',
        'work_date' => (new DateTimeImmutable('+2 days'))->format('Y-m-d'),
    ], $jar);
    check(
        'a day that has not happened is refused',
        str_contains(request($baseUrl . '/tickets/' . $ticketId, [], $jar)['body'], 'has not happened')
    );

    // Broken into steps: a subtask added from the ticket's page shows there,
    // and on its own page says whose step it is.
    $subtaskAdded = request($baseUrl . '/tickets/' . $ticketId . '/subtasks', [
        '_token' => $token, 'title' => 'A step made by tests/smoke.php',
    ], $jar);
    $parentPage = request($baseUrl . '/tickets/' . $ticketId, [], $jar)['body'];
    check(
        'a ticket can be broken into subtasks',
        $subtaskAdded['status'] === 302 && str_contains($parentPage, 'A step made by tests/smoke.php')
        && str_contains($parentPage, 'subtasks__item')
    );
    check(
        'and a ticket with subtasks is not deleted from under them',
        str_contains($parentPage, 'This ticket has subtasks.') || !str_contains($parentPage, '/tickets/' . $ticketId . '/delete')
    );

    // A release: made on the project's page, a ticket put into it from the
    // list, and its notes waiting for it to be finished.
    $releaseMade = request($baseUrl . '/projects/' . $projectId . '/releases', [
        '_token' => $token, 'name' => 'Smoke 1.0', 'release_on' => date('Y-m-d', strtotime('+30 days')),
    ], $jar);
    $releaseId = preg_match('#/releases/(\d+)#', $releaseMade['headers'], $m) === 1 ? (int) $m[1] : 0;
    check('a release can be made', $releaseMade['status'] === 302 && $releaseId > 0);
    request($baseUrl . '/tickets/bulk', ['_token' => $token, 'ids' => [$ticketId], 'set_release' => (string) $releaseId], $jar);
    $releasePage = request($baseUrl . '/releases/' . $releaseId, [], $jar)['body'];
    check(
        'and tickets put into it from the list',
        str_contains($releasePage, $code . '-1') && str_contains($releasePage, 'Release notes')
    );

    // The roadmap, every project's and this one's, with an epic's days
    // moved the way the bar's drag sends them.
    check('the roadmap answers', str_contains(request($baseUrl . '/roadmap', [], $jar)['body'], 'roadmap__months'));
    check('and a project’s own', request($baseUrl . '/projects/' . $projectId . '/roadmap', [], $jar)['status'] === 200);

    // The query language: the ticket just made, found by a query, and a
    // query that cannot be read saying where.
    $queried = request($baseUrl . '/tickets?' . http_build_query(['query' => 'project = ' . $code . ' AND text ~ "Smoke test ticket" ORDER BY created DESC']), [], $jar)['body'];
    check('a query finds tickets', str_contains($queried, $code . '-1'));
    check(
        'and one that cannot be read says where',
        str_contains(request($baseUrl . '/tickets?' . http_build_query(['query' => 'colour = red']), [], $jar)['body'], 'query-form__error')
    );

    $timesheet = request($baseUrl . '/timesheet?view=days', [], $jar);
    check('the timesheet answers', $timesheet['status'] === 200, 'status ' . $timesheet['status']);
    check(
        'and this week shows the ticket the time went on',
        str_contains($timesheet['body'], $code . '-1')
        && str_contains($timesheet['body'], 'Logged by tests/smoke.php.')
    );
    check('with the week totalled', str_contains($timesheet['body'], '2h 15m'));

    // The same week as a grid, typed into: a new cell becomes an entry, and a
    // cell that holds two entries is not rewritten from one number.
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $gridWeek = date('Y-m-d', strtotime('monday this week', strtotime($yesterday)));
    $gridPage = request($baseUrl . '/timesheet?view=grid&week=' . $gridWeek, [], $jar);
    check('the week can be shown as a grid', str_contains($gridPage['body'], 'grid-sheet') && str_contains($gridPage['body'], $code . '-1'));

    $gridSaved = request($baseUrl . '/timesheet/grid', [
        '_token' => $token,
        'week' => $gridWeek,
        'cells' => [$ticketId => [$yesterday => '1h 15m']],
    ], $jar);
    check(
        'and a day typed into it becomes an entry',
        $gridSaved['status'] === 302 && str_contains(request($baseUrl . '/tickets/' . $ticketId, [], $jar)['body'], '1h 15m')
    );

    request($baseUrl . '/timesheet/grid', ['_token' => $token, 'week' => date('Y-m-d', strtotime('monday this week')), 'cells' => [$ticketId => [date('Y-m-d') => '9h']]], $jar);
    // An hour that is not billed, and the reports that add it all up.
    request($baseUrl . '/tickets/' . $ticketId . '/log', [
        '_token' => $token,
        'time' => '30m',
        'work_date' => date('Y-m-d'),
        'note' => '=HYPERLINK("http://example.test","click")',
        'billable_sent' => '1',
    ], $jar);
    $range = 'from=' . date('Y-m-d', strtotime('-7 days')) . '&to=' . date('Y-m-d') . '&project=' . $projectId;
    $report = request($baseUrl . '/reports?' . $range, [], $jar)['body'];
    check('the reports add the hours up by project', str_contains($report, 'Smoke test project') && str_contains($report, 'Not billable'));

    $csv = request($baseUrl . '/reports/export?format=csv&' . $range, [], $jar);
    check(
        'and hand them out as CSV that Excel reads as UTF-8, and cannot run a formula from',
        $csv['status'] === 200 && str_starts_with($csv['body'], "\xEF\xBB\xBF")
        && str_contains($csv['body'], $code . '-1') && str_contains($csv['body'], "'=HYPERLINK") && str_contains($csv['body'], ',no,')
    );

    $xlsx = request($baseUrl . '/reports/export?format=xlsx&' . $range, [], $jar);
    $sheetFile = tempnam(sys_get_temp_dir(), 'ct-xlsx-');
    file_put_contents($sheetFile, $xlsx['body']);
    $zip = new ZipArchive();
    $sheet = $zip->open($sheetFile) === true ? (string) $zip->getFromName('xl/worksheets/sheet1.xml') : '';
    $zip->close();
    @unlink($sheetFile);
    check('and as a spreadsheet', $xlsx['status'] === 200 && str_contains($sheet, $code . '-1'));

    $planned = request($baseUrl . '/planning', [
        '_token' => $token,
        'ticket' => $code . '-1',
        'starts_on' => date('Y-m-d', strtotime('monday next week')),
        'ends_on' => date('Y-m-d', strtotime('friday next week')),
        'per_day' => '2h',
        'note' => 'Planned by tests/smoke.php.',
        'back' => '/planning?week=' . date('Y-m-d', strtotime('monday next week')),
    ], $jar);
    check(
        'work can be planned ahead',
        $planned['status'] === 302
        && str_contains(request($baseUrl . '/planning?week=' . date('Y-m-d', strtotime('monday next week')), [], $jar)['body'], 'Planned by tests/smoke.php.')
    );

    check('the work types answer', str_contains(request($baseUrl . '/settings/work-types', [], $jar)['body'], 'Add a work type'));
    check('and the reports add up by them', request($baseUrl . '/reports?group=type', [], $jar)['status'] === 200);

    $teamWeek = request($baseUrl . '/timesheet/team', [], $jar);
    check('the team’s week answers, a row a person', $teamWeek['status'] === 200 && str_contains($teamWeek['body'], 'team-week__cell'));

    $calendarWeek = request($baseUrl . '/timesheet?view=calendar', [], $jar);
    check('the week can be shown as a calendar', $calendarWeek['status'] === 200 && str_contains($calendarWeek['body'], 'calendar__day'));

    // "Log time" from anywhere: the ticket offered as it is typed, logged by
    // its name, and back to the page it was logged from.
    $offered = json_decode(request($baseUrl . '/log/suggest?q=' . urlencode($code . '-1'), [], $jar)['body'], true);
    check('the log box offers a ticket by its name', ($offered['tickets'][0]['key'] ?? '') === $code . '-1');

    $quick = request($baseUrl . '/log', [
        '_token' => $token,
        'ticket' => $code . '-1',
        'time' => '20m',
        'work_date' => date('Y-m-d'),
        'note' => 'Logged from the top bar by tests/smoke.php.',
        'back' => '/reports',
    ], $jar);
    check(
        'and logs on it from any page, back to that page',
        $quick['status'] === 302 && str_contains($quick['headers'], '/reports')
        && str_contains(request($baseUrl . '/tickets/' . $ticketId, [], $jar)['body'], 'Logged from the top bar by tests/smoke.php.')
    );

    // Dragged on the calendar: the entry without a start, given one and a
    // new length, keeps its note — and a stretch past midnight is refused.
    $note = 'Logged from the top bar by tests/smoke.php.';
    $entryOf = static fn(string $page): string => preg_match('#<a class="calendar__chip[^>]*data-entry="(\d+)"[^>]*data-note="' . preg_quote($note, '#') . '"#', $page, $m)
        || preg_match('#<a class="calendar__block[^>]*data-entry="(\d+)"[^>]*data-note="' . preg_quote($note, '#') . '"#', $page, $m) ? $m[1] : '';
    $dragged = $entryOf(request($baseUrl . '/timesheet', [], $jar)['body']);
    check('the calendar offers an entry to drag', $dragged !== '');

    $placed = request($baseUrl . '/worklogs/' . $dragged . '/place', [
        '_token' => $token, 'work_date' => date('Y-m-d'), 'started_at' => '06:00', 'time' => '45m',
    ], $jar, ['Accept: application/json']);
    $calendarAfter = request($baseUrl . '/timesheet', [], $jar)['body'];
    check(
        'and a drop moves it, note and all',
        $placed['status'] === 200 && (json_decode($placed['body'], true)['ok'] ?? false) === true
        && (bool) preg_match('#class="calendar__block[^>]*data-entry="' . $dragged . '"[^>]*data-minutes="45" data-start="06:00"[^>]*data-note="' . preg_quote($note, '#') . '"#', $calendarAfter)
    );

    $pastMidnight = request($baseUrl . '/worklogs/' . $dragged . '/place', [
        '_token' => $token, 'work_date' => date('Y-m-d'), 'started_at' => '23:45', 'time' => '45m',
    ], $jar, ['Accept: application/json']);
    check(
        'but not past midnight',
        $pastMidnight['status'] === 422 && (json_decode($pastMidnight['body'], true)['ok'] ?? true) === false
    );

    // The calendar: a holiday far enough ahead that it cannot touch anybody's
    // real week, added and taken off again. Handing a week in is left to the
    // integration tests — it would close this account's hours for the rest
    // of the run.
    check('the weeks to approve answer', request($baseUrl . '/timesheet/approvals', [], $jar)['status'] === 200);
    check('the calendar answers', str_contains(request($baseUrl . '/settings', [], $jar)['body'], 'Closed hours'));

    request($baseUrl . '/settings/holidays', ['_token' => $token, 'day' => '2099-06-15', 'name' => 'Smoke test holiday'], $jar);
    check('a holiday can be added', str_contains(request($baseUrl . '/settings?year=2099', [], $jar)['body'], 'Smoke test holiday'));
    request($baseUrl . '/settings/holidays/delete', ['_token' => $token, 'day' => '2099-06-15'], $jar);
    check('and taken off again', !str_contains(request($baseUrl . '/settings?year=2099', [], $jar)['body'], 'Smoke test holiday'));
    check(
        'and a week that holds one says so',
        str_contains(request($baseUrl . '/timesheet?view=days', [], $jar)['body'], 'Days away')
    );

    // Again, now that the pages above have shown and used up its message.
    request($baseUrl . '/timesheet/grid', ['_token' => $token, 'week' => date('Y-m-d', strtotime('monday this week')), 'cells' => [$ticketId => [date('Y-m-d') => '9h']]], $jar);

    check(
        'while a day with several entries is left for the day list',
        str_contains(request($baseUrl . '/timesheet?view=grid', [], $jar)['body'], 'several entries on one ticket')
    );

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
    check(
        'and the timesheet offers the same one',
        str_contains($timesheet['body'], '/worklogs/' . $worklogId . '/delete')
    );

    $removedLog = request($baseUrl . '/worklogs/' . $worklogId . '/delete', [
        '_token' => $token,
        'back' => '/timesheet',
    ], $jar);
    check('an entry can be deleted', $removedLog['status'] === 302);
    check(
        'and the ticket total drops with it',
        !str_contains(request($baseUrl . '/tickets/' . $ticketId, [], $jar)['body'], '2h 15m')
    );

    // The smallest slice: five minutes is stored as the configured minimum,
    // and the person is told so rather than finding out at the end of the month.
    $tiny = request($baseUrl . '/tickets/' . $ticketId . '/log', [
        '_token' => $token,
        'time' => '5m',
        'work_date' => date('Y-m-d'),
    ], $jar);
    $afterTiny = request($baseUrl . '/tickets/' . $ticketId, [], $jar);
    check(
        'a sliver of time is rounded up to the minimum, and says so',
        $tiny['status'] === 302 && str_contains($afterTiny['body'], 'rounded up')
    );

    // An entry can be corrected in place, which it could not be before: the
    // route existed and no form pointed at it.
    preg_match('#action="[^"]*/worklogs/(\d+)"#', $afterTiny['body'], $m);
    $editId = (int) ($m[1] ?? 0);
    check('an entry of yours offers a correction form', $editId > 0);

    $corrected = request($baseUrl . '/worklogs/' . $editId, [
        '_token' => $token,
        'time' => '2h',
        'work_date' => date('Y-m-d'),
        'note' => 'Corrected by tests/smoke.php.',
        'back' => '/tickets/' . $ticketId,
    ], $jar);
    $afterCorrection = request($baseUrl . '/tickets/' . $ticketId, [], $jar);
    check(
        'and the correction is saved',
        $corrected['status'] === 302 && str_contains($afterCorrection['body'], 'Corrected by tests/smoke.php.')
    );

    // Hours are not deleted along with what they were logged against: they
    // are what gets reported and invoiced.
    request($baseUrl . '/tickets/' . $ticketId . '/delete', ['_token' => $token], $jar);
    check(
        'a ticket with hours on it cannot be deleted',
        request($baseUrl . '/tickets/' . $ticketId, [], $jar)['status'] === 200
    );

    request($baseUrl . '/projects/' . $projectId . '/delete', ['_token' => $token], $jar);
    check(
        'nor can a project with hours in it',
        request($baseUrl . '/projects/' . $projectId, [], $jar)['status'] === 200
    );

    // ---------------------------------------------------------------------
    // The API: a token made on the profile, and the same rules through it.
    // ---------------------------------------------------------------------
    $noToken = api($baseUrl . '/api/v1/me', '');
    check(
        'the API refuses a request without a token, in JSON',
        $noToken['status'] === 401 && ($noToken['json']['error']['status'] ?? 0) === 401
        && !str_contains($noToken['headers'], 'Set-Cookie')
    );
    check('and one with a made-up token', in_array(api($baseUrl . '/api/v1/me', 'ct_' . str_repeat('0', 40))['status'], [401, 429], true));

    request($baseUrl . '/profile/tokens', ['_token' => $token, 'name' => 'Smoke test'], $jar);
    $tokensPage = request($baseUrl . '/profile/tokens', [], $jar)['body'];
    preg_match('/value="(ct_[0-9a-f]{40})"/', $tokensPage, $m);
    $apiToken = $m[1] ?? '';
    check('a token can be made on the profile, and is shown once', $apiToken !== '');
    check('and not again', !str_contains(request($baseUrl . '/profile/tokens', [], $jar)['body'], $apiToken));

    $me = api($baseUrl . '/api/v1/me', $apiToken);
    check('with it, the API knows who is asking', $me['status'] === 200 && ($me['json']['data']['email'] ?? '') === $email);

    $listed = api($baseUrl . '/api/v1/tickets?project=' . $code, $apiToken);
    check(
        'lists the project\'s tickets, a page at a time',
        $listed['status'] === 200 && ($listed['json']['meta']['total'] ?? 0) >= 1
        && in_array($code . '-1', array_column($listed['json']['data'] ?? [], 'key'), true)
    );

    $made = api($baseUrl . '/api/v1/tickets', $apiToken, 'POST', [
        'project' => $code,
        'title' => 'Made through the API',
        'type' => 'bug',
        'labels' => ['api'],
        'estimate' => '2h',
    ]);
    $apiKey = (string) ($made['json']['data']['key'] ?? '');
    check('makes a ticket', $made['status'] === 201 && $apiKey !== '' && ($made['json']['data']['labels'] ?? []) === ['api']);
    check('and refuses one without a title', api($baseUrl . '/api/v1/tickets', $apiToken, 'POST', ['project' => $code])['status'] === 422);

    $version = (int) ($made['json']['data']['version'] ?? 0);
    $patched = api($baseUrl . '/api/v1/tickets/' . $apiKey, $apiToken, 'PATCH', ['priority' => 'high', 'status' => 'in_progress', 'version' => $version]);
    check(
        'changes only what it is sent',
        $patched['status'] === 200 && ($patched['json']['data']['priority'] ?? '') === 'high'
        && ($patched['json']['data']['status']['category'] ?? '') === 'in_progress'
        && ($patched['json']['data']['title'] ?? '') === 'Made through the API'
    );
    check(
        'and answers 409 to an edit made against an old version',
        api($baseUrl . '/api/v1/tickets/' . $apiKey, $apiToken, 'PATCH', ['title' => 'Stale', 'version' => $version])['status'] === 409
    );

    $commented = api($baseUrl . '/api/v1/tickets/' . $apiKey . '/comments', $apiToken, 'POST', ['body' => 'Said through the API']);
    check('takes a comment', $commented['status'] === 201 && ($commented['json']['data']['body'] ?? '') === 'Said through the API');

    $apiLog = api($baseUrl . '/api/v1/tickets/' . $apiKey . '/worklogs', $apiToken, 'POST', ['time' => '45m', 'note' => 'Through the API']);
    $apiLogId = (int) ($apiLog['json']['data']['id'] ?? 0);
    check('logs time', $apiLog['status'] === 201 && ($apiLog['json']['data']['minutes'] ?? 0) === 45);

    $week = api($baseUrl . '/api/v1/worklogs', $apiToken);
    check('lists one\'s own hours', $week['status'] === 200 && in_array($apiLogId, array_column($week['json']['data'] ?? [], 'id'), true));
    check('and deletes an entry', api($baseUrl . '/api/v1/worklogs/' . $apiLogId, $apiToken, 'DELETE')['status'] === 204);
    check('answers an unknown ticket with a JSON 404', ($missingTicket = api($baseUrl . '/api/v1/tickets/' . $code . '-99999', $apiToken))['status'] === 404 && isset($missingTicket['json']['error']));

    preg_match('#/profile/tokens/(\d+)/delete#', request($baseUrl . '/profile/tokens', [], $jar)['body'], $m);
    request($baseUrl . '/profile/tokens/' . ($m[1] ?? 0) . '/delete', ['_token' => $token], $jar);
    check('and a revoked token is refused from then on', in_array(api($baseUrl . '/api/v1/me', $apiToken)['status'], [401, 429], true));

    // ---------------------------------------------------------------------
    // Webhooks and GitHub: an address inside the network is refused, and a
    // signed push that names a ticket lands in its history.
    // ---------------------------------------------------------------------
    check('the webhooks page answers', request($baseUrl . '/settings/webhooks', [], $jar)['status'] === 200);

    request($baseUrl . '/settings/webhooks', ['_token' => $token, 'name' => 'Inside', 'url' => 'http://169.254.169.254/latest/meta-data'], $jar);
    check(
        'a webhook into the private network is refused',
        str_contains(request($baseUrl . '/settings/webhooks', [], $jar)['body'], 'private network')
    );

    $hooksPage = request($baseUrl . '/settings/webhooks', [], $jar)['body'];

    // Only on an installation where nobody has set GitHub up: a real secret
    // is not the smoke test's to replace.
    if (str_contains($hooksPage, '/settings/github') && !str_contains($hooksPage, 'id="github_secret"')) {
        request($baseUrl . '/settings/github', ['_token' => $token], $jar);
        preg_match('/id="github_secret"[^>]*value="([0-9a-f]+)"/', request($baseUrl . '/settings/webhooks', [], $jar)['body'], $m);
        $githubSecret = $m[1] ?? '';
        check('GitHub can be set up, with a secret of its own', $githubSecret !== '');

        $push = (string) json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['default_branch' => 'main'],
            'commits' => [['id' => bin2hex(random_bytes(20)), 'message' => 'Fixes ' . $apiKey . ' from tests/smoke.php', 'author' => ['email' => $email]]],
        ]);

        // request() only sends a body for form fields, so the push goes by hand.
        $send = static function (string $event, string $body, string $secret) use ($baseUrl): array {
            $handle = curl_init($baseUrl . '/integrations/github');
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-GitHub-Event: ' . $event,
                    'X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $body, $secret),
                ],
            ]);
            $text = (string) curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

            return ['status' => $status, 'json' => json_decode($text, true)];
        };

        check('a push with the wrong signature is refused', $send('push', $push, 'wrong')['status'] === 401);
        check('GitHub\'s ping is answered', $send('ping', '{}', $githubSecret)['status'] === 200);

        $pushed = $send('push', $push, $githubSecret);
        check(
            'a push that fixes a ticket closes it',
            $pushed['status'] === 200 && in_array($apiKey, $pushed['json']['closed'] ?? [], true)
        );
        check(
            'and the commit is in the ticket\'s history',
            str_contains(request($baseUrl . '/tickets/' . (int) ($made['json']['data']['id'] ?? 0), [], $jar)['body'], 'mentioned it in a commit')
        );

        request($baseUrl . '/settings/github', ['_token' => $token, 'off' => '1'], $jar);
        check('and GitHub can be turned off again', $send('ping', '{}', $githubSecret)['status'] === 404);
    }

    // ---------------------------------------------------------------------
    // A picture, and two-step sign-in: set up, used with a recovery code
    // from a fresh browser, and turned off again.
    // ---------------------------------------------------------------------
    $picture = imagecreatetruecolor(300, 200);
    imagefill($picture, 0, 0, (int) imagecolorallocate($picture, 26, 95, 191));
    $picturePath = tempnam(sys_get_temp_dir(), 'ct-face-') . '.png';
    imagepng($picture, $picturePath);

    $handle = curl_init($baseUrl . '/profile/avatar');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['_token' => $token, 'avatar' => new CURLFile($picturePath, 'image/png', 'me.png')],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
    ]);
    curl_exec($handle);
    curl_close($handle);
    @unlink($picturePath);

    preg_match('#src="([^"]*/avatars/\d+/[^"]+)"#', request($baseUrl . '/profile', [], $jar)['body'], $m);
    $face = $m[1] ?? '';
    check('a picture can be uploaded to the profile', $face !== '');
    $served = $face === '' ? ['status' => 0, 'headers' => ''] : request($face, [], $jar);
    check('and is served, as a picture', $served['status'] === 200 && preg_match('#Content-Type: image/(webp|png)#i', $served['headers']) === 1);
    check('but not to somebody signed out', $face !== '' && request($face)['status'] === 302);
    request($baseUrl . '/profile/avatar/delete', ['_token' => $token], $jar);
    check('and removed again', !str_contains(request($baseUrl . '/profile', [], $jar)['body'], '/avatars/'));

    request($baseUrl . '/profile/two-factor/start', ['_token' => $token], $jar);
    preg_match('#<code class="secret-key">([A-Z2-7 ]+)</code>#', request($baseUrl . '/profile/two-factor', [], $jar)['body'], $m);
    $totpSecret = str_replace(' ', '', $m[1] ?? '');
    check('two-step sign-in shows a key to scan', $totpSecret !== '');

    // The code of the step before, so the one a sign-in below would need
    // is not used up by setting it up.
    request($baseUrl . '/profile/two-factor/confirm', [
        '_token' => $token,
        'code' => \CantoTrack\Core\Totp::code($totpSecret, \CantoTrack\Core\Totp::step() - 1),
    ], $jar);
    preg_match_all('#<li><code>([a-z2-9]{5}-[a-z2-9]{5})</code></li>#', request($baseUrl . '/profile/two-factor', [], $jar)['body'], $m);
    $recovery = $m[1];
    check('and once a code from it is typed in, it is on, with ten recovery codes', count($recovery) === 10);

    $secondJar = tempnam(sys_get_temp_dir(), 'ct-smoke-');
    preg_match('/name="_token" value="([^"]+)"/', request($baseUrl . '/login', [], $secondJar)['body'], $m);
    $secondToken = $m[1] ?? '';
    $firstStep = request($baseUrl . '/login', ['_token' => $secondToken, 'email' => $email, 'password' => $password], $secondJar);
    check('the password alone then leads to the second question', $firstStep['status'] === 302 && str_contains($firstStep['headers'], '/login/code'));
    check('and does not sign in', request($baseUrl . '/', [], $secondJar)['status'] === 302);

    preg_match('/name="_token" value="([^"]+)"/', request($baseUrl . '/login/code', [], $secondJar)['body'], $m);
    $secondToken = $m[1] ?? $secondToken;
    check('a wrong code is refused', request($baseUrl . '/login/code', ['_token' => $secondToken, 'code' => '000000'], $secondJar)['status'] === 401);
    $signedIn = request($baseUrl . '/login/code', ['_token' => $secondToken, 'code' => $recovery[0] ?? ''], $secondJar);
    check('a recovery code signs in', $signedIn['status'] === 302 && request($baseUrl . '/', [], $secondJar)['status'] === 200);
    @unlink($secondJar);

    request($baseUrl . '/profile/two-factor/disable', ['_token' => $token, 'password' => $password], $jar);
    check('and it can be turned off with the password', str_contains(request($baseUrl . '/profile/two-factor', [], $jar)['body'], '/profile/two-factor/start'));

    // ---------------------------------------------------------------------
    // Tickets from a spreadsheet: uploaded, looked at, and imported.
    // ---------------------------------------------------------------------
    $csvPath = tempnam(sys_get_temp_dir(), 'ct-csv-');
    file_put_contents($csvPath, "Cím;Prioritás;Címkék\nImported by tests/smoke.php;magas;smoke\n;;\n");
    $handle = curl_init($baseUrl . '/projects/' . $projectId . '/import');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['_token' => $token, 'file' => new CURLFile($csvPath, 'text/csv', 'tickets.csv')],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
    ]);
    $uploaded = (string) curl_exec($handle);
    curl_close($handle);
    @unlink($csvPath);

    check('a CSV can be uploaded for import', str_contains($uploaded, '/import/preview'));
    $importPreview = request($baseUrl . '/projects/' . $projectId . '/import/preview', [], $jar)['body'];
    check('and is shown before anything is made', str_contains($importPreview, 'Imported by tests/smoke.php') && str_contains($importPreview, 'Import 1 ticket'));

    request($baseUrl . '/projects/' . $projectId . '/import/confirm', ['_token' => $token], $jar);
    check(
        'and then becomes tickets',
        str_contains(request($baseUrl . '/tickets?project=' . $projectId . '&q=Imported+by+tests', [], $jar)['body'], 'Imported by tests/smoke.php')
    );

    // Tidy up after itself: the hours first, one by one — the slow way,
    // deliberately — and then the project takes the epic and the ticket.
    $page = request($baseUrl . '/tickets/' . $ticketId, [], $jar);
    preg_match_all('#/worklogs/(\d+)/delete#', $page['body'], $all);
    foreach (array_unique($all[1]) as $id) {
        request($baseUrl . '/worklogs/' . $id . '/delete', ['_token' => $token], $jar);
    }

    $removed = request($baseUrl . '/projects/' . $projectId . '/delete', ['_token' => $token], $jar);
    check('once the hours are gone, the project can be deleted', $removed['status'] === 302);
    check(
        'and its ticket is gone with it',
        request($baseUrl . '/tickets/' . $ticketId, [], $jar)['status'] === 404
    );

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
    check(
        'and the password is shown once, to hand over',
        str_contains($afterAdd['body'], 'Hand this over') && str_contains($afterAdd['body'], $colleague)
    );
    check(
        'but not again on the next look',
        !str_contains(request($baseUrl . '/people', [], $jar)['body'], 'Hand this over')
    );

    // The colleague, in a browser of their own, replaces the password the
    // administrator read out with one only they know.
    preg_match('#<code class="handover">([^<]+)</code>#', $afterAdd['body'], $m);
    $handedOver = html_entity_decode($m[1] ?? '', ENT_QUOTES);
    $theirJar = tempnam(sys_get_temp_dir(), 'ct-smoke-');

    $theirLogin = request($baseUrl . '/login', [], $theirJar);
    preg_match('/name="_token" value="([^"]+)"/', $theirLogin['body'], $m);
    $theirToken = $m[1] ?? '';
    $theirIn = request($baseUrl . '/login', [
        '_token' => $theirToken,
        'email' => $colleague,
        'password' => $handedOver,
    ], $theirJar);
    check('the colleague can sign in with the password they were handed', $theirIn['status'] === 302);

    $theirProfile = request($baseUrl . '/profile', [], $theirJar);
    preg_match('/name="_token" value="([^"]+)"/', $theirProfile['body'], $m);
    $theirToken = $m[1] ?? $theirToken;
    check(
        'and has a profile page of their own',
        $theirProfile['status'] === 200 && str_contains($theirProfile['body'], $colleague)
    );

    $wrongCurrent = request($baseUrl . '/profile/password', [
        '_token' => $theirToken,
        'current_password' => 'not it',
        'new_password' => 'correct horse battery staple',
        'new_password_again' => 'correct horse battery staple',
    ], $theirJar);
    check(
        'changing the password asks for the current one',
        $wrongCurrent['status'] === 422 && str_contains($wrongCurrent['body'], 'not your current password')
    );

    $changed = request($baseUrl . '/profile/password', [
        '_token' => $theirToken,
        'current_password' => $handedOver,
        'new_password' => 'correct horse battery staple',
        'new_password_again' => 'correct horse battery staple',
    ], $theirJar);
    check('and with it, the password is changed', $changed['status'] === 302);

    request($baseUrl . '/logout', ['_token' => $theirToken], $theirJar);
    $theirLogin = request($baseUrl . '/login', [], $theirJar);
    preg_match('/name="_token" value="([^"]+)"/', $theirLogin['body'], $m);
    $oldAgain = request($baseUrl . '/login', [
        '_token' => $m[1] ?? '',
        'email' => $colleague,
        'password' => $handedOver,
    ], $theirJar);
    $newOne = request($baseUrl . '/login', [
        '_token' => $m[1] ?? '',
        'email' => $colleague,
        'password' => 'correct horse battery staple',
    ], $theirJar);
    check(
        'after which the old one no longer works and the new one does',
        $oldAgain['status'] === 401 && $newOne['status'] === 302,
        'old ' . $oldAgain['status'] . ', new ' . $newOne['status']
    );
    @unlink($theirJar);

    // The limit in front of the login form, on an address nobody has — so a
    // run of this cannot lock a real account out.
    if ($checkThrottle) {
        $nobody = 'nobody-' . random_int(10000, 99999) . '@example.test';
        $throttleJar = tempnam(sys_get_temp_dir(), 'ct-smoke-');
        $page = request($baseUrl . '/login', [], $throttleJar);
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $m);

        for ($i = 0; $i < 5; $i++) {
            request($baseUrl . '/login', ['_token' => $m[1] ?? '', 'email' => $nobody, 'password' => 'guess ' . $i], $throttleJar);
        }

        $sixth = request($baseUrl . '/login', ['_token' => $m[1] ?? '', 'email' => $nobody, 'password' => 'guess 6'], $throttleJar);
        check(
            'the sixth failed sign-in in a row is refused before it is tried',
            $sixth['status'] === 429 && str_contains($sixth['body'], 'Too many failed attempts'),
            'status ' . $sixth['status']
        );
        @unlink($throttleJar);
    }

    $duplicate = request($baseUrl . '/people/create', [
        '_token' => $token,
        'name' => 'The same address',
        'email' => $colleague,
        'role' => 'member',
    ], $jar);
    check(
        'an address that already signs somebody in is refused',
        str_contains($duplicate['body'], 'already signs in with that address')
    );

    /*
     * Which row is whose. Taken from the row that carries the address rather
     * than from its position: the table is sorted by name, so the first row is
     * whoever happens to sort first — which is how the first version of these
     * checks ended up editing the wrong person.
     */
    $rowId = static function (string $html, string $address): int {
        preg_match(
            '#<tr[^>]*>(?:(?!</tr>).)*?' . preg_quote($address, '#') . '(?:(?!</tr>).)*?/people/(\d+)/edit#s',
            $html,
            $found
        );

        return (int) ($found[1] ?? 0);
    };

    $listing = request($baseUrl . '/people', [], $jar);
    $myId = $rowId($listing['body'], $email);
    $colleagueId = $rowId($listing['body'], $colleague);

    check(
        'each person can be picked out of the list by their address',
        $myId > 0 && $colleagueId > 0 && $myId !== $colleagueId,
        'mine ' . $myId . ', theirs ' . $colleagueId
    );

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
    check(
        'an administrator cannot take the role off themselves',
        str_contains($selfDemote['body'], 'cannot take the administrator role off yourself')
    );

    $selfOff = request($baseUrl . '/people/' . $myId, [
        '_token' => $token,
        'name' => 'Still an administrator',
        'email' => $email,
        'role' => 'admin',
    ], $jar);
    check(
        'nor deactivate their own account',
        str_contains($selfOff['body'], 'cannot deactivate your own account')
    );

    // Somebody else, on the other hand, can be deactivated — which is what
    // happens instead of deleting them.
    $deactivated = request($baseUrl . '/people/' . $colleagueId, [
        '_token' => $token,
        'name' => 'Smoke Test Colleague',
        'email' => $colleague,
        'role' => 'member',
    ], $jar);
    check('somebody else can be deactivated rather than deleted', $deactivated['status'] === 302);
    check(
        'and then reads as deactivated',
        str_contains(request($baseUrl . '/people', [], $jar)['body'], 'Deactivated')
    );

    // A deactivated colleague is no longer offered as an assignee, which is the
    // point of deactivating them.
    // By id rather than by name: an earlier run of this script leaves a
    // deactivated colleague behind with the same name, and a check that reads
    // names would be answered by somebody else's leftovers.
    // Inside the assignee list only: the project list on the same form has
    // ids of its own, and project 31 is not person 31.
    preg_match('#<select id="assignee_id".*?</select>#s', request($baseUrl . '/tickets/create', [], $jar)['body'], $m);
    check(
        'a deactivated person is off the assignee list',
        isset($m[0]) && !str_contains($m[0], 'value="' . $colleagueId . '"')
    );

    // A GET must not sign anybody out: an <img src=".../logout"> on any page
    // on the internet would otherwise do it.
    $getOut = request($baseUrl . '/logout', [], $jar);
    check(
        'a GET to the sign-out address does not sign out',
        $getOut['status'] === 405 && request($baseUrl . '/', [], $jar)['status'] === 200,
        'status ' . $getOut['status']
    );

    $out = request($baseUrl . '/logout', ['_token' => $token], $jar);
    check(
        'signing out redirects to the login page',
        $out['status'] === 302 && str_contains($out['headers'], '/login')
    );

    $after = request($baseUrl . '/', [], $jar);
    check('and the session no longer opens the dashboard', $after['status'] === 302);
}

@unlink($jar);

printf('%s%d passed, %d failed%s', PHP_EOL, $passed, $failed, PHP_EOL);
exit($failed === 0 ? 0 : 1);
