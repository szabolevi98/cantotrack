<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\ReleaseService;
use CantoTrack\Service\TicketQuery;
use CantoTrack\Service\TicketService;
use CantoTrack\Service\WorklogService;
use DateTimeImmutable;

final class TicketQueryTest extends DatabaseTestCase
{
    private int $me;
    private int $other;
    private TicketService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person('Anna Kovács');
        $this->other = $this->person('Márk Tóth');
        $project = $this->project();
        $this->service = new TicketService($this->db);
        (new ReleaseService($this->db))->create($project, '1.4', '', '', '');

        // CT-1 to CT-4, each with something the queries below tell apart.
        $this->service->create(['project_id' => $project, 'title' => 'Checkout adds shipping twice', 'type' => 'bug', 'priority' => 'urgent', 'assignee_id' => $this->me, 'labels' => 'shop', 'release' => '1.4'], $this->me);
        $this->service->create(['project_id' => $project, 'title' => 'Pay by card', 'type' => 'story', 'priority' => 'high', 'assignee_id' => $this->other, 'story_points' => '5', 'status' => 'done'], $this->me);
        $export = $this->service->create(['project_id' => $project, 'title' => 'Export the month', 'type' => 'task', 'priority' => 'low', 'estimate' => '3h', 'due_on' => '2026-01-10'], $this->me);
        $this->service->create(['project_id' => $project, 'title' => 'Mobile menu', 'type' => 'bug', 'priority' => 'normal', 'assignee_id' => $this->me, 'description' => 'The checkout button is hidden on a phone.'], $this->other);
        (new WorklogService($this->db))->log($export, $this->me, '5h', '2026-01-05', '');
    }

    /** @return list<string> the keys a query finds, in its order */
    private function keys(string $query): array
    {
        $compiled = TicketQuery::compile($query, $this->me, new DateTimeImmutable('2026-01-12 10:00'));
        $found = (new TicketRepository($this->db))->search([
            'query_where' => $compiled['where'],
            'query_params' => $compiled['params'],
            'query_order' => $compiled['order'],
        ], 100);

        return array_map(static fn(array $t): string => $t['project_code'] . '-' . $t['number'], $found);
    }

    public function testFieldsOperatorsAndOrder(): void
    {
        self::assertSame(['CT-4', 'CT-1'], $this->keys('assignee = me AND type = bug ORDER BY priority ASC'));
        self::assertSame(['CT-1', 'CT-2'], $this->keys('priority >= high ORDER BY key'));
        self::assertSame(['CT-2'], $this->keys('category = done'));
        self::assertSame(['CT-1', 'CT-3', 'CT-4'], $this->keys('category != done ORDER BY key ASC'));
        self::assertSame(['CT-2', 'CT-3'], $this->keys('type IN (story, task) ORDER BY key'));
        self::assertSame(['CT-1', 'CT-4'], $this->keys('NOT (type IN (story, task)) ORDER BY key'));
        self::assertSame(['CT-2'], $this->keys('assignee = "Márk Tóth"'));
        self::assertSame(['CT-3'], $this->keys('assignee IS EMPTY'));
        self::assertSame(['CT-1'], $this->keys('labels = shop AND release IN unreleased()'));
        self::assertSame(['CT-2'], $this->keys('points > 3'));
    }

    public function testWordsDaysAndHours(): void
    {
        self::assertSame(['CT-1', 'CT-4'], $this->keys('text ~ checkout ORDER BY key'));
        self::assertSame(['CT-1'], $this->keys('title ~ "shipping"'));
        self::assertSame(['CT-3'], $this->keys('due < startOfDay()'));
        self::assertSame(['CT-3'], $this->keys('due = -2d'));
        self::assertSame(['CT-3'], $this->keys('logged > 4h'));
        self::assertSame(['CT-3'], $this->keys('estimate = 3h'));
        self::assertSame(['CT-3'], $this->keys('estimate IS NOT EMPTY'));
        self::assertSame(['CT-1', 'CT-2', 'CT-3', 'CT-4'], $this->keys('ORDER BY key'));
    }

    public function testAMistakeSaysWhereItIs(): void
    {
        foreach ([
            'assignee = ' => 'at the end of the query',
            'colour = red' => 'no field called “colour”',
            'title = x' => 'does not take “=”',
            'priority = sometimes' => 'not a priority',
            'created > yesterday-ish' => 'not a day',
            'type IN (bug' => 'Expected “)”',
            'text ~ "open quote' => 'never closed',
        ] as $query => $expected) {
            try {
                TicketQuery::compile($query, $this->me);
                self::fail('No mistake found in: ' . $query);
            } catch (ValidationError $e) {
                self::assertStringContainsString($expected, $e->getMessage(), $query);
            }
        }
    }

    public function testNothingTypedEverReachesTheSql(): void
    {
        $compiled = TicketQuery::compile('title ~ "x\') OR 1=1 --" AND assignee = "a\'; DROP TABLE users; --"', $this->me);

        self::assertStringNotContainsString('DROP', $compiled['where']);
        self::assertStringNotContainsString('1=1', $compiled['where']);
        self::assertSame([], $this->keys('title ~ "x\') OR 1=1 --"'));
    }
}
