<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Config;
use CantoTrack\Core\Controller;
use CantoTrack\Core\View;
use CantoTrack\Model\UserRepository;
use CantoTrack\Model\WorklogRepository;
use DateTimeImmutable;

/**
 * The week: what somebody logged, on which day, against what.
 *
 * A week rather than a month, because a week is the span people can still
 * remember well enough to correct. A month's timesheet is filled in by guessing.
 *
 * Anyone can look at anyone's — the hours are how a team's week is understood,
 * not something to be kept from each other. Editing is a different matter and
 * lives in the worklog controller.
 */
class TimesheetController extends Controller
{
    public function index(): void
    {
        Auth::require();

        $monday = $this->mondayOf((string) ($_GET['week'] ?? ''));
        $sunday = $monday->modify('+6 days');

        $userId = (int) ($_GET['user'] ?? 0) ?: (int) Auth::id();
        $people = (new UserRepository())->active();
        $worklogs = new WorklogRepository();

        $entries = $worklogs->forRange($userId, $monday->format('Y-m-d'), $sunday->format('Y-m-d'));

        // The days are built first and then filled, so a day with nothing on it
        // is still a row. A timesheet that skips empty days hides exactly the
        // thing somebody is looking for.
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $monday->modify('+' . $i . ' days')->format('Y-m-d');
            $days[$date] = ['date' => $date, 'entries' => [], 'minutes' => 0];
        }

        foreach ($entries as $entry) {
            $day = (string) $entry['work_date'];

            if (!isset($days[$day])) {
                continue;
            }

            $days[$day]['entries'][] = $entry;
            $days[$day]['minutes'] += (int) $entry['minutes'];
        }

        $expected = Config::int('work.hours_per_day', 8) * 60;

        View::render('timesheet/index.twig', [
            'days' => $days,
            'people' => $people,
            'person' => (new UserRepository())->find($userId),
            'is_mine' => $userId === (int) Auth::id(),
            'monday' => $monday->format('Y-m-d'),
            'sunday' => $sunday->format('Y-m-d'),
            'previous_week' => $monday->modify('-7 days')->format('Y-m-d'),
            'next_week' => $monday->modify('+7 days')->format('Y-m-d'),
            // A week ahead of this one is not something anybody needs to open.
            'has_next' => $monday->modify('+7 days') <= new DateTimeImmutable('today'),
            'total' => $worklogs->totalMinutes($userId, $monday->format('Y-m-d'), $sunday->format('Y-m-d')),
            'by_project' => $worklogs->minutesByProject($userId, $monday->format('Y-m-d'), $sunday->format('Y-m-d')),
            'expected_per_day' => $expected,
            // Only the working days are expected to be full, which is what the
            // week's target is measured against.
            'expected_week' => $expected * 5,
            'team' => Auth::isAdmin()
                ? $worklogs->minutesByUserAndDay($monday->format('Y-m-d'), $sunday->format('Y-m-d'))
                : [],
        ]);
    }

    /**
     * The Monday of the week a date falls in, or of this week when nothing
     * usable was given.
     *
     * Monday rather than Sunday because that is what a working week starts on
     * here, and because ISO weeks are what any export will be compared against.
     */
    private function mondayOf(string $given): DateTimeImmutable
    {
        $date = $given === '' ? false : DateTimeImmutable::createFromFormat('Y-m-d', $given);

        if ($date === false) {
            $date = new DateTimeImmutable('today');
        }

        return $date->modify('monday this week')->setTime(0, 0);
    }
}
