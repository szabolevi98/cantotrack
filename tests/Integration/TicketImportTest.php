<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\TicketImport;

final class TicketImportTest extends DatabaseTestCase
{
    public function testGoodRowsBecomeTicketsAndTheRestSayWhy(): void
    {
        $me = $this->person('Anna Kovács');
        $project = $this->project();

        $rows = [
            ['Title', 'Type', 'Priority', 'Assignee', 'Labels', 'Estimate', 'Status'],
            ['Import me', 'Hiba', 'magas', 'Anna Kovács', 'csv; import', '2h', 'In progress'],
            ['', 'task', '', '', '', '', ''],
            ['Somebody unknown', 'task', '', 'nobody@example.test', '', '', ''],
            ['Bad estimate', 'task', '', '', '', 'soon', ''],
        ];

        $import = new TicketImport($this->db);
        $prepared = $import->prepare($project, $rows);

        self::assertCount(4, $prepared);
        self::assertSame([], $prepared[0]['problems']);
        self::assertNotSame([], $prepared[1]['problems'], 'A row without a title was taken.');
        self::assertNotSame([], $prepared[2]['problems']);
        self::assertNotSame([], $prepared[3]['problems']);
        self::assertSame(5, $prepared[3]['line']);

        $result = $import->import($prepared, $me);
        self::assertSame(['CT-1'], $result['created']);

        $ticket = (new TicketRepository($this->db))->findByKey('CT-1');
        self::assertNotNull($ticket);
        self::assertSame('bug', $ticket['type']);
        self::assertSame('high', $ticket['priority']);
        self::assertSame($me, (int) $ticket['assignee_id']);
        self::assertSame(120, (int) $ticket['estimate_minutes']);
        self::assertSame('in_progress', $ticket['status_category']);
        self::assertSame("csv\nimport", $ticket['label_names']);
    }
}
