<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Access;
use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Core\Xlsx;
use CantoTrack\Model\StatementRepository;
use CantoTrack\Service\Money;
use CantoTrack\Service\Statements;

/**
 * Billing: which clients have hours to be billed for a month, their
 * statements from draft to issued, and the statements themselves — for the
 * administrators, and, once issued, for the client's own guests to read.
 */
class BillingController extends Controller
{
    public function index(): void
    {
        Auth::require();

        $repository = new StatementRepository();

        // Somebody from a client: the statements they were sent, and nothing else.
        if (!Auth::isAdmin()) {
            $this->render('billing/list.twig', ['statements' => $this->readable($repository)]);

            return;
        }

        [$month, $from, $to] = $this->month();

        $this->render('billing/index.twig', [
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'unbilled' => $repository->unbilled($from, $to),
            'statements' => $repository->all(),
            'letterhead' => (new Statements())->letterhead(),
            'months' => $this->months(),
        ]);
    }

    public function create(): void
    {
        Auth::requireAdmin();

        try {
            $id = (new Statements())->draft(
                (int) $this->idInput('client_id'),
                $this->dayInput('from'),
                $this->dayInput('to'),
                Auth::id()
            );
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->back('/billing');
        }

        $this->flash(__('A draft is made. Check it, then issue it.'));
        $this->redirect('/billing/statements/' . $id);
    }

    public function show(int $id): void
    {
        Auth::require();

        $statement = $this->statementOr404($id);
        $repository = new StatementRepository();
        $entries = $repository->entries($id);
        $group = in_array($_GET['by'] ?? '', Statements::GROUPS, true) ? (string) $_GET['by'] : 'project';
        $service = new Statements();

        $this->render('billing/show.twig', [
            'statement' => $statement,
            'entries' => $entries,
            'group' => $group,
            'summary' => Statements::summary($entries, $group),
            'minutes' => array_sum(array_column($entries, 'minutes')),
            'amount' => round(array_sum(array_column($entries, 'amount')), 2),
            'currency' => $statement['currency'] ?? Money::currency(),
            'unapproved' => array_sum(array_map(static fn(array $e): int => $e['week_state'] === 'approved' ? 0 : (int) $e['minutes'], $entries)),
            'letterhead' => $service->letterhead(),
            'manage' => Auth::isAdmin(),
        ]);
    }

