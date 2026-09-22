<?php

namespace CantoTrack\Core;

/**
 * The routing table: a path, a method, and what to call.
 *
 * Routes are matched in the order they were registered, and `{name}` in a path
 * becomes a parameter. That is the whole of it — an application with a few dozen
 * routes does not need a compiler, and a table that can be read top to bottom is
 * worth more here than one that is fast.
 */
class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = self::normalise($uri);

        foreach ($this->routes[$method] ?? [] as $route => $handler) {
            // A parameter matches anything but a slash, so `/tickets/{id}` does
            // not swallow `/tickets/12/log`.
            $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route);

            if (preg_match('#^' . $pattern . '$#', $path, $matches) === 1) {
                $parameters = array_filter($matches, static fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY);
                $handler(...array_values($parameters));

                return;
            }
        }

        self::notFound();
    }

    /**
     * The request path without the front controller's own directory and without
     * a trailing slash.
     *
     * The directory matters on a development machine, where the application
     * lives at http://localhost/cantotrack/web rather than at a domain root.
     * Without stripping it every route would have to be written twice.
     */
    public static function normalise(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return rtrim($path, '/') ?: '/';
    }

    /**
     * The 404 page draws itself, with its colours written out rather than taken
     * from the stylesheet: on an address that does not exist there is no promise
     * that a static file under it resolves either. If the palette changes, these
     * few values have to be carried over by hand.
     */
    private static function notFound(): void
    {
        http_response_code(404);

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>404 — CantoTrack</title>'
            . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'background:#f4f6fb;color:#16202f;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}'
            . 'main{text-align:center;padding:48px}h1{font-size:64px;margin:0;color:#1a5fbf}'
            . 'p{color:#5a6474}a{color:#1a5fbf}</style></head>'
            . '<body><main><h1>404</h1><p>There is no such page.</p>'
            . '<p><a href="' . htmlspecialchars((string) Config::get('app.base_url', ''), ENT_QUOTES, 'UTF-8')
            . '/">Back to the board</a></p></main></body></html>';
    }
}
