<?php

namespace CantoTrack\Core;

/**
 * A spreadsheet file, written by hand: one sheet, a bold header row, and
 * cells that are numbers, dates or text.
 *
 * An .xlsx is a zip of a few XML files, and the few that a single plain sheet
 * needs fit on a page. That is less than a spreadsheet library weighs, and it
 * is what "export to Excel" has to mean for whoever does the invoicing: a
 * file that opens with its dates as dates and its hours as numbers, which a
 * CSV does not promise in every country's Excel.
 */
class Xlsx
{
    /**
     * @param list<string> $headers
     * @param list<list<int|float|string|\DateTimeInterface|null>> $rows
     */
    public static function build(string $sheetName, array $headers, array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ct-xlsx-');
        $zip = new \ZipArchive();

        if ($file === false || $zip->open($file, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The spreadsheet could not be written.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::e(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');

        // Style 1: the header, bold. Style 2: a date. Style 3: hours to two places.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/><numFmt numFmtId="165" formatCode="0.00"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="4"><xf/><xf fontId="1" applyFont="1"/><xf numFmtId="164" applyNumberFormat="1"/><xf numFmtId="165" applyNumberFormat="1"/></cellXfs>'
            . '</styleSheet>');

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetData>';

        $xml .= self::row(1, array_map(static fn(string $h): array => ['text', $h, 1], $headers));

        foreach ($rows as $index => $row) {
            $cells = [];

            foreach ($row as $value) {
                $cells[] = match (true) {
                    $value instanceof \DateTimeInterface => ['number', self::serial($value), 2],
                    is_float($value) => ['number', $value, 3],
                    is_int($value) => ['number', $value, 0],
                    default => ['text', (string) $value, 0],
                };
            }

            $xml .= self::row($index + 2, $cells);
        }

        $xml .= '</sheetData>';
        $xml .= '<autoFilter ref="A1:' . self::column(max(1, count($headers))) . (count($rows) + 1) . '"/>';
        $xml .= '</worksheet>';

        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        $bytes = (string) file_get_contents($file);
        @unlink($file);

        return $bytes;
    }

    /** @param list<array{0: string, 1: int|float|string, 2: int}> $cells */
    private static function row(int $number, array $cells): string
    {
        $xml = '<row r="' . $number . '">';

        foreach ($cells as $i => [$kind, $value, $style]) {
            $reference = self::column($i + 1) . $number;
            $styled = $style > 0 ? ' s="' . $style . '"' : '';

            $xml .= $kind === 'number'
                ? '<c r="' . $reference . '"' . $styled . '><v>' . $value . '</v></c>'
                : '<c r="' . $reference . '"' . $styled . ' t="inlineStr"><is><t xml:space="preserve">' . self::e((string) $value) . '</t></is></c>';
        }

        return $xml . '</row>';
    }

    /** 1 → A, 27 → AA. */
    private static function column(int $index): string
    {
        $name = '';

        while ($index > 0) {
            $index--;
            $name = chr(65 + $index % 26) . $name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    /** A date as the day number spreadsheets count from 1899-12-30. */
    private static function serial(\DateTimeInterface $date): int
    {
        return (int) ((new \DateTimeImmutable($date->format('Y-m-d')))->diff(new \DateTimeImmutable('1899-12-30'))->days);
    }

    private static function e(string $text): string
    {
        // Control characters are not allowed in XML at all, and a note
        // pasted from somewhere can carry them.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
