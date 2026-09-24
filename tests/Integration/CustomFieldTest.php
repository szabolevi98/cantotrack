<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\CustomFieldRepository;
use CantoTrack\Model\EventRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\CustomFields;
use CantoTrack\Service\TicketQuery;
use CantoTrack\Service\TicketService;

final class CustomFieldTest extends DatabaseTestCase
{
    private int $me;
    private int $project;
    private TicketService $service;
    private int $platform;
    private int $budget;
    private int $legal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person();
        $this->project = $this->project();
        $this->service = new TicketService($this->db);
        $fields = new CustomFields($this->db);
        $this->platform = $fields->define($this->project, 'Platform', 'select', "iOS\nAndroid\nBoth", true);
        $this->budget = $fields->define($this->project, 'Budget', 'number', '', false);
        $this->legal = $fields->define($this->project, 'Legal review', 'checkbox', '', false);
    }

    public function testValuesAreKeptInTheFormOfTheirKind(): void
    {
        $id = $this->service->create(['project_id' => $this->project, 'title' => 'x', 'fields' => [$this->platform => 'android', $this->budget => '1 200,5', $this->legal => '1']], $this->me);
        $values = (new CustomFieldRepository($this->db))->valuesFor($id);

        self::assertSame('Android', $values[$this->platform]);
        self::assertSame('1200.5', $values[$this->budget]);
        self::assertSame('1', $values[$this->legal]);
    }

    public function testARequiredFieldAndAChoiceThatIsNotOne(): void
    {
        try {
            $this->service->create(['project_id' => $this->project, 'title' => 'x'], $this->me);
            self::fail('A required field was left out.');
        } catch (ValidationError $e) {
            self::assertStringContainsString('Platform', $e->getMessage());
        }

        $this->expectException(ValidationError::class);
        $this->service->create(['project_id' => $this->project, 'title' => 'x', 'fields' => ['Platform' => 'Windows Phone']], $this->me);
    }

    public function testAChangeIsInTheHistory(): void
    {
        $id = $this->service->create(['project_id' => $this->project, 'title' => 'x', 'fields' => ['Platform' => 'iOS']], $this->me);
        $this->service->update($id, ['fields' => ['Platform' => 'Both']], $this->me);

        $changed = array_values(array_filter((new EventRepository($this->db))->forTicket($id), static fn(array $e): bool => $e['field'] === 'Platform'));

        self::assertCount(1, $changed);
        self::assertSame('iOS', $changed[0]['old_value']);
        self::assertSame('Both', $changed[0]['new_value']);
    }

    public function testTheQueryLanguageKnowsThem(): void
    {
        $this->service->create(['project_id' => $this->project, 'title' => 'Cheap', 'fields' => ['Platform' => 'iOS', 'Budget' => '100']], $this->me);
        $this->service->create(['project_id' => $this->project, 'title' => 'Dear', 'fields' => ['Platform' => 'Android', 'Budget' => '900', 'Legal review' => true]], $this->me);

        $titles = function (string $query): array {
            $compiled = TicketQuery::compile($query, $this->me, null, (new CustomFieldRepository($this->db))->kindsByName());
            $found = (new TicketRepository($this->db))->search(['query_where' => $compiled['where'], 'query_params' => $compiled['params']]);

            return array_map(static fn(array $t): string => $t['title'], $found);
        };

        self::assertSame(['Dear'], $titles('Budget > 500'));
        self::assertSame(['Cheap'], $titles('Platform = iOS'));
        self::assertSame(['Dear'], $titles('"Legal review" = yes'));
        self::assertSame(['Cheap'], $titles('"Legal review" = no'));
        self::assertSame(['Dear'], $titles('Platform IN (Android, Both) AND budget >= 900'));
    }
}
