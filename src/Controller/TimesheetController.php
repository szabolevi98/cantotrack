<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Config;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Format;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\WorklogService;
use DateTimeImmutable;

/**
 * The week: what somebody logged, on which day, against what — as a list of
 * days, or as a grid of tickets by days that can be filled in directly.
 *
 * A week rather than a month, because a week is the span people can still
 * remember well enough to correct. A month's timesheet is filled in by guessing.
 *
 * Anyone can look at anyone's — the hours are how a team's week is understood,
 * not something to be kept from each other. Changing them is a different
 * matter: one's own, or anybody's as an administrator.
 */
class TimesheetController extends Controller
{
    public function index(): void
    {
        Auth::require();

        $monday = $this->mondayOf((string) ($_GET['week'] ?? ''));
        $sunday = $monday->modify('+6 days');
        $from = $monday->format('Y-m-d');
        $to = $sunday->format('Y-m-d');

        $userId = (int) ($_GET['user'] ?? 0) ?: (int) Auth::id();
        $person = (new UserRepository())->find($userId);

        if ($person === null) {
            $this->notFound(__('There is no such person.'));
        }

        $worklogs = new WorklogRepository();
        $entries = $worklogs->forRange($userId, $from, $to);

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
        $view = ($_GET['view'] ?? '') === 'grid' ? 'grid' : 'days';

        $this->render('timesheet/index.twig', [
            'view' => $view,
            'days' => $days,
            'grid' => $view === 'grid' ? $this->grid($userId, $entries, array_keys($days)) : [],
            'people' => (new UserRepository())->active(),
            'person' => $person,
            'is_mine' => $userId === (int) Auth::id(),
            'can_change' => $userId === (int) Auth::id() || Auth::isAdmin(),
            'monday' => $from,
            'sunday' => $to,
            'today' => date('Y-m-d'),
            'previous_week' => $monday->modify('-7 days')->format('Y-m-d'),
            'next_week' => $monday->modify('+7 days')->format('Y-m-d'),
            // A week ahead of this one is not something anybody needs to open.
            'has_next' => $monday->modify('+7 days') <= new DateTimeImmutable('today'),
            'total' => $worklogs->totalMinutes($userId, $from, $to),
            'by_project' => $worklogs->minutesByProject($userId, $from, $to),
            'expected_per_day' => $expected,
            // Only the working days are expected to be full, which is what the
            // week's target is measured against.
            'expected_week' => $expected * 5,
            'team' => Auth::isAdmin() ? $worklogs->minutesByUserAndDay($from, $to) : [],
        ]);
    }

    /**
     * The week as a grid: a row per ticket, a column per day, each cell the
     * time on that ticket that day.
     *
     * The rows are the tickets with time on them this week, then the ones
     * the person has in progress — the ones they are most likely to have
     * worked on and not written down yet — and any added by name.
     *
     * @param array<array> $entries
     * @param list<string> $dates
     * @return list<array<string, mixed>>
     */
    private function grid(int $userId, array $entries, array $dates): array
    {
        $tickets = new TicketRepository();
        $rows = [];
        $empty = array_fill_keys($dates, ['minutes' => 0, 'count' => 0]);

        foreach ($entries as $entry) {
            $id = (int) $entry['ticket_id'];
            $day = (string) $entry['work_date'];

            $rows[$id] ??= [
                'ticket' => [
                    'id' => $id,
                    'project_code' => $entry['project_code'],
                    'number' => $entry['ticket_number'],
                    'title' => $entry['ticket_title'],
                ],
                'cells' => $empty,
                'total' => 0,
            ];

            $rows[$id]['cells'][$day] = [
                'minutes' => (int) ($rows[$id]['cells'][$day]['minutes'] ?? 0) + (int) $entry['minutes'],
                'count' => (int) ($rows[$id]['cells'][$day]['count'] ?? 0) + 1,
            ];
            $rows[$id]['total'] += (int) $entry['minutes'];
        }

        $more = $tickets->search(['assignee_id' => $userId, 'status' => 'in_progress'], 20);

        foreach (array_filter(array_map('trim', explode(',', (string) ($_GET['add'] ?? '')))) as $key) {
            $ticket = $tickets->findByKey($key);

            if ($ticket !== null) {
                $more[] = $ticket;
            }
        }

        foreach ($more as $ticket) {
            $rows[(int) $ticket['id']] ??= ['ticket' => $ticket, 'cells' => $empty, 'total' => 0];
        }

        return array_values($rows);
    }

    /**
     * The grid, saved: every cell that changed becomes an entry, a changed
     * entry, or no entry.
     *
     * A cell that holds one entry is that entry, and changing the cell changes
     * it. A cell that holds several (two stretches on the same ticket, each
     * with its note) is not rewritten from one number — which of them would
     * the difference belong to? — and is left as it was, with a word about it.
     */
    public function saveGrid(): void
    {
        Auth::require();

        $userId = (int) ($this->idInput('user') ?? Auth::id());

        if ($userId !== (int) Auth::id() && !Auth::isAdmin()) {
            $this->forbidden(__('Those are somebody else’s hours.'));
        }

        $monday = $this->mondayOf($this->input('week'));
        $from = $monday->format('Y-m-d');
        $to = $monday->modify('+6 days')->format('Y-m-d');
        $cells = is_array($_POST['cells'] ?? null) ? $_POST['cells'] : [];

        $worklogs = new WorklogRepository();
        $service = new WorklogService();
        $existing = [];

        foreach ($worklogs->forRange($userId, $from, $to) as $entry) {
            $existing[(int) $entry['ticket_id']][(string) $entry['work_date']][] = $entry;
        }

        $changed = 0;
        $problems = [];

        foreach ($cells as $ticketId => $byDay) {
            if (!ctype_digit((string) $ticketId) || !is_array($byDay)) {
                continue;
            }

            foreach ($byDay as $date => $typed) {
                $date = (string) $date;
                if ($date < $from || $date > $to || !is_string($typed)) {
                    continue;
                }

                $there = $existing[(int) $ticketId][$date] ?? [];
                $before = array_sum(array_map(static fn(array $e): int => (int) $e['minutes'], $there));
                $typed = trim($typed);
                $after = $typed === '' ? 0 : Format::parseDuration($typed);

                if ($after === null) {
                    $problems[] = __('“{typed}” is not a time.', ['typed' => $typed]);
                    continue;
                }

                if ($after === $before) {
                    continue;
                }

                try {
                    if (count($there) > 1) {
                        $problems[] = __('{day} has several entries on one ticket; change those one by one on the day list.', ['day' => Format::day($date)]);
                    } elseif ($there === []) {
                        $service->log((int) $ticketId, $userId, $after . 'm', $date, null);
                        $changed++;
                    } elseif ($after === 0) {
                        $service->remove($there[0]);
                        $changed++;
                    } else {
                        $service->change($there[0], $after . 'm', $date, $there[0]['note']);
                        $changed++;
                    }
                } catch (ValidationError $e) {
                    $problems[] = Format::day($date) . ': ' . $e->getMessage();
                }
            }
        }

        $this->flash(__n('{count} day changed.', '{count} days changed.', $changed), $changed > 0 ? 'success' : 'warning');

        foreach (array_unique($problems) as $problem) {
            $this->flash($problem, 'danger');
        }

        $this->redirect('/timesheet?view=grid&week=' . $from . '&user=' . $userId);
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
