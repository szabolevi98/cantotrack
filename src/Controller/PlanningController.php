<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\Calendar;
use CantoTrack\Service\Planning;
use DateTimeImmutable;

/**
 * Who works on what, a week at a time: the hours planned against the hours
 * each person has in their week, and beside them what was logged.
 *
 * Anybody in the team can look. An administrator plans for anybody; a
 * member plans their own week.
 */
class PlanningController extends Controller
{
    public function index(): void
    {
        Auth::requireMember();

        $given = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['week'] ?? ''));
        $monday = ($given === false ? new DateTimeImmutable('today') : $given)->modify('monday this week');
        $from = $monday->format('Y-m-d');
        $to = $monday->modify('+6 days')->format('Y-m-d');
        $dates = [];

        for ($i = 0; $i < 7; $i++) {
            $dates[] = $monday->modify('+' . $i . ' days')->format('Y-m-d');
        }

        $planning = new Planning();
        $plans = $planning->plans($from, $to);
        $planned = $planning->perDay($plans, $from, $to);
        $logged = (new WorklogRepository())->minutesByUserAndDay($from, $to);
        $calendar = new Calendar();
        $rows = [];

        foreach ((new UserRepository())->active() as $person) {
            if ($person['role'] === 'guest') {
                continue;
            }

            $id = (int) $person['id'];
            $days = $calendar->days($person, $from, $to);
            $cells = [];

            foreach ($dates as $date) {
                $cells[$date] = [
                    'planned' => $planned[$id][$date] ?? 0,
                    'logged' => $logged[$id][$date] ?? 0,
                    'expected' => $days[$date]['expected'],
                    'why' => $days[$date]['holiday'] ?? $days[$date]['absence'],
                ];
            }

            $rows[] = [
                'person' => $person,
                'cells' => $cells,
                'planned' => array_sum(array_column($cells, 'planned')),
                'logged' => array_sum(array_column($cells, 'logged')),
                'capacity' => array_sum(array_column($cells, 'expected')),
                'plans' => array_values(array_filter($plans, static fn(array $p): bool => (int) $p['user_id'] === $id)),
            ];
        }

        $this->render('planning/index.twig', [
            'rows' => $rows,
            'dates' => $dates,
            'monday' => $from,
            'sunday' => $to,
            'today' => date('Y-m-d'),
            'previous_week' => $monday->modify('-7 days')->format('Y-m-d'),
            'next_week' => $monday->modify('+7 days')->format('Y-m-d'),
            'projects' => (new ProjectRepository())->allWithCounts(),
            'people' => array_values(array_filter((new UserRepository())->active(), static fn(array $u): bool => $u['role'] !== 'guest')),
        ]);
    }

    public function create(): void
    {
        Auth::requireMember();

        $userId = (int) ($this->idInput('user') ?? Auth::id());

        if ($userId !== (int) Auth::id() && !Auth::isAdmin()) {
            $this->forbidden(__('You plan your own week; an administrator plans anybody’s.'));
        }

        try {
            (new Planning())->add(
                $userId,
                $this->input('ticket'),
                $this->idInput('project'),
                $this->input('starts_on'),
                $this->input('ends_on'),
                $this->input('per_day'),
                $this->input('note'),
                (int) Auth::id()
            );
            $this->flash(__('Planned.'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->back('/planning?week=' . $this->input('starts_on'));
    }

    public function delete(int $id): void
    {
        Auth::requireMember();

        $planning = new Planning();
        $plan = $planning->find($id);

        if ($plan === null) {
            $this->notFound(__('There is no such plan.'));
        }

        if ((int) $plan['user_id'] !== (int) Auth::id() && (int) $plan['created_by'] !== (int) Auth::id() && !Auth::isAdmin()) {
            $this->forbidden(__('You plan your own week; an administrator plans anybody’s.'));
        }

        $planning->remove($id);

        $this->flash(__('Taken off the plan.'), 'warning');
        $this->back('/planning');
    }
}
