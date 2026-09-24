<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Chart;
use CantoTrack\Core\Controller;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\ReportRepository;
use CantoTrack\Service\Flow;
use DateTimeImmutable;

/**
 * How work moves through a project, over the last weeks: the cumulative
 * flow, how long tickets took, and how many came in and went out — see
 * Flow for how each is worked out.
 */
class FlowController extends Controller
{
    /** The spans on offer, in weeks. */
    public const SPANS = [4, 8, 12, 26];

    public function show(int $projectId): void
    {
        Auth::require();

        $project = (new ProjectRepository())->find($projectId);

        if ($project === null) {
            $this->notFound(__('There is no such project.'));
        }

        $weeks = in_array((int) ($_GET['weeks'] ?? 0), self::SPANS, true) ? (int) $_GET['weeks'] : 8;
        $to = new DateTimeImmutable('today');
        $from = $to->modify('-' . ($weeks * 7 - 1) . ' days');

        $tickets = (new ReportRepository())->flowTickets($projectId);
        $days = Flow::cumulative($tickets, $from, $to);
        $times = Flow::times($tickets, $from, $to);
        $cycle = Flow::summary(array_column($times, 'cycle'));
        $lead = Flow::summary(array_column($times, 'lead'));
        $weekly = Flow::weekly($tickets, $from, $to);
        $today = end($days) ?: ['todo' => 0, 'in_progress' => 0, 'done' => 0];

        $this->render('reports/flow.twig', [
            'project' => $project,
            'weeks' => $weeks,
            'spans' => self::SPANS,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'flow_chart' => Chart::flow($days),
            'cycle_chart' => $times === [] ? null : Chart::cycleTimes($times, $from->format('Y-m-d'), $to->format('Y-m-d'), $cycle['p85']),
            'weekly_chart' => Chart::createdResolved($weekly),
            'cycle' => $cycle,
            'lead' => $lead,
            'weekly' => $weekly,
            'now' => $today,
            'created' => array_sum(array_column($weekly, 'created')),
            'resolved' => array_sum(array_column($weekly, 'resolved')),
            'slowest' => array_slice(array_reverse(self::byCycle($times)), 0, 5),
        ]);
    }

    /**
     * @param list<array{key: string, title: string, closed: string, cycle: float, lead: float}> $times
     * @return list<array{key: string, title: string, closed: string, cycle: float, lead: float}>
     */
    private static function byCycle(array $times): array
    {
        usort($times, static fn(array $a, array $b): int => $a['cycle'] <=> $b['cycle']);

        return $times;
    }
}
