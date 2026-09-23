<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Format;
use CantoTrack\Core\Xlsx;
use CantoTrack\Model\ClientRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\ReportRepository;
use CantoTrack\Model\UserRepository;

/**
 * The hours over any span of days, added up the way somebody needs them —
 * by project, person, client, ticket or day — and handed out as a file for
 * whoever does the invoicing.
 */
class ReportController extends Controller
{
    public function index(): void
    {
        Auth::require();

        $filters = $this->filters();
        $group = array_key_exists($_GET['group'] ?? '', ReportRepository::GROUPS) ? $_GET['group'] : 'project';
        $reports = new ReportRepository();
        $summary = $reports->summary($filters, $group);

        $this->render('reports/index.twig', [
            'filters' => $filters,
            'group' => $group,
            'summary' => $summary,
            'total' => array_sum(array_column($summary, 'minutes')),
            'billable' => array_sum(array_column($summary, 'billable')),
            'matrix' => $reports->matrix($filters),
            'projects' => (new ProjectRepository())->allWithCounts(true),
            'clients' => (new ClientRepository())->all(),
            'people' => (new UserRepository())->all(),
            'query' => http_build_query(array_filter([
                'from' => $filters['from'],
                'to' => $filters['to'],
                'project' => $filters['project_id'],
                'client' => $filters['client_id'],
                'person' => $filters['user_id'],
                'billable' => $filters['billable'],
            ])),
            'months' => $this->months(),
        ]);
    }

    /**
     * Every entry the report takes in, one row each: CSV for anything, or a
     * spreadsheet that opens with its dates as dates and its hours as numbers.
     */
    public function export(): void
    {
        Auth::require();

        $filters = $this->filters();
        $rows = (new ReportRepository())->rows($filters);
        $format = ($_GET['format'] ?? '') === 'xlsx' ? 'xlsx' : 'csv';
        $name = 'cantotrack-hours-' . $filters['from'] . '-' . $filters['to'];

        $headers = [
            __('Date'), __('Person'), __('Project'), __('Client'), __('Ticket'), __('Title'),
            __('Minutes'), __('Hours'), __('Billable'), __('Note'),
        ];

        if ($format === 'xlsx') {
            $data = array_values(array_map(static fn(array $r): array => [
                new \DateTimeImmutable((string) $r['work_date']),
                (string) $r['person'],
                $r['project_code'] . ' — ' . $r['project_name'],
                (string) ($r['client'] ?? ''),
                (string) $r['ticket_key'],
                (string) $r['ticket_title'],
                (int) $r['minutes'],
                round((int) $r['minutes'] / 60, 2),
                (int) $r['billable'] === 1 ? __('yes') : __('no'),
                (string) ($r['note'] ?? ''),
            ], $rows));

            $bytes = Xlsx::build(__('Hours'), $headers, $data);

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
            header('Content-Length: ' . strlen($bytes));
            header('Cache-Control: private, no-store');
            echo $bytes;
            exit;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '.csv"');
        header('Cache-Control: private, no-store');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            exit;
        }

        // The byte-order mark is what makes Excel read the file as UTF-8, and
        // an accented name as itself.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers, ',', '"', '');

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['work_date'],
                $r['person'],
                $r['project_code'] . ' — ' . $r['project_name'],
                $r['client'] ?? '',
                $r['ticket_key'],
                $r['ticket_title'],
                (int) $r['minutes'],
                Format::hours((int) $r['minutes']),
                (int) $r['billable'] === 1 ? 'yes' : 'no',
                self::neutral((string) ($r['note'] ?? '')),
            ], ',', '"', '');
        }

        fclose($out);
        exit;
    }

    /**
     * A note that starts like a formula is written with a quote in front, so
     * a spreadsheet shows it rather than running it: "=HYPERLINK(...)" typed
     * into a worklog is otherwise a link somebody clicks in the invoice sheet.
     */
    private static function neutral(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    private function filters(): array
    {
        $from = $this->date((string) ($_GET['from'] ?? '')) ?? date('Y-m-01');
        $to = $this->date((string) ($_GET['to'] ?? '')) ?? date('Y-m-t');

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from,
            'to' => $to,
            'project_id' => $this->idQuery('project'),
            'client_id' => $this->idQuery('client'),
            'user_id' => $this->idQuery('person'),
            'billable' => in_array($_GET['billable'] ?? '', ['yes', 'no'], true) ? $_GET['billable'] : null,
        ];
    }

    private function date(string $given): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $given);

        return $date !== false && $date->format('Y-m-d') === $given ? $given : null;
    }

    /** The last six months, for the quick links above the form. */
    private function months(): array
    {
        $months = [];

        for ($i = 0; $i < 6; $i++) {
            $first = new \DateTimeImmutable('first day of -' . $i . ' month');
            $months[] = ['from' => $first->format('Y-m-01'), 'to' => $first->format('Y-m-t'), 'label' => $first->format('Y-m')];
        }

        return $months;
    }
}
