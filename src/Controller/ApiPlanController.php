<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Access;
use CantoTrack\Model\BoardRepository;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\ReleaseRepository;
use CantoTrack\Model\SprintRepository;
use CantoTrack\Model\WorkTypeRepository;
use CantoTrack\Service\BoardService;
use CantoTrack\Service\Presenter;

/**
 * The API's view of how the work is planned: the boards and their sprints,
 * a project's epics and releases, and the kinds of work hours are logged as.
 * Read only — planning is done on the web, where it can be seen — and the
 * tickets themselves are read through /tickets with ?sprint=, ?epic= or
 * ?release=.
 */
class ApiPlanController extends ApiEndpoint
{
    /** Every board you can see: each project's own, then the shared ones by name. */
    public function boards(): never
    {
        $this->json(['data' => array_map(fn(array $b): array => $this->boardData($b), (new BoardRepository())->visible())]);
    }

    /** One board, with its projects, its columns and its sprints — the running one first. */
    public function board(int $id): never
    {
        $boards = new BoardRepository();
        $board = $boards->find($id);

        if ($board === null) {
            $this->notFound(__('There is no such board.'));
        }

        $columns = (new BoardService())->columns($board)['columns'];

        $this->json(['data' => $this->boardData($board) + [
            'columns' => array_map(static fn(array $c): array => [
                'name' => (string) $c['name'],
                'done' => (bool) $c['done'],
                'status_ids' => array_values(array_map('intval', $c['status_ids'])),
            ], $columns),
            'sprints' => array_map(fn(array $s): array => $this->sprintData($s), (new SprintRepository())->forBoard($id)),
        ]]);
    }

    public function sprint(int $id): never
    {
        $sprints = new SprintRepository();
        $sprint = $sprints->find($id);

        if ($sprint === null) {
            $this->notFound(__('There is no such sprint.'));
        }

        // The counts of what it holds now, from the board's list.
        foreach ($sprints->forBoard((int) $sprint['board_id']) as $counted) {
            if ((int) $counted['id'] === $id) {
                $sprint = $counted + $sprint;
            }
        }

        $this->json(['data' => $this->sprintData($sprint) + [
            'board' => ['id' => (int) $sprint['board_id'], 'name' => $sprint['board_name']],
        ]]);
    }

    /** A project's epics, the open ones first. */
    public function epics(string $code): never
    {
        $project = $this->projectOr404($code);

        $this->json(['data' => array_map(static fn(array $e): array => [
            'id' => (int) $e['id'],
            'title' => $e['title'],
            'description' => $e['description'],
            'done' => (int) $e['is_done'] === 1,
            'starts_on' => $e['starts_on'] ?? null,
            'ends_on' => $e['ends_on'] ?? null,
            'tickets' => ['count' => (int) $e['ticket_count'], 'done' => (int) $e['done_count']],
            'url' => Presenter::url('/epics/' . $e['id']),
        ], (new EpicRepository())->forProject((int) $project['id']))]);
    }

    /** A project's releases: the ones still coming, soonest first, then the ones that went out. */
    public function releases(string $code): never
    {
        $project = $this->projectOr404($code);

        $this->json(['data' => array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'description' => $r['description'],
            'starts_on' => $r['starts_on'],
            'release_on' => $r['release_on'],
            'released' => $r['released_at'] !== null,
            'released_at' => $r['released_at'],
            'tickets' => ['count' => (int) $r['ticket_count'], 'done' => (int) $r['done_count']],
            'url' => Presenter::url('/releases/' . $r['id']),
        ], (new ReleaseRepository())->forProject((int) $project['id']))]);
    }

    /** The kinds of work an entry can be logged as — the ones still offered. */
    public function workTypes(): never
    {
        $this->json(['data' => array_map(static fn(array $t): array => [
            'id' => (int) $t['id'],
            'name' => $t['name'],
        ], WorkTypeRepository::active())]);
    }

    private function boardData(array $board): array
    {
        $shared = $board['project_id'] === null;
        $projects = (new BoardRepository())->projects((int) $board['id']);

        return [
            'id' => (int) $board['id'],
            'name' => $board['name'],
            'shared' => $shared,
            // Only the projects you can see: a shared board may hold one
            // that is private to others.
            'projects' => array_values(array_map(
                static fn(array $p): string => (string) $p['code'],
                array_filter($projects, static fn(array $p): bool => Access::canSeeProject((int) $p['id']))
            )),
            'query' => $board['query'] ?: null,
            'url' => Presenter::url(BoardService::url($board)),
        ];
    }

    private function sprintData(array $sprint): array
    {
        return [
            'id' => (int) $sprint['id'],
            'name' => $sprint['name'],
            'goal' => $sprint['goal'],
            'state' => $sprint['state'],
            'starts_on' => $sprint['starts_on'],
            'ends_on' => $sprint['ends_on'],
            'tickets' => isset($sprint['ticket_count']) ? ['count' => (int) $sprint['ticket_count'], 'done' => (int) $sprint['done_count']] : null,
            'points' => isset($sprint['points']) ? ['total' => (int) $sprint['points'], 'done' => (int) $sprint['done_points']] : null,
            'url' => Presenter::url('/sprints/' . $sprint['id']),
        ];
    }
}
