<?php

namespace CantoTrack\Service;

use CantoTrack\Core\ConflictError;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Markdown;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\CustomFieldRepository;
use CantoTrack\Model\PageRepository;
use CantoTrack\Model\TicketRepository;
use PDO;

/**
 * The rules about a project's pages — a title, a place in the tree that is
 * not under itself, a version kept of every save — and how a page's text is
 * drawn: Markdown, with [[links to other pages]] and lists of tickets from
 * the query language, drawn fresh every time the page is read.
 */
class Pages
{
    /** A line that is a list of tickets: {{tickets project = CT AND category != done}} */
    private const TICKETS = '/^\{\{\s*tickets\s+(.+?)\s*\}\}\s*$/mi';

    private PageRepository $pages;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
        $this->pages = new PageRepository($this->db);
    }

    /** @throws ValidationError */
    public function create(int $projectId, ?int $parentId, string $title, string $body, int $userId): int
    {
        $title = $this->title($title);
        $this->checkParent($projectId, $parentId, null);

        $id = $this->pages->create($projectId, $parentId, $title, $body, $userId);
        $this->pages->setTickets($id, $this->mentionedTickets($body));

        return $id;
    }

    /**
     * @throws ValidationError
     * @throws ConflictError when somebody saved it after it was opened
     */
    public function update(array $page, ?int $parentId, string $title, string $body, int $userId, int $version): void
    {
        $title = $this->title($title);
        $this->checkParent((int) $page['project_id'], $parentId, (int) $page['id']);

        // The version before this one is kept first, so an edit never loses
        // what it replaced.
        $this->pages->keepVersion($page);

        if (!$this->pages->update((int) $page['id'], $parentId, $title, $body, $userId, $version)) {
            throw new ConflictError(
                __('Somebody else saved this page while you were editing it. What you wrote is still in the form; their version is in the history.'),
                (array) $this->pages->find((int) $page['id'])
            );
        }

        $this->pages->setTickets((int) $page['id'], $this->mentionedTickets($body));
    }

    /** An earlier version back as the page's text, itself a new version. */
    public function restore(array $page, int $version, int $userId): void
    {
        $old = $this->pages->version((int) $page['id'], $version);

        if ($old === null) {
            throw new ValidationError(__('There is no such version.'));
        }

        $this->update($page, $page['parent_id'] === null ? null : (int) $page['parent_id'], (string) $old['title'], (string) $old['body'], $userId, (int) $page['version']);
    }

    /**
     * A page goes; its children move up to where it was, so nothing under it
     * is lost with it.
     */
    public function delete(array $page): void
    {
        $this->db->prepare('UPDATE pages SET parent_id = :parent WHERE parent_id = :id')
            ->execute(['parent' => $page['parent_id'], 'id' => $page['id']]);

        // Its pictures: the rows go with the page, the files would stay.
        $files = (new \CantoTrack\Model\AttachmentRepository($this->db))->pathsForPages('pg.id = :id', ['id' => $page['id']]);
        $this->pages->delete((int) $page['id']);
        \CantoTrack\Service\AttachmentService::unlinkAll($files);
    }

    /**
     * A page's text as the parts it is drawn in: Markdown, and the lists of
     * tickets between them — each list read now, as whoever is reading.
     *
     * @return list<array{kind: string, html?: string, query?: string, tickets?: list<array>, error?: ?string}>
     */
    public function render(array $page, ?int $readerId): array
    {
        $body = $this->pageLinks((int) $page['project_id'], (string) ($page['body'] ?? ''));
        $parts = [];
        $offset = 0;

        preg_match_all(self::TICKETS, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $text = substr($body, $offset, $match[0][1] - $offset);
            if (trim($text) !== '') {
                $parts[] = ['kind' => 'markdown', 'html' => self::headingIds(Markdown::toHtml($text))];
            }

            $query = $match[1][0];
            try {
                $compiled = TicketQuery::compile($query, $readerId, null, (new CustomFieldRepository($this->db))->kindsByName());
                $tickets = (new TicketRepository($this->db))->search([
                    'query_where' => $compiled['where'], 'query_params' => $compiled['params'], 'query_order' => $compiled['order'],
                ], 25);
                $parts[] = ['kind' => 'tickets', 'query' => $query, 'tickets' => array_values($tickets), 'error' => null];
            } catch (ValidationError $e) {
                $parts[] = ['kind' => 'tickets', 'query' => $query, 'tickets' => [], 'error' => $e->getMessage()];
            }

            $offset = $match[0][1] + strlen($match[0][0]);
        }

        $rest = substr($body, $offset);
        if (trim($rest) !== '') {
            $parts[] = ['kind' => 'markdown', 'html' => self::headingIds(Markdown::toHtml($rest))];
        }

        return $parts;
    }

    /**
     * The headings of a page, for the list of contents beside it.
     *
     * @return list<array{level: int, text: string, id: string}>
     */
    public static function contents(string $body): array
    {
        $out = [];
        $inCode = false;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $inCode = !$inCode;
            }
            if (!$inCode && preg_match('/^(#{1,3})\s+(.+?)\s*#*\s*$/', $line, $m) === 1) {
                $text = trim(html_entity_decode(strip_tags(Markdown::toHtml($m[2])), ENT_QUOTES));
                $out[] = ['level' => strlen($m[1]), 'text' => $text, 'id' => self::slug($text)];
            }
        }

        return $out;
    }

    /** The ids the headings get, so the contents can link to them. */
    public static function headingIds(string $html): string
    {
        return (string) preg_replace_callback('/<h([1-3])>(.*?)<\/h\1>/s', static function (array $m): string {
            return '<h' . $m[1] . ' id="' . self::slug(html_entity_decode(strip_tags($m[2]), ENT_QUOTES)) . '">' . $m[2] . '</h' . $m[1] . '>';
        }, $html);
    }

    public static function slug(string $text): string
    {
        $slug = mb_strtolower(trim((string) preg_replace('/[^\pL\pN]+/u', '-', $text), '-'));

        return 'h-' . ($slug === '' ? 'section' : mb_substr($slug, 0, 60));
    }

    /**
     * [[Another page]] and [[Another page|as this]] as links: to the page if
     * the project has one by that title, and to making it if not.
     */
    private function pageLinks(int $projectId, string $body): string
    {
        return (string) preg_replace_callback('/\[\[([^\]|]{1,200})(?:\|([^\]]{1,200}))?\]\]/u', function (array $m) use ($projectId): string {
            $title = trim($m[1]);
            $shown = trim($m[2] ?? '') ?: $title;
            $page = $this->pages->byTitle($projectId, $title);
            $base = rtrim((string) \CantoTrack\Core\Config::get('app.base_url', ''), '/');
            $url = $page !== null
                ? $base . '/pages/' . $page['id']
                : $base . '/projects/' . $projectId . '/pages/create?title=' . rawurlencode($title);

            return '[' . str_replace(['[', ']'], '', $shown) . ($page === null ? ' (+)' : '') . '](' . $url . ')';
        }, $body);
    }

    /** @return list<int> the tickets a text names by their keys */
    private function mentionedTickets(string $body): array
    {
        preg_match_all('/\b([A-Z][A-Z0-9]{1,9})-(\d+)\b/', $body, $matches, PREG_SET_ORDER);
        $ids = [];
        $statement = $this->db->prepare('SELECT t.id FROM tickets t JOIN projects p ON p.id = t.project_id WHERE p.code = :code AND t.number = :number');

        foreach ($matches as $m) {
            $statement->execute(['code' => $m[1], 'number' => (int) $m[2]]);
            $id = $statement->fetchColumn();
            if ($id !== false) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @throws ValidationError */
    private function title(string $given): string
    {
        $title = trim($given);

        if ($title === '' || mb_strlen($title) > 200) {
            throw new ValidationError(__('A page needs a title of at most {count} characters.', ['count' => 200]));
        }

        return $title;
    }

    /** @throws ValidationError */
    private function checkParent(int $projectId, ?int $parentId, ?int $pageId): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = $this->pages->find($parentId);

        if ($parent === null || (int) $parent['project_id'] !== $projectId) {
            throw new ValidationError(__('That page is not in this project.'));
        }

        if ($pageId !== null && ($parentId === $pageId || in_array($parentId, $this->pages->descendantIds($pageId), true))) {
            throw new ValidationError(__('A page cannot go under itself, or under a page of its own.'));
        }
    }
}
