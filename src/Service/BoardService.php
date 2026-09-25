<?php

namespace CantoTrack\Service;

use CantoTrack\Core\Auth;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\BoardRepository;
use CantoTrack\Model\CustomFieldRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\StatusRepository;
use PDO;

/**
 * Shared boards: making one, choosing its projects and its columns, and
 * working out what it holds — see the 0047 migration.
 *
 * A project's own board is made with the project and has nothing to set:
 * its projects are the project, its columns the project's.
 */
final class BoardService
{
    private BoardRepository $boards;
    private ProjectRepository $projects;
    private StatusRepository $statuses;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
        $this->boards = new BoardRepository($this->db);
        $this->projects = new ProjectRepository($this->db);
        $this->statuses = new StatusRepository($this->db);
    }

    /**
     * Where a board's pages are: a project's own board is the project's
     * Board tab (and '/backlog' its Backlog tab), a shared one under /boards.
     */
    public static function url(array $board, string $page = ''): string
    {
        return ($board['project_id'] !== null ? '/projects/' . $board['project_id'] : '/boards/' . $board['id']) . $page;
    }

    /**
     * @param list<int> $projectIds
     * @throws ValidationError
     */
    public function create(string $name, array $projectIds, string $query): int
    {
        [$name, $query] = $this->checked($name, $query);

        $id = $this->boards->create($name, $query);
        $this->boards->setProjects($id, $this->visibleProjects($projectIds));

        return $id;
    }

    /**
     * Renames a shared board and gives it its projects and query. A project
     * taken off it takes its tickets out of the board's open sprints: they
     * go back to its backlog, not into a sprint nobody can see them in.
     *
     * @param list<int> $projectIds
     * @throws ValidationError
     */
    public function update(array $board, string $name, array $projectIds, string $query): void
    {
        if ($board['project_id'] !== null) {
            throw new ValidationError(__('A project’s own board goes with the project and cannot be changed.'));
        }

        [$name, $query] = $this->checked($name, $query);
        $id = (int) $board['id'];

        // What somebody cannot see stays on the board as it was: they could
        // not have chosen to take it off.
        $hidden = array_values(array_filter(
            $this->boards->projectIds($id),
            static fn(int $projectId): bool => !\CantoTrack\Core\Access::canSeeProject($projectId)
        ));
        $kept = array_values(array_unique(array_merge($this->visibleProjects($projectIds), $hidden)));
        $removed = array_diff($this->boards->projectIds($id), $kept);

        $this->boards->update($id, $name, $query);
        $this->boards->setProjects($id, $kept);

        if ($removed !== []) {
            $this->db->prepare(
                'UPDATE tickets t JOIN sprints sp ON sp.id = t.sprint_id
                 SET t.sprint_id = NULL
                 WHERE sp.board_id = :board AND sp.state <> \'closed\'
                   AND t.project_id IN (' . implode(',', array_map('intval', $removed)) . ')'
            )->execute(['board' => $id]);
        }
    }

    /**
     * The columns of a shared board as given on its settings page: a name per
     * line, and the column each status is put in by hand (its name, or
     * nothing for "wherever its name or its kind puts it").
     *
     * @param array<int|string, string> $statusColumns status id => column name
     * @throws ValidationError
     */
    public function saveColumns(array $board, string $names, array $statusColumns): void
    {
        if ($board['project_id'] !== null) {
            throw new ValidationError(__('A project’s own board has the project’s columns.'));
        }

        $lines = [];
        foreach (preg_split('/\R/', $names) ?: [] as $line) {
            $line = mb_substr(trim($line), 0, 60);

            if ($line !== '' && !in_array(BoardColumns::plain($line), array_map([BoardColumns::class, 'plain'], $lines), true)) {
                $lines[] = $line;
            }
        }

        $onBoard = [];
        foreach ($this->statusesOf($board) as $statuses) {
            foreach ($statuses as $status) {
                $onBoard[(int) $status['id']] = true;
            }
        }

        $byHand = [];
        $plainLines = array_map([BoardColumns::class, 'plain'], $lines);
        foreach ($statusColumns as $statusId => $column) {
            $index = array_search(BoardColumns::plain((string) $column), $plainLines, true);

            if ($index !== false && isset($onBoard[(int) $statusId]) && trim((string) $column) !== '') {
                $byHand[(int) $statusId] = (int) $index;
            }
        }

        $this->boards->saveColumns((int) $board['id'], $lines, $byHand);
    }

    /**
     * What a board holds, as filters for the ticket list: its projects, and
     * its query when it has one. A query that no longer reads (a field
     * renamed since) narrows to nothing rather than widening to everything.
     */
    public function scope(array $board): array
    {
        $scope = ['project_ids' => $this->boards->projectIds((int) $board['id'])];
        $query = trim((string) ($board['query'] ?? ''));

        if ($query !== '') {
            try {
                $compiled = TicketQuery::compile($query, Auth::id(), null, (new CustomFieldRepository($this->db))->kindsByName());
                $scope['query_where'] = $compiled['where'] !== '' ? $compiled['where'] : 'TRUE';
                $scope['query_params'] = $compiled['params'];
            } catch (ValidationError) {
                $scope['query_where'] = 'FALSE';
            }
        }

        return $scope;
    }

    /**
     * The board's columns, worked out from its projects' — see BoardColumns.
     *
     * @return array{columns: list<array>, of: array<int, string>}
     */
    public function columns(array $board): array
    {
        return BoardColumns::work($this->statusesOf($board), $this->boards->columns((int) $board['id']));
    }

    /**
     * Each project's columns on the board, keyed by project, in the order of
     * the projects' codes.
     *
     * @return array<int, list<array>>
     */
    public function statusesOf(array $board): array
    {
        $all = $this->statuses->byProject();
        $out = [];

        foreach ($this->boards->projectIds((int) $board['id']) as $projectId) {
            $out[$projectId] = $all[$projectId] ?? [];
        }

        return $out;
    }

    /**
     * Where a card dropped into a board's column goes: the status of its own
     * project in that column, and the statuses the column holds, which its
     * place among the cards is counted in.
     *
     * @return array{status: array, column: list<int>}
     * @throws ValidationError
     */
    public function dropInto(array $board, string $columnId, array $ticket): array
    {
        $worked = $this->columns($board);

        foreach ($worked['columns'] as $column) {
            if ($column['id'] !== $columnId) {
                continue;
            }

            $status = BoardColumns::statusFor($column, $this->statuses->forProject((int) $ticket['project_id']), (int) $ticket['status_id']);

            if ($status === null) {
                throw new ValidationError(__('{code} has no column that goes in {column}.', ['code' => $ticket['project_code'], 'column' => $column['name']]));
            }

            return ['status' => $status, 'column' => $column['status_ids']];
        }

        throw new ValidationError(__('That is not one of the board’s columns.'));
    }

    /** @return array{0: string, 1: ?string} */
    private function checked(string $name, string $query): array
    {
        $name = mb_substr(trim($name), 0, 80);

        if ($name === '') {
            throw new ValidationError(__('A board needs a name.'));
        }

        $query = mb_substr(trim($query), 0, 1000);

        if ($query !== '') {
            // Read now, so a query that does not read is said at once, not
            // found out as an empty board.
            TicketQuery::compile($query, Auth::id(), null, (new CustomFieldRepository($this->db))->kindsByName());
        }

        return [$name, $query === '' ? null : $query];
    }

    /**
     * @param list<int> $projectIds
     * @return list<int>
     */
    private function visibleProjects(array $projectIds): array
    {
        return array_values(array_filter(
            array_unique(array_map('intval', $projectIds)),
            fn(int $id): bool => $id > 0 && $this->projects->find($id) !== null && \CantoTrack\Core\Access::canSeeProject($id)
        ));
    }
}
