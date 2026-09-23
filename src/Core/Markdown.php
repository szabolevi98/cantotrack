<?php

namespace CantoTrack\Core;

use CantoTrack\Core\Markdown\MentionParser;
use CantoTrack\Core\Markdown\TicketKeyParser;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * What people type into descriptions and comments, turned into HTML.
 *
 * Markdown rather than a rich-text editor: it is readable as it was typed, it
 * survives being pasted into a commit message, and it needs no editor on the
 * page. GitHub's flavour, because that is the one people already know — the
 * tables, the ~~strikethrough~~ and the task lists.
 *
 * Two additions of this application's own: a ticket's name anywhere in the
 * text (CT-14) becomes a link to it, and @anna becomes a mention of Anna.
 *
 * Safety: HTML in the text is escaped, never passed through, and a link to a
 * `javascript:` address is dropped. Everything a user writes is shown to other
 * users, and "it is only a comment" is how a stored XSS starts.
 */
class Markdown
{
    private static ?MarkdownConverter $converter = null;

    /** @var array<string, array{id: int, name: string}>|null */
    private static ?array $handles = null;

    public static function toHtml(?string $text): string
    {
        $text = (string) $text;

        if (trim($text) === '') {
            return '';
        }

        return self::converter()->convert($text)->getContent();
    }

    /**
     * The people a text mentions, by their handle — the ones that exist.
     *
     * @return list<int> user ids
     */
    public static function mentions(?string $text): array
    {
        preg_match_all('/(?<![\w@.])@([a-z0-9]{2,40})\b/i', (string) $text, $matches);

        $ids = [];
        foreach (array_unique(array_map('strtolower', $matches[1])) as $handle) {
            if (isset(self::handles()[$handle])) {
                $ids[] = self::handles()[$handle]['id'];
            }
        }

        return $ids;
    }

    /** @return array<string, array{id: int, name: string}> */
    public static function handles(): array
    {
        if (self::$handles === null) {
            self::$handles = [];

            try {
                $statement = DatabaseConnection::get()->prepare('SELECT id, name, handle FROM users WHERE handle IS NOT NULL');
                $statement->execute();

                foreach ($statement->fetchAll() as $user) {
                    self::$handles[strtolower((string) $user['handle'])] = ['id' => (int) $user['id'], 'name' => (string) $user['name']];
                }
            } catch (\Throwable $e) {
                Logger::error('Handles could not be read for mentions: ' . $e->getMessage());
            }
        }

        return self::$handles;
    }

    /** For tests, and after a handle changes during the request. */
    public static function forget(): void
    {
        self::$handles = null;
        self::$converter = null;
    }

    /**
     * Uses these handles instead of reading them from the database — for a
     * test that has no database to read them from.
     *
     * @param array<string, array{id: int, name: string}> $handles
     */
    public static function useHandles(array $handles): void
    {
        self::$converter = null;
        self::$handles = $handles;
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter !== null) {
            return self::$converter;
        }

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'renderer' => ['soft_break' => "<br>\n"],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        $base = rtrim((string) Config::get('app.base_url', ''), '/');
        $environment->addInlineParser(new TicketKeyParser($base), 50);
        $environment->addInlineParser(new MentionParser($base, self::handles(...)), 50);

        return self::$converter = new MarkdownConverter($environment);
    }
}
