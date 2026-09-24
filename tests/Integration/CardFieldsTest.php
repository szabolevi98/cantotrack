<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Model\ProjectRepository;

final class CardFieldsTest extends DatabaseTestCase
{
    public function testACardShowsEverythingUntilSomebodyChoosesLess(): void
    {
        $projects = new ProjectRepository($this->db);
        $id = $this->project();

        self::assertSame(ProjectRepository::CARD_FIELDS, ProjectRepository::cardFields((array) $projects->find($id)));

        $projects->setCardFields($id, ['due', 'assignee', 'nonsense']);
        self::assertSame(['assignee', 'due'], ProjectRepository::cardFields((array) $projects->find($id)), 'in the settings\' order, and only real ones');

        $projects->setCardFields($id, ProjectRepository::CARD_FIELDS);
        self::assertNull($projects->find($id)['card_fields'], 'all of them is nothing kept');

        $projects->setCardFields($id, []);
        self::assertSame([], ProjectRepository::cardFields((array) $projects->find($id)));
    }
}
