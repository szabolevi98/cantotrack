<?php

namespace CantoTrack\Service;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\CustomFieldRepository;
use CantoTrack\Model\GadgetRepository;
use CantoTrack\Model\TicketRepository;
use PDO;

/**
 * The pieces a dashboard is made of — see the 0034 and 0041 migrations.
 *
 * Some are drawn from a query the person wrote: the tickets it finds, how
 * many, or how they split by a field. The rest are the ones every
 * dashboard starts with — the numbers, one's own tickets, one's week, the
 * sprints running — which can be hidden, put back and moved like any other.
 * Each is read as the person who is looking, so it only ever counts what
 * they may see.
 */
final class Gadgets
{
    /** The pieces made of a query. */
    public const KINDS = ['list', 'count', 'breakdown'];

    /** The pieces every dashboard starts with, and the column each starts in. */
    public const BUILTIN = [
        'numbers' => 'main',
        'mine' => 'main',
        'week' => 'side',
        'budgets' => 'side',
        'sprints' => 'side',
        'releases' => 'side',
        'starred' => 'side',
        'looked_at' => 'side',
        'pages' => 'side',
        'activity' => 'side',
    ];

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

    /**
     * Ready-made pieces, one click each — the help's examples.
     *
     * @return list<array{title: string, kind: string, query: string, group_by: string}>
     */
    public static function examples(): array
    {
        return [
            ['title' => __('Mine, the most urgent first'), 'kind' => 'list', 'query' => 'assignee = me AND category != done ORDER BY priority DESC', 'group_by' => ''],
            ['title' => __('Mine, due this week'), 'kind' => 'list', 'query' => 'assignee = me AND due <= endOfWeek() AND category != done ORDER BY due ASC', 'group_by' => ''],
            ['title' => __('Stuck for three days'), 'kind' => 'list', 'query' => 'category = "in progress" AND updated <= -3d ORDER BY updated ASC', 'group_by' => ''],
            ['title' => __('Open work by person'), 'kind' => 'breakdown', 'query' => 'category != done', 'group_by' => 'assignee'],
            ['title' => __('Where the sprints are'), 'kind' => 'breakdown', 'query' => 'sprint IN openSprints()', 'group_by' => 'status'],
            ['title' => __('Bugs opened this week'), 'kind' => 'count', 'query' => 'type = bug AND created >= startOfWeek()', 'group_by' => ''],
        ];
    }

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
     * A dashboard laid out for the first time: the pieces every dashboard
     * starts with, with whatever the person had made kept in the main
     * column, after the numbers.
     */
    public function layOut(int $userId): void
    {
        if ($this->gadgets->isLaidOut($userId)) {
            return;
        }

        $this->reset($userId);
    }

    /**
     * Back to how a dashboard starts: every built-in piece where it starts,
     * in its order, and the person's own pieces kept in their columns.
     */
    public function reset(int $userId): void
    {
        // The person's own pieces stay in the column they were in: in the
        // main one after the numbers, in the side one after the usual pieces.
        $own = ['main' => [], 'side' => []];
        foreach ($this->gadgets->forUser($userId) as $gadget) {
            if (isset(self::BUILTIN[$gadget['kind']])) {
                $this->gadgets->delete((int) $gadget['id'], $userId);
            } else {
                $own[$gadget['area'] === 'side' ? 'side' : 'main'][] = (int) $gadget['id'];
            }
        }

        $main = [];
        $side = [];
        foreach (self::BUILTIN as $kind => $area) {
            $id = $this->gadgets->create($userId, $kind, '', '', null, $area);
            if ($area === 'side') {
                $side[] = $id;
            } elseif ($kind === 'numbers') {
                $main = array_merge([$id], $own['main']);
            } else {
                $main[] = $id;
            }
        }

        $this->gadgets->arrange($userId, $main, array_merge($side, $own['side']));
        $this->gadgets->markLaidOut($userId);
    }

    /**
     * Puts a built-in piece back, where it starts.
     *
     * @throws ValidationError
     */
    public function addBuiltin(int $userId, string $kind): int
    {
        if (!isset(self::BUILTIN[$kind])) {
            throw new ValidationError(__('There is no such piece.'));
        }

        foreach ($this->gadgets->forUser($userId) as $gadget) {
            if ($gadget['kind'] === $kind) {
                return (int) $gadget['id'];
            }
        }

        return $this->gadgets->create($userId, $kind, '', '', null, self::BUILTIN[$kind]);
    }

    /**
     * Adds a piece of the person's own, once its query reads.
     *
     * @throws ValidationError
     */
    public function add(int $userId, string $kind, string $title, string $query, string $groupBy, string $area = 'main'): int
    {
        [$title, $query, $groupBy] = $this->checked($userId, $kind, $title, $query, $groupBy);

        return $this->gadgets->create($userId, $kind, $title, $query, $groupBy, $area);
    }

    /**
     * Changes a piece of the person's own in place.
     *
     * @throws ValidationError
     */
    public function update(int $userId, int $id, string $kind, string $title, string $query, string $groupBy): void
    {
        $gadget = $this->gadgets->find($id, $userId);

        if ($gadget === null || isset(self::BUILTIN[$gadget['kind']])) {
            throw new ValidationError(__('There is no such piece.'));
        }

        [$title, $query, $groupBy] = $this->checked($userId, $kind, $title, $query, $groupBy);
        $this->gadgets->update($id, $userId, $kind, $title, $query, $groupBy);
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     * @throws ValidationError
     */
    private function checked(int $userId, string $kind, string $title, string $query, string $groupBy): array
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

        return [mb_substr($title, 0, 120), mb_substr(trim($query), 0, 1000), $kind === 'breakdown' ? $groupBy : null];
    }

    /**
     * Each of a person's pieces, read now: what a query piece shows, or why
     * it cannot. The built-in ones are passed through for the dashboard to
     * fill in.
     *
     * @return list<array<string, mixed>>
     */
    public function drawn(int $userId): array
    {
        $drawn = [];

        foreach ($this->gadgets->forUser($userId) as $gadget) {
            if (isset(self::BUILTIN[$gadget['kind']])) {
                $drawn[] = $gadget + ['builtin' => true];
                continue;
            }

            $query = (string) $gadget['query'];
            $piece = $gadget + ['builtin' => false, 'error' => null, 'tickets' => [], 'count' => 0, 'groups' => [], 'link' => '/tickets?' . http_build_query(['query' => $query])];

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