    /** The draft's, brought up to date with its hours. */
    public function refresh(int $id): void
    {
        $statement = $this->managed($id);

        try {
            $changed = (new Statements())->refresh($statement);
            $this->flash(__('Up to date: {added} added, {removed} taken off.', $changed));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/billing/statements/' . $id);
    }

    public function issue(int $id): void
    {
        $statement = $this->managed($id);

        try {
            $number = (new Statements())->issue($statement, (int) Auth::id());
            $this->flash(__('Issued as {number}. Its hours no longer change.', ['number' => $number]));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/billing/statements/' . $id);
    }

    public function reopen(int $id): void
    {
        $statement = $this->managed($id);
        (new Statements())->reopen($statement);

        $this->flash(__('Open again as a draft. It keeps its number when it is issued again.'), 'warning');
        $this->redirect('/billing/statements/' . $id);
    }

    public function delete(int $id): void
    {
        $statement = $this->managed($id);

        try {
            (new Statements())->delete($statement);
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->redirect('/billing/statements/' . $id);
        }

        $this->flash(__('The draft is deleted; its hours can go on another.'), 'warning');
        $this->redirect('/billing?month=' . substr((string) $statement['period_from'], 0, 7));
    }

    public function reword(int $id): void
    {
        $statement = $this->managed($id);

        try {
            (new Statements())->reword($statement, (string) ($_POST['bill_to'] ?? ''), (string) ($_POST['note'] ?? ''));
            $this->flash(__('Saved.'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/billing/statements/' . $id);
    }

    /** One entry off the draft: it is not billed, now or later. */
    public function takeOff(int $id, int $worklogId): void
    {
        $statement = $this->managed($id);

        try {
            (new Statements())->takeOff($statement, $worklogId);
            $this->flash(__('The entry is off the statement, and no longer billable.'), 'warning');
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/billing/statements/' . $id . '#entries');
    }

    public function letterhead(): void
    {
        Auth::requireAdmin();

        (new Statements())->setLetterhead((string) ($_POST['from'] ?? ''), (string) ($_POST['footer'] ?? ''));

        $this->flash(__('Saved.'));
        $this->back('/billing');
    }

    /** Every entry of a statement as a spreadsheet, with its totals at the foot. */
    public function export(int $id): void
    {
        Auth::require();

        $statement = $this->statementOr404($id);
        $entries = (new StatementRepository())->entries($id);
        $name = 'statement-' . ($statement['number'] ?? 'draft-' . $id) . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $statement['client']);

        $rows = array_map(static fn(array $e): array => [
            new \DateTimeImmutable((string) $e['work_date']),
            (string) $e['person'],
            $e['project_code'] . ' — ' . $e['project_name'],
            (string) $e['ticket_key'],
            ReportController::neutral((string) $e['ticket_title']),
            (string) ($e['work_type'] ?? ''),
            ReportController::neutral((string) ($e['note'] ?? '')),
            round((int) $e['minutes'] / 60, 2),
            (float) $e['rate'],
            (float) $e['amount'],
        ], $entries);

        $rows[] = [null, null, null, null, null, null, __('Total'),
            round(array_sum(array_column($entries, 'minutes')) / 60, 2), null, round(array_sum(array_column($entries, 'amount')), 2)];

        $bytes = Xlsx::build($statement['number'] ?? __('Draft'), [
            __('Date'), __('Person'), __('Project'), __('Ticket'), __('Title'), __('Work type'), __('Note'),
            __('Hours'), __('Rate') . ' (' . ($statement['currency'] ?? Money::currency()) . ')', __('Amount'),
        ], $rows);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private, no-store');
        echo $bytes;
        exit;
    }

    /** The statement as a PDF, to attach to an email or keep. */
    public function pdf(int $id): void
    {
        Auth::require();

        $statement = $this->statementOr404($id);
        $bytes = \CantoTrack\Service\StatementPdf::render($statement, (new StatementRepository())->entries($id), $statement['currency'] ?? Money::currency());
        $name = 'statement-' . ($statement['number'] ?? 'draft-' . $id) . '-' . trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $statement['client']), '-');

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $name . '.pdf"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }

    /**
     * A statement the person may read: any, for an administrator; an issued
     * one of their own client whose every hour is in a project they see, for
     * a guest. Nobody else is told it exists.
     */
    private function statementOr404(int $id): array
    {
        $repository = new StatementRepository();
        $statement = $repository->find($id);

        if ($statement !== null && !Auth::isAdmin()) {
            $projects = Access::projectIds() ?? [];
            $readable = (Auth::user()['role'] ?? '') === 'guest'
                && $statement['state'] === 'issued'
                && in_array((int) $statement['client_id'], $repository->clientsSeenBy($projects), true)
                && $repository->onlyIn($id, $projects);

            $statement = $readable ? $statement : null;
        }

        if ($statement === null) {
            $this->notFound(__('There is no such statement.'));
        }

        return $statement;
    }

    private function managed(int $id): array
    {
        Auth::requireAdmin();

        return $this->statementOr404($id);
    }

    /** @return list<array<string, mixed>> the issued statements a guest may read */
    private function readable(StatementRepository $repository): array
    {
        if ((Auth::user()['role'] ?? '') !== 'guest') {
            return [];
        }

        $projects = Access::projectIds() ?? [];

        return array_values(array_filter(
            $repository->all($repository->clientsSeenBy($projects), true),
            static fn(array $s): bool => $repository->onlyIn((int) $s['id'], $projects)
        ));
    }

    /**
     * The month asked for — last month unless said otherwise, which is the
     * one being billed at the start of this one.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function month(): array
    {
        $given = (string) ($_GET['month'] ?? '');
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $given) === 1 ? $given : (new \DateTimeImmutable('first day of last month'))->format('Y-m');
        $first = new \DateTimeImmutable($month . '-01');

        return [$month, $first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
    }

    /** @return list<string> the last twelve months, the latest first */
    private function months(): array
    {
        $months = [];
        $at = new \DateTimeImmutable('first day of this month');

        for ($i = 0; $i < 12; $i++) {
            $months[] = $at->modify('-' . $i . ' months')->format('Y-m');
        }

        return $months;
    }

    /** A day from the form, or back to where it came from with why not. */
    private function dayInput(string $name): string
    {
        $given = trim((string) ($_POST[$name] ?? ''));
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $given);

        if ($day === false || $day->format('Y-m-d') !== $given) {
            $this->flash(__('“{value}” is not a date.', ['value' => $given]), 'danger');
            $this->back('/billing');
        }

        return $given;
    }
}
