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

    $timesheet = request($baseUrl . '/timesheet', [], $jar);
    check('the timesheet answers', $timesheet['status'] === 200, 'status ' . $timesheet['status']);
    check(
        'and this week shows the ticket the time went on',
        str_contains($timesheet['body'], $code . '-1')
        && str_contains($timesheet['body'], 'Logged by tests/smoke.php.')
    );
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
