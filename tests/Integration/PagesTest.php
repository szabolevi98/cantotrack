<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ConflictError;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\PageRepository;
use CantoTrack\Service\Pages;
use CantoTrack\Service\TicketService;

final class PagesTest extends DatabaseTestCase
{
    private int $me;
    private int $project;
    private Pages $pages;
    private PageRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person();
        $this->project = $this->project();
        $this->pages = new Pages($this->db);
        $this->repository = new PageRepository($this->db);
    }

    public function testATreeOfPagesThatCannotGoUnderItself(): void
    {
        $home = $this->pages->create($this->project, null, 'Home', '', $this->me);
        $child = $this->pages->create($this->project, $home, 'Child', '', $this->me);
        $grandchild = $this->pages->create($this->project, $child, 'Grandchild', '', $this->me);

        self::assertSame('Grandchild', $this->repository->tree($this->project)[0]['children'][0]['children'][0]['title']);
        self::assertSame(['Home', 'Child'], array_column($this->repository->ancestors((array) $this->repository->find($grandchild)), 'title'));

        $this->expectException(ValidationError::class);
        $this->pages->update((array) $this->repository->find($home), $grandchild, 'Home', '', $this->me, 1);
    }

    public function testEverySaveKeepsTheVersionBeforeIt(): void
    {
        $id = $this->pages->create($this->project, null, 'Decisions', 'First.', $this->me);
        $this->pages->update((array) $this->repository->find($id), null, 'Decisions', 'Second.', $this->me, 1);

        self::assertSame('First.', $this->repository->version($id, 1)['body']);

        // An edit made against version 1 now is somebody else's lost.
        try {
            $this->pages->update((array) $this->repository->find($id), null, 'Decisions', 'Stale.', $this->me, 1);
            self::fail('A stale edit went through.');
        } catch (ConflictError) {
        }

        $this->pages->restore((array) $this->repository->find($id), 1, $this->me);
        $page = (array) $this->repository->find($id);
        self::assertSame('First.', $page['body']);
        self::assertSame(3, (int) $page['version']);
    }

    public function testLinksTicketListsAndMentions(): void
    {
        $ticket = (new TicketService($this->db))->create(['project_id' => $this->project, 'title' => 'Pay by card'], $this->me);
        $this->pages->create($this->project, null, 'How we work', 'Nothing yet.', $this->me);
        $id = $this->pages->create($this->project, null, 'Home', "# Home\n\nSee [[How we work]] and [[Nowhere yet]], and CT-1.\n\n{{tickets project = CT}}\n\nThe end.", $this->me);

        $parts = $this->pages->render((array) $this->repository->find($id), $this->me);

        self::assertSame(['markdown', 'tickets', 'markdown'], array_column($parts, 'kind'));
        self::assertStringContainsString('/pages/', (string) $parts[0]['html']);
        self::assertStringContainsString('pages/create?title=Nowhere%20yet', (string) $parts[0]['html']);
        self::assertStringContainsString('id="h-home"', (string) $parts[0]['html']);
        self::assertSame('Pay by card', $parts[1]['tickets'][0]['title']);
        self::assertSame(['Home'], array_column($this->repository->mentioning($ticket), 'title'));
    }

    public function testADeletedPagesChildrenMoveUp(): void
    {
        $home = $this->pages->create($this->project, null, 'Home', '', $this->me);
        $child = $this->pages->create($this->project, $home, 'Child', '', $this->me);

        $this->pages->delete((array) $this->repository->find($home));

        self::assertNull($this->repository->find($child)['parent_id']);
    }

    public function testAProjectGoesWithItsTreeOfPages(): void
    {
        $home = $this->pages->create($this->project, null, 'Home', '', $this->me);
        $child = $this->pages->create($this->project, $home, 'Child', '', $this->me);
        $this->pages->create($this->project, $child, 'Grandchild', '', $this->me);

        (new \CantoTrack\Model\ProjectRepository($this->db))->delete($this->project);

        self::assertNull($this->repository->find($home));
    }

    public function testCommentsUnderAPageGoWithIt(): void
    {
        $home = $this->pages->create($this->project, null, 'Home', '', $this->me);
        $id = $this->repository->addComment($home, $this->me, 'Is this still true?');

        self::assertSame(['Is this still true?'], array_column($this->repository->comments($home), 'body'));
        self::assertSame($home, (int) $this->repository->findComment($id)['page_id']);

        $this->pages->delete((array) $this->repository->find($home));

        self::assertNull($this->repository->findComment($id));
    }
}
