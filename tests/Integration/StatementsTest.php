<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\ClientRepository;
use CantoTrack\Model\StatementRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\Statements;
use CantoTrack\Service\TicketService;
use CantoTrack\Service\WorklogService;

final class StatementsTest extends DatabaseTestCase
{
    private int $me;
    private int $client;
    private int $ticket;
    private int $elsewhere;
    private string $from;
    private string $to;
    private Statements $service;
    private StatementRepository $statements;
    private WorklogService $worklogs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person('Szabó Levente', true, 'admin');
        (new UserRepository($this->db))->setRate($this->me, 20000.0);

        $this->client = (int) (new ClientRepository($this->db))->findOrCreate('Mecsek Clinic ' . random_int(1000, 9999));
        $project = $this->project('MC');
        $this->db->prepare('UPDATE projects SET client_id = :client WHERE id = :id')->execute(['client' => $this->client, 'id' => $project]);
        $this->ticket = (new TicketService($this->db))->create(['project_id' => $project, 'title' => 'Booking'], $this->me);

        // Another client's work, and work for nobody: neither is on the statement.
        $other = $this->project('XX');
        $this->elsewhere = (new TicketService($this->db))->create(['project_id' => $other, 'title' => 'Internal'], $this->me);

        $first = new \DateTimeImmutable('first day of last month');
        $this->from = $first->format('Y-m-d');
        $this->to = $first->modify('last day of this month')->format('Y-m-d');

