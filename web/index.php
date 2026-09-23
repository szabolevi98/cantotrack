<?php

/**
 * The front controller: everything that is not a real file on disk arrives here.
 *
 * The order below matters and is deliberate — configuration, then error
 * handling, then the session, then the one CSRF gate, and only then the routes.
 * The routes themselves are in src/routes.php.
 */

use CantoTrack\Core\Config;
use CantoTrack\Core\ConflictError;
use CantoTrack\Core\Csp;
use CantoTrack\Core\Csrf;
use CantoTrack\Core\ErrorPage;
use CantoTrack\Core\HttpError;
use CantoTrack\Core\I18n;
use CantoTrack\Core\Logger;
use CantoTrack\Core\Router;
use CantoTrack\Core\Session;
use CantoTrack\Core\ValidationError;
use CantoTrack\Service\Notifier;
use CantoTrack\Service\Webhooks;

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

/*
 * A fatal error — a parse error in a file loaded late, memory running out — is
 * neither of the two handlers above: PHP simply stops. Without this it stopped
 * silently, with an empty 500 and nothing in the log, which is the one kind of
 * failure that most needs writing down.
 */
register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    Logger::error('Fatal: ' . $error['message'], ['file' => $error['file'], 'line' => $error['line']]);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Something went wrong. It has been written to the log.';
    }
});

/*
 * One place every failure is answered from. An HttpError is a request that
 * asked for something that is not there or not theirs — expected, and not
 * logged. Anything else is a fault, and goes to the log with where it happened.
 */
set_exception_handler(static function (\Throwable $e): void {
    if ($e instanceof HttpError) {
        ErrorPage::render($e->status, $e->getMessage());

        return;
    }

    // The forms catch these where they happen and say them beside the field;
    // the API lets them through to here, where they are what they are: a
    // request that did not make sense, and one that lost a race.
    if ($e instanceof ValidationError) {
        ErrorPage::render(422, $e->getMessage());

        return;
    }

    if ($e instanceof ConflictError) {
        ErrorPage::render(409, $e->getMessage());

        return;
    }

    Logger::error('Uncaught: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'path' => $_SERVER['REQUEST_URI'] ?? '',
    ]);

    ErrorPage::render(500, __('Something went wrong. It has been written to the log.'));
});

/*
 * No page of this application has any business inside somebody else's frame,
 * and none runs a script it did not ship itself — see Csp for the policy, and
 * for the one page that widens it.
 */
header('X-Frame-Options: DENY');
Csp::send();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$path = Router::normalise($_SERVER['REQUEST_URI'] ?? '/');
$exempt = str_starts_with($path, '/api/') || str_starts_with($path, '/integrations/');

// The API and the integrations get no session: they prove who they are on
// every request, and a cookie handed to a script is one more thing to leak.
if (!$exempt) {
    Session::start();

    // The language chosen before signing in; a signed-in person's profile
    // says its own when the user is read.
    $chosen = Session::get('_locale');
    if (is_string($chosen)) {
        I18n::setLocale($chosen);
    }
}

// Whoever has to hear about a change is told by the Notifier, which listens
// to every change the services make; so are the webhooks.
Notifier::register();
Webhooks::register();

/*
 * One CSRF gate for every post, rather than a check in each controller. A check
 * that has to be remembered in thirty places is a check that is missing in one
 * of them, and that one is the form that deletes something.
 *
 * The token comes in the form, or — for the few requests the page's own
 * script sends — in a header. The API and the incoming integrations are the
 * exceptions: they carry no session cookie at all, and prove who they are with
 * a token or a signature of their own, which is checked where they arrive.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$exempt) {
    // A post bigger than post_max_size reaches PHP empty — no fields, so no
    // token either — and would be answered "your session expired". Said as
    // what it is instead.
    if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
        && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')
        && !isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        throw new HttpError(413, __('That was more than the server takes in one go. Try a smaller file.'));
    }

    $given = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (!Csrf::validate(is_string($given) ? $given : null)) {
        // 403 rather than one of the codes a framework invented for this: an
        // unknown status code is answered by Apache with a 500 of its own,
        // which turns "your session expired" into "something is broken".
        throw HttpError::forbidden(__('Your session expired. Go back, reload the page and try again.'));
    }
}

$router = new Router();
require dirname(__DIR__) . '/src/routes.php';

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
