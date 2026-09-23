<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Service\Roadmap;
use DateTimeImmutable;

/**
 * The roadmap: one project's, from its tabs, and every project's at once,
 * for whoever has to say what happens in which month across all of them.
 */
class RoadmapController extends Controller
{
    public function all(): void
    {
        Auth::require();

        $projects = array_values(array_filter(
            (new ProjectRepository())->allWithCounts(),
            static fn(array $p): bool => (int) $p['is_archived'] === 0
        ));

        $this->show($projects, null);
    }

    public function project(int $id): void
    {
        Auth::require();

        $project = (new ProjectRepository())->find($id);

        if ($project === null) {
            $this->notFound(__('There is no such project.'));
        }

        $this->show([$project], $project);
    }

    /** @param list<array<string, mixed>> $projects */
    private function show(array $projects, ?array $project): void
    {
        $today = new DateTimeImmutable('today');
        $window = Roadmap::window((string) ($_GET['from'] ?? ''), $today);

        $this->render('roadmap/index.twig', [
            'project' => $project,
            'lanes' => (new Roadmap())->lanes($projects, $window['from'], $window['to']),
            'months' => Roadmap::months($window['from'], $window['to']),
            'today_at' => Roadmap::at($today->format('Y-m-d'), $window['from'], $window['to']),
            'from' => $window['from']->format('Y-m-d'),
            'days' => (int) $window['from']->diff($window['to'])->days + 1,
            'to' => $window['to']->format('Y-m-d'),
            'earlier' => $window['from']->modify('-3 months')->format('Y-m'),
            'later' => $window['from']->modify('+3 months')->format('Y-m'),
        ]);
    }
}
