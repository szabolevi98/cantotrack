<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Controller\TicketController;
use CantoTrack\Model\ClientRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\TicketQuery;
use CantoTrack\Service\TicketService;

/**
 * The lists whose column headers sort them: every column runs both ways
 * against the real schema, the order really follows the header and goes back,
 * and every header a template offers is one its list can sort by.
 */
final class SortableListsTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    public function testEveryColumnOfThePeopleAndClientListsRunsBothWays(): void
    {
        $this->person();
        (new ClientRepository($this->db))->findOrCreate('Acme');

        foreach (['asc', 'desc'] as $dir) {
            foreach (array_keys(UserRepository::SORTS) as $key) {
                $_GET = ['sort' => $key, 'dir' => $dir];
                self::assertNotEmpty((new UserRepository($this->db))->withActivity(), "people by $key $dir");
            }
            foreach (array_keys(ClientRepository::SORTS) as $key) {
                $_GET = ['sort' => $key, 'dir' => $dir];
                self::assertNotEmpty((new ClientRepository($this->db))->overview('2026-09-01', '2026-09-30'), "clients by $key $dir");
            }
        }
    }

    public function testEveryTicketColumnRunsBothWays(): void
    {
        $me = $this->person();
        (new TicketService($this->db))->create(['project_id' => $this->project(), 'title' => 'One'], $me);

        foreach (self::ticketSorts() as $key) {
            foreach (['asc', 'desc'] as $dir) {
                $order = TicketQuery::orderFor($key, $dir);
                self::assertNotNull($order, "tickets cannot be put in order by $key");
                self::assertCount(1, (new TicketRepository($this->db))->search(['header_order' => $order]), "tickets by $key $dir");
            }
        }
    }

    public function testTheTicketOrderFollowsTheHeaderAndGoesBack(): void
    {
        $me = $this->person();
        $project = $this->project();
        $service = new TicketService($this->db);
        $service->create(['project_id' => $project, 'title' => 'Brakes', 'priority' => 'low'], $me);
        $service->create(['project_id' => $project, 'title' => 'Chain', 'priority' => 'urgent'], $me);
        $service->create(['project_id' => $project, 'title' => 'Axle', 'priority' => 'normal'], $me);
        $titles = fn(array $filters): array => array_column((new TicketRepository($this->db))->search($filters), 'title');

        self::assertSame(['Axle', 'Brakes', 'Chain'], $titles(['header_order' => TicketQuery::orderFor('title', 'asc')]));
        self::assertSame(['Chain', 'Brakes', 'Axle'], $titles(['header_order' => TicketQuery::orderFor('title', 'desc')]));
        self::assertSame(['Chain', 'Axle', 'Brakes'], $titles([]), 'no header: by priority, as before');

        // The header goes before a query's own order.
        $query = TicketQuery::compile('ORDER BY priority DESC', $me);
        self::assertSame(['Axle', 'Brakes', 'Chain'], $titles([
            'query_where' => $query['where'] ?: 'TRUE', 'query_params' => $query['params'], 'query_order' => $query['order'],
            'header_order' => TicketQuery::orderFor('title', 'asc'),
        ]));
    }

    public function testTicketsWithoutADateComeLastEitherWay(): void
    {
        $me = $this->person();
        $project = $this->project();
        $service = new TicketService($this->db);
        $service->create(['project_id' => $project, 'title' => 'Undated'], $me);
        $service->create(['project_id' => $project, 'title' => 'Late', 'due_on' => '2026-10-20'], $me);
        $service->create(['project_id' => $project, 'title' => 'Soon', 'due_on' => '2026-10-01'], $me);
        $titles = fn(string $dir): array => array_column((new TicketRepository($this->db))->search(['header_order' => TicketQuery::orderFor('due', $dir)]), 'title');

        self::assertSame(['Soon', 'Late', 'Undated'], $titles('asc'));
        self::assertSame(['Late', 'Soon', 'Undated'], $titles('desc'));
    }

    public function testEveryHeaderInTheTemplatesIsSortableByItsList(): void
    {
        $lists = [
            'tickets/index.twig' => self::ticketSorts(),
            'people/index.twig' => array_keys(UserRepository::SORTS),
            'clients/index.twig' => array_keys(ClientRepository::SORTS),
        ];

        foreach ($lists as $template => $keys) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/View/' . $template);
            preg_match_all("/sort_link\\('([a-z0-9_]+)'/", $source, $links);
            preg_match_all("/sort_aria\\('([a-z0-9_]+)'/", $source, $arias);

            self::assertEqualsCanonicalizing($keys, $links[1], "$template offers exactly the columns its list sorts by");
            self::assertSame($links[1], $arias[1], "$template marks the same header it links");
        }
    }

    /** @return list<string> */
    private static function ticketSorts(): array
    {
        return (new \ReflectionClassConstant(TicketController::class, 'SORTS'))->getValue();
    }
}
