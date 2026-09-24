<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\TemplateRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\Calendar;
use CantoTrack\Service\Templates;

final class TemplatesTest extends DatabaseTestCase
{
    private int $me;
    private int $project;
    private int $template;
    private Templates $service;
    private TemplateRepository $templates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person('Szabó Levente', true, 'admin');
        $this->project = $this->project('TP');
        $this->service = new Templates($this->db);
        $this->templates = new TemplateRepository($this->db);
        $this->template = $this->templates->create($this->project, Templates::clean([
            'name' => 'Server updates',
            'type' => 'task',
            'priority' => 'high',
            'title' => 'Server updates — {month}',
            'description' => '- [ ] Read the changelog',
            'estimate' => '2h',
            'subtasks' => "Back up the database\n\nUpdate the packages\n",
        ]), $this->me);
    }

    private function repeat(array $rule, string $nextOn): int
    {
        return $this->templates->createRecurring($rule + [
            'template_id' => $this->template,
            'assignee_id' => $this->me,
            'frequency' => 'monthly',
            'weekday' => null,
            'month_day' => 1,
            'due_days' => 3,
            'next_on' => $nextOn,
        ], $this->me);
    }

    /** @return list<array<string, mixed>> the project's tickets, the latest first, parents only */
    private function tickets(): array
    {
        $statement = $this->db->prepare('SELECT * FROM tickets WHERE project_id = :p AND parent_id IS NULL ORDER BY id DESC');
        $statement->execute(['p' => $this->project]);

        return array_values($statement->fetchAll());
    }

    public function testATemplateNeedsANameAndAnEstimateThatReads(): void
    {
        try {
            Templates::clean(['name' => ' ']);
            self::fail('A template without a name was taken.');
        } catch (ValidationError) {
        }

        $this->expectException(ValidationError::class);
        Templates::clean(['name' => 'x', 'estimate' => 'soonish']);
    }

    public function testTheBlankLinesOfItsStepsAreLeftOut(): void
    {
        self::assertSame(['Back up the database', 'Update the packages'], Templates::steps((array) $this->templates->find($this->template)));
    }

    public function testTheTitleSaysTheDayItIsMadeFor(): void
    {
        $day = new \DateTimeImmutable('2026-10-05');

        self::assertSame('2026-10 / 2026-W41 / 2026-10-05', Templates::title('{month} / {week} / {date}', $day));
    }

    public function testWeeklyMonthlyAndWorkingDays(): void
    {
        $friday = new \DateTimeImmutable('2026-10-02');

        self::assertSame('2026-10-05', $this->service->onOrAfter(['frequency' => 'weekly', 'weekday' => 1, 'month_day' => null], $friday)->format('Y-m-d'));
        self::assertSame('2026-10-02', $this->service->onOrAfter(['frequency' => 'weekly', 'weekday' => 5, 'month_day' => null], $friday)->format('Y-m-d'));
        self::assertSame('2027-02-28', $this->service->onOrAfter(['frequency' => 'monthly', 'weekday' => null, 'month_day' => 31], new \DateTimeImmutable('2027-02-01'))->format('Y-m-d'));
        self::assertSame('2026-11-15', $this->service->onOrAfter(['frequency' => 'monthly', 'weekday' => null, 'month_day' => 15], new \DateTimeImmutable('2026-10-16'))->format('Y-m-d'));

        // Saturday, Sunday and a public holiday are not working days.
        (new Calendar($this->db))->addHoliday('2026-10-05', 'A holiday');
        $service = new Templates($this->db);
        $next = $service->onOrAfter(['frequency' => 'workdays', 'weekday' => null, 'month_day' => null], new \DateTimeImmutable('2026-10-03'))->format('Y-m-d');
        (new Calendar($this->db))->removeHoliday('2026-10-05');
        self::assertSame('2026-10-06', $next);
    }

    public function testOnItsDayOneIsMadeWithItsTitleDueDateAndStepsAndOnlyOnce(): void
    {
        $id = $this->repeat([], '2026-10-01');

        $made = $this->service->runDue(new \DateTimeImmutable('2026-10-01'));
        self::assertCount(1, $made);
        self::assertSame([], $this->service->runDue(new \DateTimeImmutable('2026-10-01')));

        $ticket = (array) (new TicketRepository($this->db))->find($made[0]);
        self::assertSame('Server updates — 2026-10', $ticket['title']);
        self::assertSame('high', $ticket['priority']);
        self::assertSame('2026-10-04', $ticket['due_on']);
        self::assertSame(120, (int) $ticket['estimate_minutes']);
        self::assertSame($this->me, (int) $ticket['assignee_id']);
        self::assertCount(2, (new TicketRepository($this->db))->subtasks($made[0]));

        $recurring = (array) $this->templates->findRecurring($id);
        self::assertSame('2026-11-01', $recurring['next_on']);
        self::assertSame($made[0], (int) $recurring['last_ticket_id']);
    }

    public function testDaysMissedAreMadeUpOnceForTheLastOfThem(): void
    {
        $this->repeat(['frequency' => 'weekly', 'weekday' => 1, 'month_day' => null], '2026-09-07');

        $made = $this->service->runDue(new \DateTimeImmutable('2026-09-24'));

        self::assertCount(1, $made);
        self::assertSame('Server updates — 2026-09', $this->tickets()[0]['title']);
        // Made for the last Monday missed, the 21st: due three days later.
        self::assertSame('2026-09-24', (new TicketRepository($this->db))->find($made[0])['due_on']);
        self::assertSame('2026-09-28', $this->templates->findRecurring((int) $this->db->query('SELECT MAX(id) FROM recurring_tickets')->fetchColumn())['next_on']);
    }

    public function testPausedItMakesNothingAndMadeByHandItsNextDayStays(): void
    {
        $id = $this->repeat([], '2026-10-01');
        $this->templates->setActive($id, false);

        self::assertSame([], $this->service->runDue(new \DateTimeImmutable('2026-10-01')));

        $ticket = $this->service->makeNow((array) $this->templates->findRecurring($id), new \DateTimeImmutable('2026-10-01'));
        self::assertGreaterThan(0, $ticket);
        self::assertSame('2026-10-01', $this->templates->findRecurring($id)['next_on']);
    }
}
