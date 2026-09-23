<?php

/**
 * The router for PHP's built-in web server, which has no .htaccess:
 *
 *   php -S 127.0.0.1:8080 -t web bin/dev-router.php
 *
 * A file that exists under web/ is served as it is; everything else goes to
 * the front controller — which is exactly what web/.htaccess says to Apache.
 * Used by CI to run the smoke test without an Apache, and handy for a quick
 * look without XAMPP.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath(__DIR__ . '/../web' . $path);
$web = realpath(__DIR__ . '/../web');

if ($file !== false && $web !== false && str_starts_with($file, $web) && is_file($file)) {
    return false;
}

// The front controller works out its own directory from SCRIPT_NAME, and the
// built-in server would otherwise report this router there.
$_SERVER['SCRIPT_NAME'] = '/index.php';

require __DIR__ . '/../web/index.php';
