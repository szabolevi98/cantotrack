<?php

namespace CantoTrack\Service;

use CantoTrack\Core\View;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * A statement as a PDF: the same document the page prints, laid out for A4
 * and drawn on the server, so that it can be attached to an email or kept
 * by the client as it is.
 *
 * DejaVu Sans, which comes with the library, is the font: it has every
 * accented letter of Hungarian, ő and ű included, which the PDF's own
 * built-in fonts do not. Nothing outside the document is ever fetched.
 */
final class StatementPdf
{
    /**
     * @param array<string, mixed> $statement
     * @param list<array<string, mixed>> $entries
     */
    public static function render(array $statement, array $entries, string $currency): string
    {
        $html = View::twig()->render('billing/pdf.twig', [
            'statement' => $statement,
            'entries' => $entries,
            'summary' => Statements::summary($entries, 'project'),
            'minutes' => array_sum(array_column($entries, 'minutes')),
            'amount' => round(array_sum(array_column($entries, 'amount')), 2),
            'currency' => $currency,
            'letterhead' => (new Statements())->letterhead(),
        ]);

        $cache = dirname(__DIR__, 2) . '/var/cache/dompdf';
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('fontCache', $cache);
        $options->set('tempDir', $cache);
        $options->set('chroot', dirname(__DIR__, 2) . '/vendor/dompdf/dompdf');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        // The page numbers, in the corner of every page, once the pages are known.
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        if ($font === null) {
            return (string) $pdf->output();
        }

        $canvas->page_text(
            $canvas->get_width() - 110,
            $canvas->get_height() - 30,
            __('Page {PAGE_NUM} of {PAGE_COUNT}'),
            $font,
            7,
            [0.45, 0.47, 0.52]
        );

        return (string) $pdf->output();
    }
}
