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
    check('which greets the signed-in person',
        str_contains($dashboard['body'], 'Dashboard') && str_contains($dashboard['body'], $email));

    $out = request($baseUrl . '/logout', [], $jar);
    check('signing out redirects to the login page',
        $out['status'] === 302 && str_contains($out['headers'], '/login'));

    $after = request($baseUrl . '/', [], $jar);
    check('and the session no longer opens the dashboard', $after['status'] === 302);
}

@unlink($jar);

printf('%s%d passed, %d failed%s', PHP_EOL, $passed, $failed, PHP_EOL);
exit($failed === 0 ? 0 : 1);
