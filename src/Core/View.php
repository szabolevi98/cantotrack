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

        $twig->addFunction(new TwigFunction('asset', [self::class, 'asset']));

        // Every sentence on the screen goes through this, so the interface can
        // be read in more than one language. See I18n for why the English is
        // the key.
        $twig->addFilter(new TwigFilter('t', static fn(?string $text, array $params = []): string =>
            I18n::translate((string) $text, $params)));
        $twig->addFunction(new TwigFunction('plural', static fn(string $one, string $many, int $count, array $params = []): string =>
            I18n::plural($one, $many, $count, $params)));
        $twig->addGlobal('locale', I18n::locale());
        $twig->addGlobal('theme', (string) (Auth::user()['theme'] ?? 'system'));

        return self::$twig = $twig;
    }

    /**
     * The address of a file under web/assets, with a fingerprint of its
     * contents on the end.
     *
     * The fingerprint is what lets the stylesheet be cached for as long as the
     * CDN in front of the site likes: a deployment that changes the file
     * changes the address, so nobody is shown yesterday's CSS against today's
     * markup. Without it, a release looked broken until the cache ran out.
     */
    public static function asset(string $path): string
    {
        static $fingerprints = [];

        $path = ltrim($path, '/');
        $file = dirname(__DIR__, 2) . '/web/assets/' . $path;

        if (!isset($fingerprints[$path])) {
            $fingerprints[$path] = is_file($file) ? substr((string) md5_file($file), 0, 10) : '0';
        }

        return rtrim((string) Config::get('app.base_url', ''), '/') . '/assets/' . $path . '?v=' . $fingerprints[$path];
    }
}
