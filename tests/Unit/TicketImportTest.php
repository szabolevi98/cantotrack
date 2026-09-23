<?php

namespace CantoTrack\Tests\Unit;

use CantoTrack\Service\TicketImport;
use PHPUnit\Framework\TestCase;

final class TicketImportTest extends TestCase
{
    private function file(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ct-import-');
        file_put_contents($path, $contents);

        return $path;
    }

    public function testAHungarianExcelFileIsRead(): void
    {
        // Semicolons, Windows-1250, and a quoted field with a semicolon in it.
        $csv = (string) iconv('UTF-8', 'Windows-1250', "Cím;Határidő;Leírás\nÁrvíztűrő tükörfúrógép;2026.10.01.;\"egy; kettő\"\n");
        $rows = TicketImport::read($this->file($csv));

        self::assertSame(['Cím', 'Határidő', 'Leírás'], $rows[0]);
        self::assertSame(['Árvíztűrő tükörfúrógép', '2026.10.01.', 'egy; kettő'], $rows[1]);
        self::assertSame([0 => 'title', 1 => 'due_on', 2 => 'description'], TicketImport::columns($rows[0]));
    }

    public function testAUtf8FileWithItsMarkIsRead(): void
    {
        $rows = TicketImport::read($this->file("\xEF\xBB\xBFSummary,Story Points,Something else\nFirst,3,x\n"));

        self::assertSame('Summary', $rows[0][0]);
        self::assertSame([0 => 'title', 1 => 'story_points'], TicketImport::columns($rows[0]));
    }

    public function testDatesAreReadInTheUsualWays(): void
    {
        self::assertSame('2026-10-01', TicketImport::date('2026-10-01'));
        self::assertSame('2026-10-01', TicketImport::date('2026.10.01.'));
        self::assertSame('2026-10-01', TicketImport::date('2026. 10. 1.'));
        self::assertSame('2026-10-01', TicketImport::date('01.10.2026'));
        self::assertNull(TicketImport::date('2026-02-31'));
        self::assertNull(TicketImport::date('next week'));
    }

    public function testAFileWithoutRowsIsRefused(): void
    {
        $this->expectException(\CantoTrack\Core\ValidationError::class);
        TicketImport::read($this->file("Title\n"));
    }
}
