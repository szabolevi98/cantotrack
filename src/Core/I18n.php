<?php

namespace CantoTrack\Core;

/**
 * The words on the screen, in the language of whoever is reading them.
 *
 * The English text is the key. A template says `{{ 'Log time'|t }}` and PHP says
 * `__('Ticket saved.')`; the Hungarian catalogue maps those sentences to its
 * own. Keys made of the English itself rather than of codes like
 * `ticket.saved`, because a template full of codes cannot be read without the
 * catalogue open beside it, and a missing translation falls back to a sentence
 * rather than to `ticket.saved` on somebody's screen.
 *
 * Placeholders are `{name}` and are filled after translation, so a translator
 * can move them: "{count} tickets" is "{count} ticket" in Hungarian, which uses
 * the singular after a number.
 */
class I18n
{
    /** The languages the interface can be read in, by their own names. */
    public const LOCALES = ['en' => 'English', 'hu' => 'Magyar'];

    private static ?string $locale = null;

    /** @var array<string, array<string, string>> */
    private static array $catalogues = [];

    public static function setLocale(string $locale): void
    {
        self::$locale = array_key_exists($locale, self::LOCALES) ? $locale : 'en';
    }

    public static function locale(): string
    {
        if (self::$locale === null) {
            // Before the configuration is loaded (a command-line script that
            // failed early, a unit test) there is only English.
            try {
                self::setLocale((string) Config::get('app.locale', 'en'));
            } catch (\RuntimeException) {
                return 'en';
            }
        }

        return (string) self::$locale;
    }

    /** @param array<string, scalar|null> $params */
    public static function translate(string $text, array $params = []): string
    {
        $translated = self::catalogue(self::locale())[$text] ?? $text;

        if ($params === []) {
            return $translated;
        }

        $replace = [];
        foreach ($params as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }

        return strtr($translated, $replace);
    }

    /**
     * One of two sentences depending on a count. English needs both; the
     * Hungarian catalogue maps both keys to the same singular form.
     *
     * @param array<string, scalar|null> $params
     */
    public static function plural(string $one, string $many, int $count, array $params = []): string
    {
        return self::translate($count === 1 ? $one : $many, $params + ['count' => $count]);
    }

    /** @return array<string, string> */
    public static function catalogue(string $locale): array
    {
        if ($locale === 'en') {
            return [];
        }

        if (!isset(self::$catalogues[$locale])) {
            $file = dirname(__DIR__, 2) . '/lang/' . $locale . '.php';
            $loaded = is_file($file) ? require $file : [];
            self::$catalogues[$locale] = is_array($loaded) ? $loaded : [];
        }

        return self::$catalogues[$locale];
    }
}
