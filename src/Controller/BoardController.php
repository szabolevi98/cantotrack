<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\BoardRepository;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\LabelRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\SprintRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\BoardLanes;
use CantoTrack\Service\BoardService;

/**
 * Shared boards: the list of them, one board with its running sprint, and
 * the settings that say which projects it holds and how their columns line
 * up — see the 0047 migration.
 *
 * A project's own board is the project's Board tab (ProjectController); its
 * address here goes there.
 */
class BoardController extends Controller
{
    public function index(): void
    {
        Auth::require();

        $this->render('boards/index.twig', [
            'boards' => (new BoardRepository())->shared(),
            'projects' => (new ProjectRepository())->allWithCounts(),
        ]);
    }

    public function create(): void
    {
        Auth::requireMember();

        try {
            $id = (new BoardService())->create($this->input('name'), $this->idsInput('projects'), $this->input('query'));
            $this->flash(__('Board made.'));
            $this->redirect('/boards/' . $id);
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->redirect('/boards');
        }
    }

    /** The board: its projects' tickets in its columns — the running sprint's, while one runs. */
    public function show(int $id): void
    {
        Auth::require();

        $board = $this->sharedOr404($id);
        $service = new BoardService();
        $worked = $service->columns($board);
        $projects = (new BoardRepository())->projects($id);

        $who = (string) ($_GET['who'] ?? '');
        $epic = (string) ($_GET['epic'] ?? '');
        $filters = [
            'assignee_id' => $who === 'me' ? Auth::id() : (ctype_digit($who) ? (int) $who : null),
            'unassigned' => $who === 'none',
            'epic_id' => ctype_digit($epic) ? (int) $epic : null,
            'no_epic' => $epic === 'none',
            'type' => $_GET['type'] ?? null,
            'label' => trim((string) ($_GET['label'] ?? '')) ?: null,
            'q' => trim((string) ($_GET['q'] ?? '')) ?: null,
        ];
        $lanesBy = in_array($_GET['lanes'] ?? '', ['epic', 'assignee'], true) ? $_GET['lanes'] : 'none';
        $narrowed = array_filter($filters) !== [];

        // While a sprint runs the board is that sprint's, whatever the query
        // — what the team said it would do — unless somebody asks for all
        // of what the board holds.
        $active = (new SprintRepository())->active($id);
        $scope = $active !== null && ($_GET['scope'] ?? '') !== 'all' ? 'sprint' : 'all';
        $holds = $scope === 'sprint' ? ['sprint_id' => (int) $active['id']] : $service->scope($board);

        $drawn = (new TicketRepository())->boardColumns($worked['columns'], $filters + $holds, $scope === 'sprint');

        $epics = [];
        foreach ($projects as $project) {
            foreach ((new EpicRepository())->forProject((int) $project['id']) as $one) {
                $one['project_code'] = $project['code'];
                $epics[] = $one;
            }
        }

        $this->render('boards/show.twig', [
            'board' => $board,
            'board_projects' => $projects,
            'columns' => $worked['columns'],
            'more' => $drawn['more'],
            'more_urls' => $this->moreUrls($worked['columns'], $projects, $service->statusesOf($board)),
            'counts' => $drawn['counts'],
            'statuses_of' => $service->statusesOf($board),
            'epics' => $epics,
            'lanes' => BoardLanes::split($drawn['columns'], $lanesBy, $epics),
            'lanes_by' => $lanesBy,
            'who' => $who,
            'epic' => $epic,
            'filters' => $filters,
            'narrowed' => $narrowed,
            'active_sprint' => $active,
            'sprint_progress' => $active === null ? null : $this->progress((int) $active['id']),
            'scope' => $scope,
            'people' => (new UserRepository())->active(),
            'labels' => (new LabelRepository())->all(),
            'types' => TicketRepository::TYPES,
        ]);
    }

