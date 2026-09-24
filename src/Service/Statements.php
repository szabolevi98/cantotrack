<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\SettingRepository;
use CantoTrack\Model\StatementRepository;
use PDO;

/**
 * Billing a client for a month: a draft made from their billable hours,
 * checked and corrected, then issued — numbered, its rates written down, its
 * hours closed — and handed over as a page to print, a spreadsheet, or to
 * the client's own people to read.
 *
 * Only administrators do any of it, the same people who see what an hour
 * is worth anywhere else.
 */
final class Statements
{
    /** Who the statements are from, and what is printed under every one. */
    public const FROM_SETTING = 'billing_from';
    public const FOOTER_SETTING = 'billing_footer';

    /** What a statement's summary can be split by. */
    public const GROUPS = ['project', 'person', 'type', 'ticket'];

    private StatementRepository $statements;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
        $this->statements = new StatementRepository($this->db);
    }

    /**
     * A draft of a client's billable hours in a span, addressed the way
     * their last statement was. Refused when none are left to bill.
     *
     * @throws ValidationError
     */
    public function draft(int $clientId, string $from, string $to, ?int $userId): int
    {
        if ($to < $from) {
            throw new ValidationError(__('The span ends before it starts.'));
        }

        $client = $this->db->prepare('SELECT name FROM clients WHERE id = :id');
        $client->execute(['id' => $clientId]);
        $name = $client->fetchColumn();

        if (!is_string($name)) {
            throw new ValidationError(__('There is no such client.'));
        }

        $this->db->beginTransaction();

        try {
            $id = $this->statements->create($clientId, $from, $to, $this->statements->lastBillTo($clientId) ?? $name, $userId);
            $claimed = $this->statements->claim($id);

            if ($claimed['added'] === 0) {
                throw new ValidationError(__('{client} has no billable hours in that span that are not on a statement already.', ['client' => $name]));
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $id;
    }

    /** @throws ValidationError */
    public function issue(array $statement, int $userId): string
    {
        $this->ensureDraft($statement);

        // What is on it is what it says now — not what it said when the
        // page was drawn.
        $this->statements->claim((int) $statement['id']);

        if ($this->statements->entries((int) $statement['id']) === []) {
            throw new ValidationError(__('There is nothing on this statement to issue.'));
        }

        $number = $this->statements->issue((int) $statement['id'], $userId, Money::currency());
        AuditLog::record('statement_issued', 'statement', (int) $statement['id'], $number . ' — ' . $statement['client']);

        return $number;
    }

    /** An issued statement back to a draft — to correct it, and issue it again under its number. */
    public function reopen(array $statement): void
    {
        if ($statement['state'] !== 'issued') {
            return;
        }

        $this->statements->reopen((int) $statement['id']);
        AuditLog::record('statement_reopened', 'statement', (int) $statement['id'], $statement['number'] . ' — ' . $statement['client']);
    }

    /** @throws ValidationError */
    public function delete(array $statement): void
    {
        $this->ensureDraft($statement);

        if ($statement['number'] !== null) {
            // It has been sent out under its number once; a number that
            // disappears is a gap somebody will ask about.
            throw new ValidationError(__('This statement was issued as {number} once. Issue it again rather than deleting it.', ['number' => $statement['number']]));
        }

        $this->statements->delete((int) $statement['id']);
    }

    /** @throws ValidationError */
    public function reword(array $statement, string $billTo, string $note): void
    {
        $this->ensureDraft($statement);

        $clean = static fn(string $text): ?string => ($text = trim(str_replace("\r\n", "\n", $text))) === '' ? null : mb_substr($text, 0, 2000);
        $this->statements->setWording((int) $statement['id'], $clean($billTo), $clean($note));
    }

    /** @throws ValidationError */
    public function takeOff(array $statement, int $worklogId): void
    {
        $this->ensureDraft($statement);

        if (!$this->statements->takeOff((int) $statement['id'], $worklogId)) {
            throw new ValidationError(__('That entry is not on this statement.'));
        }
    }

    /** @throws ValidationError */
    public function refresh(array $statement): array
    {
        $this->ensureDraft($statement);

        return $this->statements->claim((int) $statement['id']);
    }

    /**
     * The statement's hours added up by one of GROUPS, the biggest first.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array{label: string, minutes: int, amount: float, rate: ?float}>
     */
    public static function summary(array $entries, string $group): array
    {
        $rows = [];

        foreach ($entries as $entry) {
            $label = match ($group) {
                'person' => (string) $entry['person'],
                'type' => (string) ($entry['work_type'] ?? ''),
                'ticket' => $entry['ticket_key'] . ' ' . $entry['ticket_title'],
                default => $entry['project_code'] . ' — ' . $entry['project_name'],
            };

            $rows[$label] ??= ['label' => $label, 'minutes' => 0, 'amount' => 0.0, 'rates' => []];
            $rows[$label]['minutes'] += (int) $entry['minutes'];
            $rows[$label]['amount'] += (float) $entry['amount'];
            $rows[$label]['rates'][(string) $entry['rate']] = true;
        }

        usort($rows, static fn(array $a, array $b): int => [$b['minutes'], $a['label']] <=> [$a['minutes'], $b['label']]);

        // A rate is worth a column only where one rate is all there is.
        return array_map(static fn(array $r): array => [
            'label' => $r['label'],
            'minutes' => $r['minutes'],
            'amount' => round($r['amount'], 2),
            'rate' => count($r['rates']) === 1 ? (float) array_key_first($r['rates']) : null,
        ], $rows);
    }

    /** @return array{from: ?string, footer: ?string} */
    public function letterhead(): array
    {
        $settings = new SettingRepository($this->db);

        return ['from' => $settings->get(self::FROM_SETTING), 'footer' => $settings->get(self::FOOTER_SETTING)];
    }

    public function setLetterhead(string $from, string $footer): void
    {
        $settings = new SettingRepository($this->db);
        $settings->set(self::FROM_SETTING, trim($from) === '' ? null : mb_substr(trim($from), 0, 1000));
        $settings->set(self::FOOTER_SETTING, trim($footer) === '' ? null : mb_substr(trim($footer), 0, 1000));
    }

    /** @throws ValidationError */
    private function ensureDraft(array $statement): void
    {
        if ($statement['state'] !== 'draft') {
            throw new ValidationError(__('Statement {number} has been issued. Open it again to change it.', ['number' => $statement['number']]));
        }
    }
}
