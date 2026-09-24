<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Format;
use CantoTrack\Core\Logger;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\TemplateRepository;
use CantoTrack\Model\TicketRepository;
use PDO;

/**
 * Tickets written the same way again and again.
 *
 * A template is what a new ticket starts from — its type and priority, a
 * title, a description with its checklist, labels, an estimate, the steps it
 * is broken into — chosen on the new-ticket form, where everything can still
 * be changed. A repeating ticket makes one from a template by itself, on its
 * day: every working day, every week on a weekday, or every month on a day,
 * run each morning with the daily automation (bin/automation.php).
 */
final class Templates
{
    public const FREQUENCIES = ['workdays', 'weekly', 'monthly'];

    private TemplateRepository $templates;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
        $this->templates = new TemplateRepository($this->db);
    }

    /**
     * What a form sent, as a template is kept — or what is wrong with it.
     *
     * @param array<array-key, mixed> $posted
     * @return array<string, mixed>
     * @throws ValidationError
     */
    public static function clean(array $posted): array
    {
        $text = static fn(string $key, int $length): ?string => ($value = trim(str_replace("\r\n", "\n", (string) ($posted[$key] ?? '')))) === '' ? null : mb_substr($value, 0, $length);

        $name = $text('name', 120);
        if ($name === null) {
            throw new ValidationError(__('A template needs a name.'));
        }

        $type = (string) ($posted['type'] ?? 'task');
        $priority = (string) ($posted['priority'] ?? 'normal');
        if (!in_array($type, TicketRepository::TYPES, true) || !in_array($priority, TicketRepository::PRIORITIES, true)) {
            throw new ValidationError(__('That is not a type or a priority a ticket can have.'));
        }

        $estimate = $text('estimate', 40);
        $minutes = $estimate === null ? null : Format::parseDuration($estimate);
        if ($estimate !== null && $minutes === null) {
            throw new ValidationError(__('The estimate should read like "3h", "90m" or "1h 30m".'));
        }

        $points = $text('story_points', 5);
        if ($points !== null && (!ctype_digit($points) || (int) $points > 999)) {
            throw new ValidationError(__('Story points are a whole number.'));
        }

        $steps = array_values(array_filter(array_map('trim', explode("\n", (string) ($text('subtasks', 5000) ?? ''))), static fn(string $s): bool => $s !== ''));

        return [
            'name' => $name,
            'type' => $type,
            'priority' => $priority,
            'title' => $text('title', 250) ?? '',
            'description' => $text('description', 20000),
            'labels' => $text('labels', 500),
            'estimate_minutes' => $minutes ?: null,
            'story_points' => $points === null ? null : (int) $points,
            'subtasks' => $steps === [] ? null : implode("\n", array_map(static fn(string $s): string => mb_substr($s, 0, 250), array_slice($steps, 0, 30))),
        ];
    }

    /**
     * A title with its placeholders filled in for a day: {date} is the day,
     * {week} its week (2026-W41), {month} its month (2026-10).
     */
    public static function title(string $title, \DateTimeImmutable $day): string
    {
        return strtr($title, [
            '{date}' => $day->format('Y-m-d'),
            '{week}' => $day->format('o-\WW'),
            '{month}' => $day->format('Y-m'),
        ]);
    }

    /**
     * The new-ticket form's values from a template, for today.
     *
     * @return array<string, mixed>
     */
    public static function formValues(array $template, ?\DateTimeImmutable $day = null): array
    {
        return [
            'type' => $template['type'],
            'priority' => $template['priority'],
            'title' => self::title((string) $template['title'], $day ?? new \DateTimeImmutable('today')),
            'description' => (string) ($template['description'] ?? ''),
            'labels' => (string) ($template['labels'] ?? ''),
            'estimate_minutes' => $template['estimate_minutes'],
            'story_points' => $template['story_points'],
            'template_id' => (int) $template['id'],
            'template_name' => (string) $template['name'],
            'template_steps' => self::steps($template),
        ];
    }

    /** @return list<string> the subtasks a template breaks a ticket into */
    public static function steps(array $template): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) ($template['subtasks'] ?? ''))), static fn(string $s): bool => $s !== ''));
    }

    /** A ticket made from a template has its steps made too. */
    public function addSteps(int $ticketId, array $template, ?int $assigneeId, int $reporterId): void
    {
        $parent = (new TicketRepository($this->db))->find($ticketId);

        if ($parent === null) {
            return;
        }

        foreach (self::steps($template) as $step) {
            (new TicketService($this->db))->addSubtask($parent, $step, $assigneeId, $reporterId);
        }
    }

    /**
     * A repeating ticket from its form, with the first day it is made for.
     *
     * @param array<array-key, mixed> $posted
     * @return array{template_id: int, assignee_id: ?int, frequency: string, weekday: ?int, month_day: ?int, due_days: ?int, next_on: string}
     * @throws ValidationError
     */
    public function cleanRecurring(array $template, array $posted, \DateTimeImmutable $today): array
    {
        $frequency = (string) ($posted['frequency'] ?? '');
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            throw new ValidationError(__('Choose how often it is made.'));
        }

        $weekday = $frequency === 'weekly' ? (int) ($posted['weekday'] ?? 0) : null;
        $monthDay = $frequency === 'monthly' ? (int) ($posted['month_day'] ?? 0) : null;

        if ($weekday !== null && ($weekday < 1 || $weekday > 7)) {
            throw new ValidationError(__('Choose the day of the week.'));
        }
        if ($monthDay !== null && ($monthDay < 1 || $monthDay > 31)) {
            throw new ValidationError(__('The day of the month is between 1 and 31.'));
        }

        $due = trim((string) ($posted['due_days'] ?? ''));
        if ($due !== '' && (!ctype_digit($due) || (int) $due > 365)) {
            throw new ValidationError(__('Due after a number of days, up to a year.'));
        }

        $assignee = (int) ($posted['assignee_id'] ?? 0);
        $rule = ['frequency' => $frequency, 'weekday' => $weekday, 'month_day' => $monthDay];

        return [
            'template_id' => (int) $template['id'],
            'assignee_id' => $assignee > 0 ? $assignee : null,
            'frequency' => $frequency,
            'weekday' => $weekday,
            'month_day' => $monthDay,
            'due_days' => $due === '' ? null : (int) $due,
            // Today counts: made up in the morning run, or at once from the page.
            'next_on' => $this->onOrAfter($rule, $today)->format('Y-m-d'),
        ];
    }

    /**
     * The first day on or after `$day` that a repeating ticket is made for.
     *
     * @param array<string, mixed> $rule its frequency, weekday and month_day
     */
    public function onOrAfter(array $rule, \DateTimeImmutable $day): \DateTimeImmutable
    {
        $weekdays = [1 => 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        return match ($rule['frequency']) {
            'weekly' => (int) $day->format('N') === (int) $rule['weekday'] ? $day : $day->modify('next ' . $weekdays[(int) $rule['weekday']]),
            'monthly' => $this->monthDay($day, (int) $rule['month_day']) >= $day
                ? $this->monthDay($day, (int) $rule['month_day'])
                : $this->monthDay($day->modify('first day of next month'), (int) $rule['month_day']),
            default => $this->workday($day),
        };
    }

    /** The day itself if it is a working day, or the next one that is. */
    private function workday(\DateTimeImmutable $day): \DateTimeImmutable
    {
        $calendar = new Calendar($this->db);

        while ((int) $day->format('N') > 5 || $calendar->holiday($day->format('Y-m-d')) !== null) {
            $day = $day->modify('+1 day');
        }

        return $day;
    }

    /**
     * Makes today's repeating tickets — and, for one whose day was missed,
     * the latest missed day's once. Returns the tickets made.
     *
     * @return list<int>
     */
    public function runDue(\DateTimeImmutable $today): array
    {
        $made = [];

        foreach ($this->templates->due($today->format('Y-m-d')) as $recurring) {
            try {
                $id = $this->make($recurring, $today);
                if ($id !== null) {
                    $made[] = $id;
                }
            } catch (\Throwable $e) {
                // One that cannot be made does not stop the others.
                Logger::error('A repeating ticket could not be made: ' . $e->getMessage(), ['recurring' => $recurring['id']]);
            }
        }

        return $made;
    }

    /**
     * One repeating ticket made for the latest of its days that has come,
     * and moved on to its next one. Null when another run got there first.
     */
    public function make(array $recurring, \DateTimeImmutable $today): ?int
    {
        $day = new \DateTimeImmutable((string) $recurring['next_on']);

        // Missed days are made up once: for the last of them.
        while (($following = $this->onOrAfter($recurring, $day->modify('+1 day'))) <= $today) {
            $day = $following;
        }

        if (!$this->templates->claim((int) $recurring['id'], (string) $recurring['next_on'], $this->onOrAfter($recurring, $today->modify('+1 day'))->format('Y-m-d'))) {
            return null;
        }

        return $this->ticketFor($recurring, $day);
    }

    /** One made at once, from the page, for today — its next day stays as it was. */
    public function makeNow(array $recurring, \DateTimeImmutable $today): int
    {
        return $this->ticketFor($recurring, $today);
    }

    /** The ticket itself, for a day: its title, its due date, its steps. */
    private function ticketFor(array $recurring, \DateTimeImmutable $day): int
    {
        $read = $this->db->prepare('SELECT * FROM ticket_templates WHERE id = :id');
        $read->execute(['id' => (int) $recurring['template_id']]);
        $template = (array) $read->fetch();

        // Made by whoever set it up — or, once they are gone, an administrator.
        $reporter = (int) ($recurring['created_by'] ?? 0);
        if ($reporter === 0) {
            $admin = $this->db->prepare("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");
            $admin->execute();
            $reporter = (int) $admin->fetchColumn();
        }
        $assignee = $recurring['assignee_id'] === null ? null : (int) $recurring['assignee_id'];

        $ticket = (new TicketService($this->db))->create([
            'project_id' => (int) $template['project_id'],
            'title' => self::title((string) $template['title'] ?: (string) $template['name'], $day),
            'type' => $template['type'],
            'priority' => $template['priority'],
            'description' => (string) ($template['description'] ?? ''),
            'labels' => (string) ($template['labels'] ?? ''),
            'estimate_minutes' => $template['estimate_minutes'],
            'story_points' => $template['story_points'],
            'assignee_id' => $assignee,
            'due_on' => $recurring['due_days'] === null ? '' : $day->modify('+' . (int) $recurring['due_days'] . ' days')->format('Y-m-d'),
        ], $reporter);

        $this->addSteps($ticket, $template, $assignee, $reporter);
        $this->templates->made((int) $recurring['id'], $ticket);

        return $ticket;
    }

    /** The day of a month, or its last day when it has fewer. */
    private function monthDay(\DateTimeImmutable $inMonth, int $dayOfMonth): \DateTimeImmutable
    {
        $first = $inMonth->modify('first day of this month');

        return $first->modify('+' . (min($dayOfMonth, (int) $first->format('t')) - 1) . ' days');
    }
}