        $this->service = new Statements($this->db);
        $this->statements = new StatementRepository($this->db);
        $this->worklogs = new WorklogService($this->db);
    }

    private function log(int $ticket, string $time, int $day = 2, bool $billable = true): int
    {
        return $this->worklogs->log($ticket, $this->me, $time, (new \DateTimeImmutable($this->from))->modify('+' . ($day - 1) . ' days')->format('Y-m-d'), null, '', $billable)['id'];
    }

    public function testADraftTakesTheClientsBillableHoursOfTheSpanAndNothingElse(): void
    {
        $this->log($this->ticket, '2h');
        $this->log($this->ticket, '1h', 5);
        $this->log($this->ticket, '3h', 6, false);
        $this->log($this->elsewhere, '4h');
        $this->worklogs->log($this->ticket, $this->me, '5h', date('Y-m-d'), null);

        $id = $this->service->draft($this->client, $this->from, $this->to, $this->me);
        $entries = $this->statements->entries($id);

        self::assertSame([120, 60], array_map('intval', array_column($entries, 'minutes')));
        self::assertSame(60000.0, round(array_sum(array_column($entries, 'amount')), 2));
    }

    public function testADraftIsAddressedAsTheClientsPageSays(): void
    {
        $clients = new ClientRepository($this->db);
        $client = (array) $clients->find($this->client);
        $clients->update($this->client, ['name' => (string) $client['name'], 'billing_address' => '7624 Pécs, Szigeti út 3.', 'tax_number' => '18934527-2-02', 'contact_name' => null, 'contact_email' => null, 'note' => null]);
        $this->log($this->ticket, '2h');

        $id = $this->service->draft($this->client, $this->from, $this->to, $this->me);
        $billTo = (string) $this->statements->find($id)['bill_to'];

        self::assertStringStartsWith($client['name'] . "\n7624 Pécs, Szigeti út 3.\n", $billTo);
        self::assertStringContainsString('18934527-2-02', $billTo);
        self::assertNull(ClientRepository::billTo(['name' => 'Nobody yet', 'billing_address' => '', 'tax_number' => null]));
    }

    public function testAnHourGoesOnOneStatementOnly(): void
    {
        $this->log($this->ticket, '2h');
        $this->service->draft($this->client, $this->from, $this->to, $this->me);

        $this->expectException(ValidationError::class);
        $this->service->draft($this->client, $this->from, $this->to, $this->me);
    }

    public function testIssuingNumbersItAndKeepsWhatAnHourWasWorthThen(): void
    {
        $this->log($this->ticket, '2h');
        $id = $this->service->draft($this->client, $this->from, $this->to, $this->me);

        $number = $this->service->issue((array) $this->statements->find($id), $this->me);
        self::assertMatchesRegularExpression('/^' . date('Y') . '-\d{3}$/', $number);

        // A rate raised afterwards does not change what was sent.
        (new UserRepository($this->db))->setRate($this->me, 30000.0);
        $issued = (array) $this->statements->find($id);

        self::assertSame('issued', $issued['state']);
        self::assertSame(40000.0, (float) $issued['amount']);
        self::assertSame(40000.0, (float) $this->statements->entries($id)[0]['amount']);
    }

    public function testTheNextStatementGetsTheNextNumber(): void
    {
        $this->log($this->ticket, '2h');
        $first = $this->service->issue((array) $this->statements->find($this->service->draft($this->client, $this->from, $this->to, $this->me)), $this->me);

        $this->worklogs->log($this->ticket, $this->me, '1h', date('Y-m-d'), null);
        $id = $this->service->draft($this->client, date('Y-m-01'), date('Y-m-d'), $this->me);
        $second = $this->service->issue((array) $this->statements->find($id), $this->me);

        self::assertSame((int) substr($first, 5) + 1, (int) substr($second, 5));
    }

    public function testABilledHourNoLongerChanges(): void
    {
        $entry = $this->log($this->ticket, '2h');
        $this->service->issue((array) $this->statements->find($this->service->draft($this->client, $this->from, $this->to, $this->me)), $this->me);
        $worklog = (array) $this->db->query('SELECT * FROM worklogs WHERE id = ' . $entry)->fetch();

        try {
            $this->worklogs->change($worklog, '3h', (string) $worklog['work_date'], null);
            self::fail('A billed hour was changed.');
        } catch (ValidationError $e) {
            self::assertStringContainsString('billed', $e->getMessage());
        }

        $this->expectException(ValidationError::class);
        $this->worklogs->remove($worklog);
    }

    public function testOpenedAgainItsHoursChangeAndItKeepsItsNumber(): void
    {
        $entry = $this->log($this->ticket, '2h');
        $id = $this->service->draft($this->client, $this->from, $this->to, $this->me);
        $number = $this->service->issue((array) $this->statements->find($id), $this->me);

        $this->service->reopen((array) $this->statements->find($id));
        $worklog = (array) $this->db->query('SELECT * FROM worklogs WHERE id = ' . $entry)->fetch();
        $this->worklogs->change($worklog, '3h', (string) $worklog['work_date'], null);

        self::assertSame($number, $this->service->issue((array) $this->statements->find($id), $this->me));
        self::assertSame(60000.0, (float) $this->statements->find($id)['amount']);
    }

    public function testOnceIssuedItIsIssuedAgainRatherThanDeleted(): void
    {
        $this->log($this->ticket, '2h');
        $id = $this->service->draft($this->client, $this->from, $this->to, $this->me);
        $this->service->issue((array) $this->statements->find($id), $this->me);
        $this->service->reopen((array) $this->statements->find($id));

        $this->expectException(ValidationError::class);
        $this->service->delete((array) $this->statements->find($id));
    }

    public function testADeletedDraftFreesItsHours(): void
    {
        $this->log($this->ticket, '2h');
        $id = $this->service->draft($this->client, $this->from, $this->to, $this->me);
        $this->service->delete((array) $this->statements->find($id));

        self::assertNull($this->statements->find($id));
        self::assertCount(1, $this->statements->unbilled($this->from, $this->to));
    }

    public function testTakenOffAnHourIsNotBilledAgainAndBroughtUpToDateTheDraftTakesNewHours(): void
    {
        $first = $this->log($this->ticket, '2h');
        $id = $this->service->draft($this->client, $this->from, $this->to, $this->me);

        $this->service->takeOff((array) $this->statements->find($id), $first);
        $this->log($this->ticket, '1h', 9);
        $changed = $this->service->refresh((array) $this->statements->find($id));

        self::assertSame(['added' => 1, 'removed' => 0], $changed);
        self::assertSame([60], array_map('intval', array_column($this->statements->entries($id), 'minutes')));
        self::assertSame('0', (string) $this->db->query('SELECT billable FROM worklogs WHERE id = ' . $first)->fetchColumn());
    }

    public function testAGuestReadsOnlyStatementsWhoseEveryHourIsInTheirProjects(): void
    {
        $this->log($this->ticket, '2h');
        $id = $this->service->draft($this->client, $this->from, $this->to, $this->me);
        $project = (int) $this->db->query('SELECT project_id FROM tickets WHERE id = ' . $this->ticket)->fetchColumn();

        self::assertContains($this->client, $this->statements->clientsSeenBy([$project]));
        self::assertTrue($this->statements->onlyIn($id, [$project]));
        self::assertFalse($this->statements->onlyIn($id, [$project + 1000]));
        self::assertSame([], $this->statements->clientsSeenBy([]));
    }

    public function testTheSummaryAddsUpByWhatItIsAskedFor(): void
    {
        $entries = [
            ['person' => 'Anna', 'work_type' => 'Design', 'ticket_key' => 'MC-1', 'ticket_title' => 'x', 'project_code' => 'MC', 'project_name' => 'Clinic', 'minutes' => 60, 'amount' => 100.0, 'rate' => 100.0],
            ['person' => 'Márk', 'work_type' => 'Design', 'ticket_key' => 'MC-2', 'ticket_title' => 'y', 'project_code' => 'MC', 'project_name' => 'Clinic', 'minutes' => 120, 'amount' => 300.0, 'rate' => 150.0],
        ];

        self::assertSame([['label' => 'MC — Clinic', 'minutes' => 180, 'amount' => 400.0, 'rate' => null]], Statements::summary($entries, 'project'));
        self::assertSame(['Márk', 'Anna'], array_column(Statements::summary($entries, 'person'), 'label'));
        self::assertSame(150.0, Statements::summary($entries, 'person')[0]['rate']);
    }
}
