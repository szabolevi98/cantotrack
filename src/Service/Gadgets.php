<?php

namespace CantoTrack\Service;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\CustomFieldRepository;
use CantoTrack\Model\GadgetRepository;
use CantoTrack\Model\TicketRepository;
use PDO;

/**
 * The pieces of a person's dashboard, each drawn from a query: the tickets
 * it finds, how many, or how they split by a field — see the 0034
 * migration. Each is read as the person who is looking, so it only ever
 * counts what they may see.
 */
final class Gadgets
{
    public const KINDS = ['list', 'count', 'breakdown'];

    /** What a breakdown can split by: the field's name in the query language, and its column. */
    public const GROUPS = [
        'status' => 's.name',
        'assignee' => "COALESCE(a.name, '')",
        'priority' => 't.priority',
        'type' => 't.type',
        'epic' => "COALESCE(e.title, '')",
        'sprint' => "COALESCE(sp.name, '')",
        'release' => "COALESCE(rl.name, '')",
        'project' => 'p.code',
    ];

    private GadgetRepository $gadgets;
    private TicketRepository $tickets;
    private ?PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
        $this->gadgets = new GadgetRepository($db);
        $this->tickets = new TicketRepository($db);
    }

    /**
     * Adds a piece to someone's dashboard, once its query reads.
     *
     * @throws ValidationError
     */
    public function add(int $userId, string $kind, string $title, string $query, string $groupBy): int
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new ValidationError(__('Pick what it should show.'));
        }

        $title = trim($title);
        if ($title === '') {
            throw new ValidationError(__('Give it a title.'));
        }

        if ($kind === 'breakdown' && !isset(self::GROUPS[$groupBy])) {
            throw new ValidationError(__('Pick what to split it by.'));
        }

        // Checked now, so a typo is said at once rather than on every visit.
        $this->compile(trim($query), $userId);

        return $this->gadgets->create($userId, $kind, mb_substr($title, 0, 120), mb_substr(trim($query), 0, 1000), $kind === 'breakdown' ? $groupBy : null);
    }

    /**
     * Each of a person's pieces, read now: what it shows, or why it cannot.
     *
     * @return list<array<string, mixed>>
     */
    public function drawn(int $userId): array
    {
        $drawn = [];

        foreach ($this->gadgets->forUser($userId) as $gadget) {
            $query = (string) $gadget['query'];
            $piece = $gadget + ['error' => null, 'tickets' => [], 'count' => 0, 'groups' => [], 'link' => '/tickets?' . http_build_query(['query' => $query])];

            try {
                $filters = $this->compile($query, $userId);
                $piece['count'] = $this->tickets->count($filters);

                if ($gadget['kind'] === 'list') {
                    $piece['tickets'] = $this->tickets->search($filters, 8);
                } elseif ($gadget['kind'] === 'breakdown') {
                    $field = (string) $gadget['group_by'];
                    foreach ($this->tickets->countBy($filters, self::GROUPS[$field] ?? 's.name') as $group) {
                        $piece['groups'][] = $group + ['link' => '/tickets?' . http_build_query(['query' => self::narrowed($query, $field, $group['label'])])];
                    }
                }
            } catch (ValidationError $e) {
                $piece['error'] = $e->getMessage();
            }

            $drawn[] = $piece;
        }

        return $drawn;
    }

    /**
     * A query with one more condition: the same tickets, those of one value
     * of the field — for the link behind a bar of a breakdown.
     */
    public static function narrowed(string $query, string $field, string $value): string
    {
        $order = '';
        if (preg_match('/^(.*?)(\s+ORDER\s+BY\s+.*)$/is', $query, $m) === 1) {
            [$query, $order] = [$m[1], $m[2]];
        }

        $condition = $value === '' ? $field . ' IS EMPTY' : $field . ' = "' . addcslashes($value, '"\\') . '"';
        $query = trim($query);

        return ($query === '' ? $condition : '(' . $query . ') AND ' . $condition) . $order;
    }

    /**
     * @return array<string, mixed> the ticket list's filters for a query
     * @throws ValidationError
     */
    private function compile(string $query, int $userId): array
    {
        if ($query === '') {
            return [];
        }

        $compiled = TicketQuery::compile($query, $userId, null, (new CustomFieldRepository($this->db))->kindsByName());

        return ['query_where' => $compiled['where'], 'query_params' => $compiled['params'], 'query_order' => $compiled['order']];
    }
}
