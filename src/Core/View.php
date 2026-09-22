<?php

namespace CantoTrack\Core;

use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The template engine, set up once and handed the few things every page needs.
 *
 * Templates get the signed-in user, the base URL and the CSRF token as globals
 * rather than through every controller: those three are on every page, and a
 * controller that forgets to pass the token renders a form that cannot be
 * submitted.
 */
class View
{
    private static ?Environment $twig = null;

    public static function render(string $template, array $context = []): void
    {
        echo self::twig()->render($template, $context);
    }

    public static function twig(): Environment
    {
        if (self::$twig !== null) {
            return self::$twig;
        }

        $root = dirname(__DIR__, 2);
        $loader = new FilesystemLoader($root . '/src/View');

        $debug = Config::bool('app.debug', false);

        $twig = new Environment($loader, [
            'debug' => $debug,
            // Compiled templates are written only when debug is off. On a
            // development machine the cache is the thing that shows yesterday's
            // markup after an edit; on a live server it is the difference
            // between compiling every page and none.
            'cache' => $debug ? false : $root . '/var/cache/twig',
            'strict_variables' => $debug,
            'autoescape' => 'html',
        ]);

        if ($debug) {
            $twig->addExtension(new DebugExtension());
        }

        $twig->addGlobal('app_name', Config::get('app.name', 'CantoTrack'));
        $twig->addGlobal('base_url', rtrim((string) Config::get('app.base_url', ''), '/'));
        $twig->addGlobal('current_user', Auth::user());
        $twig->addGlobal('is_admin', Auth::isAdmin());
        $twig->addGlobal('csrf_token', Csrf::token());
        $twig->addGlobal('current_path', Router::normalise($_SERVER['REQUEST_URI'] ?? '/'));
        $twig->addGlobal('flash', Session::takeFlash());

        // Minutes are how time is stored everywhere in this application; hours
        // and minutes are how people read it. One filter, so "7h 30m" cannot
        // come out differently on two pages.
        $twig->addFilter(new TwigFilter('duration', static fn(?int $minutes): string => Format::duration((int) $minutes)));
        $twig->addFilter(new TwigFilter('hours', static fn(?int $minutes): string => Format::hours((int) $minutes)));
        $twig->addFilter(new TwigFilter('day', static fn(?string $date): string => Format::day((string) $date)));
        $twig->addFilter(new TwigFilter('initials', static fn(?string $name): string => Format::initials((string) $name)));

        $twig->addFunction(new TwigFunction('url', static fn(string $path = ''): string =>
            rtrim((string) Config::get('app.base_url', ''), '/') . '/' . ltrim($path, '/')));

        return self::$twig = $twig;
    }
}
