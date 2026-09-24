<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\GadgetRepository;
use CantoTrack\Service\Gadgets;
use CantoTrack\Service\TicketService;

final class GadgetsTest extends DatabaseTestCase
{
    public function testAPieceShowsWhatItsQueryFinds(): void
    {
        $me = $this->person();
        $project = $this->project();
        $tickets = new TicketService($this->db);
        $tickets->create(['project_id' => $project, 'title' => 'a', 'type' => 'bug', 'priority' => 'urgent'], $me);
        $tickets->create(['project_id' => $project, 'title' => 'b', 'type' => 'bug'], $me);
        $tickets->create(['project_id' => $project, 'title' => 'c', 'type' => 'task'], $me);

        $gadgets = new Gadgets($this->db);
        $gadgets->add($me, 'count', 'Bugs', 'type = bug', '');
        $gadgets->add($me, 'breakdown', 'By type', '', 'type');
        $gadgets->add($me, 'list', 'Urgent', 'priority = urgent', '');

        [$count, $split, $list] = $gadgets->drawn($me);

        self::assertSame(2, $count['count']);
        self::assertSame([['label' => 'bug', 'count' => 2], ['label' => 'task', 'count' => 1]], array_map(
            static fn(array $g): array => ['label' => $g['label'], 'count' => $g['count']],
            $split['groups']
        ));
        self::assertStringContainsString(urlencode('type = "bug"'), $split['groups'][0]['link']);
        self::assertSame(['a'], array_column($list['tickets'], 'title'));
    }

    public function testAQueryThatDoesNotReadIsRefusedAtOnce(): void
    {
        $this->expectException(ValidationError::class);
        (new Gadgets($this->db))->add($this->person(), 'list', 'Broken', 'priority = soon', '');
    }

    public function testAPieceIsOnlyItsOwnersToMoveOrRemove(): void
    {
        $me = $this->person();
        $other = $this->person('Bence Tóth');
        $gadgets = new Gadgets($this->db);
        $first = $gadgets->add($me, 'count', 'One', '', '');
        $second = $gadgets->add($me, 'count', 'Two', '', '');
        $repository = new GadgetRepository($this->db);

        $repository->move($second, $me, -1);
        self::assertSame(['Two', 'One'], array_column($repository->forUser($me), 'title'));

        $repository->delete($first, $other);
        self::assertCount(2, $repository->forUser($me));
    }

    public function testANarrowedQueryKeepsItsOrderAtTheEnd(): void
    {
        self::assertSame('(category != done) AND assignee = "Anna" ORDER BY priority DESC', Gadgets::narrowed('category != done ORDER BY priority DESC', 'assignee', 'Anna'));
        self::assertSame('epic IS EMPTY', Gadgets::narrowed('', 'epic', ''));
    }
}
