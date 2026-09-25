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
            // A compiled template is checked against its source on every
            // use. Twig only does that by default in debug mode, and without
            // it a `git pull` of new templates went on serving the old ones —
            // a macro that did not exist yet, from a file compiled a release ago.
            // One stat per template is the whole cost.
            'auto_reload' => true,
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
        // Whether the signed-in person may change the work, or is a guest who
        // reads and comments. The pages leave out what a guest would only be
        // refused.
        $twig->addGlobal('can_work', Auth::check() && (Auth::user()['role'] ?? '') !== 'guest');
        $twig->addGlobal('csrf_token', Csrf::token());
        $twig->addGlobal('current_path', Router::normalise($_SERVER['REQUEST_URI'] ?? '/'));
        // The rest of the address, for a form that should come back to the
        // same filtered list it was sent from.
        $twig->addGlobal('current_query', (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY));
        $twig->addGlobal('flash', Session::takeFlash());
        $twig->addFunction(new TwigFunction('currency_code', static fn(): string => \CantoTrack\Service\Money::currency()));
        // A delete that can still be taken back, offered on the next page.
        $twig->addFunction(new TwigFunction('undo_offer', static fn(): ?array => \CantoTrack\Service\Undo::offer(Auth::id())));

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

        // A list's column headers that put it in order — see Sort.
        $twig->addFunction(new TwigFunction('sort_link', [Sort::class, 'link'], ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('sort_aria', [Sort::class, 'aria'], ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('sort_inputs', [Sort::class, 'inputs'], ['is_safe' => ['html']]));

        // What people typed, as HTML. Safe to print unescaped only because the
        // renderer escapes any HTML in the text itself — see Markdown.
        $twig->addFilter(new TwigFilter('markdown', static fn(?string $text): string => Markdown::toHtml($text), ['is_safe' => ['html']]));
        $twig->addFilter(new TwigFilter('money', static fn(float|int|string|null $amount, ?string $currency = null): string => \CantoTrack\Service\Money::format((float) $amount, $currency)));
        // A stretch of a text around the words searched for, with them marked.
        $twig->addFilter(new TwigFilter('excerpt', static fn(?string $text, string $words, int $length = 180): string => FullText::excerpt((string) $text, $words, $length), ['is_safe' => ['html']]));
        // The sidebar's saved filters, read only when a page that has the
        // sidebar is drawn — the login page and the error page do not ask.
        $twig->addFunction(new TwigFunction('saved_filters', static fn(): array =>
            Auth::check() ? (new \CantoTrack\Model\SavedFilterRepository())->visibleTo((int) Auth::id()) : []));
        $twig->addFunction(new TwigFunction('running_timer', static fn(): ?array =>
            Auth::check() ? (new \CantoTrack\Service\TimerService())->running((int) Auth::id()) : null));
        $twig->addFunction(new TwigFunction('unread_notifications', static fn(): int =>
            Auth::check() ? (new \CantoTrack\Model\NotificationRepository())->unreadCount((int) Auth::id()) : 0));
        $twig->addFunction(new TwigFunction('work_types', [\CantoTrack\Model\WorkTypeRepository::class, 'active']));
        $twig->addFunction(new TwigFunction('avatar_url', [\CantoTrack\Service\Avatars::class, 'url']));
        // Email the mail server would not take, for the administrators' sidebar.
        $twig->addFunction(new TwigFunction('failed_mail', static fn(): int => Auth::isAdmin() ? (new \CantoTrack\Service\Outbox())->counts()['failed'] : 0));
        $twig->addFunction(new TwigFunction('pending_weeks', static fn(): int =>
            Auth::isAdmin() ? count((new \CantoTrack\Service\WeekReview())->pending()) : 0));
        $twig->addFunction(new TwigFunction('burndown_chart', [Chart::class, 'burndown'], ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('velocity_chart', [Chart::class, 'velocity'], ['is_safe' => ['html']]));
        $twig->addFilter(new TwigFilter('bytes', static fn(?int $bytes): string => Format::bytes((int) $bytes)));
        $twig->addFilter(new TwigFilter('label_colour', static fn(?string $name): string => \CantoTrack\Model\LabelRepository::colour((string) $name)));
        // The labels come out of the database as one string, a name per line.
        $twig->addFilter(new TwigFilter('lines', static fn(?string $text): array =>
            $text === null || $text === '' ? [] : explode("\n", $text)));

        // Every sentence on the screen goes through this, so the interface can
        // be read in more than one language. See I18n for why the English is
        // the key.
        $twig->addFilter(new TwigFilter('t', static fn(?string $text, array $params = []): string =>
            I18n::translate((string) $text, $params)));
        $twig->addFunction(new TwigFunction('plural', static fn(string $one, string $many, int $count, array $params = []): string =>
            I18n::plural($one, $many, $count, $params)));
        $twig->addGlobal('locale', I18n::locale());
        $twig->addGlobal('locales', I18n::LOCALES);
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
