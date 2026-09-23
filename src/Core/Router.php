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

    /** The API's verbs. HTML forms only ever send GET and POST. */
    public function patch(string $path, callable $handler): void
    {
        $this->routes['PATCH'][$path] = $handler;
    }

    public function delete(string $path, callable $handler): void
    {
        $this->routes['DELETE'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = self::normalise($uri);

        foreach ($this->routes[$method] ?? [] as $route => $handler) {
            $parameters = self::match($route, $path);

            if ($parameters !== null) {
                $handler(...$parameters);

                return;
            }
        }

        // The address exists, but not for this verb: a GET to something that
        // only takes a post. Saying so is more use than "nothing here".
        foreach ($this->routes as $otherMethod => $routes) {
            if ($otherMethod === $method) {
                continue;
            }

            foreach (array_keys($routes) as $route) {
                if (self::match($route, $path) !== null) {
                    throw new HttpError(405, __('That address does not take a request like this one.'));
                }
            }
        }

        throw HttpError::notFound(__('There is no such page.'));
    }

    /**
     * The parameters of a route that matches a path, or null.
     *
     * A parameter matches anything but a slash, so `/tickets/{id}` does not
     * swallow `/tickets/12/log`.
     *
     * @return list<string>|null
     */
    private static function match(string $route, string $path): ?array
    {
        $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route);

        if (preg_match('#^' . $pattern . '$#', $path, $matches) !== 1) {
            return null;
        }

        return array_values(array_filter($matches, static fn($key) => !is_int($key), ARRAY_FILTER_USE_KEY));
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
}
