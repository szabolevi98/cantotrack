<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Model\PageRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\CommentService;
use CantoTrack\Service\Pages;
use CantoTrack\Service\TicketService;

final class SearchTest extends DatabaseTestCase
{
    /** @return list<string> */
    private function titles(string $words): array
    {
        return array_column((new TicketRepository($this->db))->search(['q' => $words, 'order' => 'relevance']), 'title');
    }

    public function testWordsAreFoundInTheTitleTheTextAndTheComments(): void
    {
        $me = $this->person();
        $project = $this->project();
        $service = new TicketService($this->db);
        $service->create(['project_id' => $project, 'title' => 'Refunds go out twice'], $me);
        $service->create(['project_id' => $project, 'title' => 'Payments', 'description' => 'A disputed ride is refunded twice.'], $me);
        $commented = $service->create(['project_id' => $project, 'title' => 'Support asked about money'], $me);
        $service->create(['project_id' => $project, 'title' => 'Something else'], $me);
        (new CommentService($this->db))->add($commented, $me, 'The customer got a refund twice.');

        $found = $this->titles('refund twice');

        self::assertCount(3, $found);
        self::assertSame('Refunds go out twice', $found[0], 'a match in the title comes first');
        self::assertContains('Support asked about money', $found);
    }

    public function testWordsTheIndexDoesNotKeepAreStillFound(): void
    {
        $me = $this->person();
        $service = new TicketService($this->db);
        $service->create(['project_id' => $this->project(), 'title' => 'The UI is slow'], $me);

        self::assertSame(['The UI is slow'], $this->titles('UI'));
        self::assertSame(['The UI is slow'], $this->titles('the slow'));
    }

    public function testPagesAreFoundBestMatchFirst(): void
    {
        $me = $this->person();
        $project = $this->project();
        $pages = new Pages($this->db);
        $pages->create($project, null, 'Release checklist', 'Everything before a release.', $me);
        $pages->create($project, null, 'Onboarding', 'Read the release checklist on your first day.', $me);
        $pages->create($project, null, 'Holidays', 'Nothing about it.', $me);

        $found = array_column((new PageRepository($this->db))->search($project, 'checklist'), 'title');

        self::assertSame(['Release checklist', 'Onboarding'], $found);
    }
}