    public function settings(int $id): void
    {
        Auth::requireMember();

        $board = $this->sharedOr404($id);
        $service = new BoardService();
        $boards = new BoardRepository();

        // What each status was put in by hand, by the column's name.
        $byHand = [];
        foreach ($boards->columns($id) as $column) {
            foreach ($column['status_ids'] as $statusId) {
                $byHand[$statusId] = $column['name'];
            }
        }

        $worked = $service->columns($board);
        $names = [];
        foreach ($worked['columns'] as $column) {
            $names[$column['id']] = $column['name'];
        }

        $this->render('boards/settings.twig', [
            'board' => $board,
            'projects' => (new ProjectRepository())->allWithCounts(),
            'on_board' => $boards->projectIds($id),
            'board_projects' => $boards->projects($id),
            'own_columns' => $boards->columns($id),
            'columns' => $worked['columns'],
            'column_names' => implode("\n", array_values($names)),
            'lands_in' => array_map(static fn(string $key): string => $names[$key] ?? '', $worked['of']),
            'by_hand' => $byHand,
            'statuses_of' => $service->statusesOf($board),
        ]);
    }

    public function update(int $id): void
    {
        Auth::requireMember();

        $board = $this->sharedOr404($id);

        try {
            (new BoardService())->update($board, $this->input('name'), $this->idsInput('projects'), $this->input('query'));
            $this->flash(__('Board saved.'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/boards/' . $id . '/settings');
    }

    public function columns(int $id): void
    {
        Auth::requireMember();

        $board = $this->sharedOr404($id);
        $given = $_POST['status_column'] ?? [];

        try {
            $service = new BoardService();

            if (($_POST['automatic'] ?? '') === '1') {
                $service->saveColumns($board, '', []);
                $this->flash(__('The board takes its projects’ columns again.'));
            } else {
                $service->saveColumns($board, $this->input('names'), is_array($given) ? array_map('strval', $given) : []);
                $this->flash(__('Columns saved.'));
            }
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/boards/' . $id . '/settings');
    }

    /** A shared board goes with its sprints; its tickets stay, back in their backlogs. */
    public function delete(int $id): void
    {
        Auth::requireAdmin();

        $board = $this->sharedOr404($id);

        (new BoardRepository())->delete((int) $board['id']);
        $this->flash(__('{name} is deleted.', ['name' => $board['name']]), 'warning');
        $this->redirect('/boards');
    }

    /**
     * Where "and 12 more" under a finished column leads: the ticket list,
     * asked for the board's projects in that column's statuses.
     *
     * @param array<int, list<array>> $statusesOf
     * @return array<string, string>
     */
    private function moreUrls(array $columns, array $projects, array $statusesOf): array
    {
        $names = [];
        foreach ($statusesOf as $statuses) {
            foreach ($statuses as $status) {
                $names[(int) $status['id']] = (string) $status['name'];
            }
        }

        $codes = implode(', ', array_map(static fn(array $p): string => (string) $p['code'], $projects));
        $urls = [];

        foreach ($columns as $column) {
            $in = array_values(array_unique(array_map(
                static fn(int $id): string => '"' . str_replace('"', '', $names[$id] ?? '') . '"',
                $column['status_ids']
            )));
            $urls[$column['id']] = '/tickets?' . http_build_query(['query' => 'project IN (' . $codes . ') AND status IN (' . implode(', ', $in) . ')']);
        }

        return $urls;
    }

    /** How far the running sprint has got: tickets and points, done and all. */
    private function progress(int $sprintId): array
    {
        $tickets = (new SprintRepository())->tickets($sprintId);
        $done = array_filter($tickets, static fn(array $t): bool => $t['category'] === 'done');

        return [
            'total' => count($tickets),
            'done' => count($done),
            'points' => array_sum(array_map(static fn(array $t): int => (int) $t['story_points'], $tickets)),
            'done_points' => array_sum(array_map(static fn(array $t): int => (int) $t['story_points'], $done)),
        ];
    }

    /** @return list<int> */
    private function idsInput(string $key): array
    {
        $given = $_POST[$key] ?? [];

        return is_array($given) ? array_values(array_filter(array_map('intval', $given), static fn(int $id): bool => $id > 0)) : [];
    }

    /** A shared board; a project's own one is the project's page. */
    private function sharedOr404(int $id): array
    {
        $board = (new BoardRepository())->find($id);

        if ($board === null) {
            $this->notFound(__('There is no such board.'));
        }

        if ($board['project_id'] !== null) {
            $this->redirect('/projects/' . $board['project_id']);
        }

        return $board;
    }
}
