<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Config;
use CantoTrack\Core\Controller;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * The API's documentation, inside the application: docs/API.md, drawn.
 *
 * One file, read by two audiences — on GitHub beside the code, and here by
 * whoever just made a token — so there is one text to keep true rather than
 * a page and a file that drift apart. The examples there are written against
 * https://tracker.example; here they carry this installation's own address,
 * so a curl line can be copied as it is.
 *
 * It is our own file, not something a user typed, but it goes through the
 * same escaping all the same: nothing in it needs raw HTML.
 */
class ApiDocsController extends Controller
{
    private const EXAMPLE_BASE = 'https://tracker.example';

    public function show(): void
    {
        Auth::require();

        $file = dirname(__DIR__, 2) . '/docs/API.md';
        $markdown = str_replace(self::EXAMPLE_BASE, rtrim((string) Config::get('app.base_url'), '/'), (string) file_get_contents($file));
        $html = self::converter()->convert($markdown)->getContent();

        $this->render('help/api.twig', [
            'html' => $html,
            'contents' => self::contents($html),
        ]);
    }

    /**
     * The second-level headings, for the list beside the text.
     *
     * @return list<array{id: string, title: string}>
     */
    public static function contents(string $html): array
    {
        preg_match_all('~<h2 id="([^"]+)">(.*?)</h2>~s', $html, $matches, PREG_SET_ORDER);

        return array_map(static fn(array $m): array => [
            'id' => $m[1],
            'title' => html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5),
        ], $matches);
    }

    private static function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            // An id on each heading, and no pilcrow beside it: the list of
            // contents is what links to them.
            'heading_permalink' => [
                'insert' => 'none',
                'apply_id_to_heading' => true,
                'id_prefix' => '',
                'fragment_prefix' => '',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new HeadingPermalinkExtension());

        return new MarkdownConverter($environment);
    }
}
