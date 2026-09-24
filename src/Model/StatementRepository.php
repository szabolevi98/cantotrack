<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * Statements: what a client is billed for — see the 0043 migration.
 *
 * A draft's figures are added up from its hours whenever they are asked
 * for, at the rates that count today; an issued one's are the ones written
 * down when it was issued, at the rates that counted then.
 */
class StatementRepository
{
    /** An hour's worth: its own rate once billed, today's until then. */
    public const RATE = 'COALESCE(w.billed_rate, p.hourly_rate, u.hourly_rate, 0)';

    private const FROM = 'FROM worklogs w
             JOIN tickets t ON t.id = w.ticket_id
             JOIN projects p ON p.id = t.project_id
             JOIN users u ON u.id = w.user_id
             LEFT JOIN work_types wt ON wt.id = w.work_type_id';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * The clients with billable hours in a span that are on no statement
     * yet: how many, what they are worth, and how much of it is in weeks
     * nobody has approved.
     *
     * @return list<array{client_id: int, client: string, minutes: int, amount: float, unapproved: int, entries: int}>
     */
    public function unbilled(string $from, string $to): array
    {
        $statement = $this->db->prepare(
            'SELECT c.id AS client_id, c.name AS client, SUM(w.minutes) AS minutes,
                    SUM(w.minutes * ' . self::RATE . ' / 60) AS amount,
                    SUM(CASE WHEN tw.state = \'approved\' THEN 0 ELSE w.minutes END) AS unapproved,
                    COUNT(*) AS entries
             ' . self::FROM . '
             JOIN clients c ON c.id = p.client_id
             LEFT JOIN timesheet_weeks tw ON tw.user_id = w.user_id
                  AND tw.week_start = DATE_SUB(w.work_date, INTERVAL WEEKDAY(w.work_date) DAY)
             WHERE w.work_date BETWEEN :from AND :to AND w.billable = 1 AND w.statement_id IS NULL
             GROUP BY c.id, c.name
             ORDER BY c.name'
        );
        $statement->execute(['from' => $from, 'to' => $to]);

        return array_values(array_map(static fn(array $r): array => [
            'client_id' => (int) $r['client_id'],
            'client' => (string) $r['client'],
            'minutes' => (int) $r['minutes'],
            'amount' => round((float) $r['amount'], 2),
            'unapproved' => (int) $r['unapproved'],
            'entries' => (int) $r['entries'],
        ], $statement->fetchAll()));
    }

    /**
     * The statements, the latest first, with what each adds up to — its
     * written-down totals once issued, its hours' until then.
     *
     * @param list<int>|null $clientIds only these clients' (null: every one)
     * @return list<array<string, mixed>>
     */
    public function all(?array $clientIds = null, bool $issuedOnly = false, int $limit = 100): array
    {
        $where = ['1 = 1'];

        if ($clientIds !== null) {
            $where[] = $clientIds === [] ? 'FALSE' : 's.client_id IN (' . implode(',', array_map('intval', $clientIds)) . ')';
        }
        if ($issuedOnly) {
            $where[] = "s.state = 'issued'";
        }

        $statement = $this->db->query(
            'SELECT s.*, c.name AS client,
                    COALESCE(s.minutes, (SELECT SUM(w.minutes) FROM worklogs w WHERE w.statement_id = s.id)) AS total_minutes,
                    COALESCE(s.amount, (SELECT SUM(w.minutes * ' . self::RATE . ' / 60) ' . self::FROM . ' WHERE w.statement_id = s.id)) AS total_amount
             FROM statements s JOIN clients c ON c.id = s.client_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY s.period_from DESC, c.name, s.id DESC
             LIMIT ' . max(1, $limit)
        );

        return $statement === false ? [] : array_values($statement->fetchAll());
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT s.*, c.name AS client, cu.name AS created_by_name, iu.name AS issued_by_name
             FROM statements s JOIN clients c ON c.id = s.client_id
             LEFT JOIN users cu ON cu.id = s.created_by LEFT JOIN users iu ON iu.id = s.issued_by
             WHERE s.id = :id'
        );
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function create(int $clientId, string $from, string $to, ?string $billTo, ?int $userId): int
    {
        $this->db->prepare(
            'INSERT INTO statements (client_id, period_from, period_to, bill_to, created_by) VALUES (:client, :from, :to, :bill_to, :user)'
        )->execute(['client' => $clientId, 'from' => $from, 'to' => $to, 'bill_to' => $billTo, 'user' => $userId]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Brings a draft up to date with its hours: the client's billable ones in
     * its span that are on no statement come onto it, and the ones that no
     * longer belong — made not billable, moved to another day or another
     * client's project — go off it. Returns how many came and went.
     *
     * @return array{added: int, removed: int}
     */
    public function claim(int $id): array
    {
        $removed = $this->db->prepare(
            'UPDATE worklogs w JOIN tickets t ON t.id = w.ticket_id JOIN projects p ON p.id = t.project_id
             JOIN statements s ON s.id = w.statement_id
             SET w.statement_id = NULL
             WHERE s.id = :id AND s.state = \'draft\'
               AND (w.billable = 0 OR w.work_date NOT BETWEEN s.period_from AND s.period_to OR p.client_id IS NULL OR p.client_id <> s.client_id)'
        );
        $removed->execute(['id' => $id]);

        $added = $this->db->prepare(
            'UPDATE worklogs w JOIN tickets t ON t.id = w.ticket_id JOIN projects p ON p.id = t.project_id
             JOIN statements s ON s.id = :id AND s.state = \'draft\'
             SET w.statement_id = s.id
             WHERE w.statement_id IS NULL AND w.billable = 1 AND p.client_id = s.client_id
               AND w.work_date BETWEEN s.period_from AND s.period_to'
        );
        $added->execute(['id' => $id]);

        return ['added' => $added->rowCount(), 'removed' => $removed->rowCount()];
    }

    /**
     * Every hour on a statement, in the order it is printed.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(int $id): array
    {
        $statement = $this->db->prepare(
            'SELECT w.id, w.work_date, w.minutes, w.note, w.user_id, w.billable, u.name AS person,
                    p.id AS project_id, p.code AS project_code, p.name AS project_name,
                    t.id AS ticket_id, CONCAT(p.code, \'-\', t.number) AS ticket_key, t.title AS ticket_title,
                    wt.name AS work_type, ' . self::RATE . ' AS rate, w.minutes * ' . self::RATE . ' / 60 AS amount,
                    (SELECT tw.state FROM timesheet_weeks tw WHERE tw.user_id = w.user_id
                      AND tw.week_start = DATE_SUB(w.work_date, INTERVAL WEEKDAY(w.work_date) DAY)) AS week_state
             ' . self::FROM . '
             WHERE w.statement_id = :id
             ORDER BY p.code, w.work_date, u.name, w.id'
        );
        $statement->execute(['id' => $id]);

        return array_values(array_map(static fn(array $r): array => ['rate' => round((float) $r['rate'], 2), 'amount' => round((float) $r['amount'], 2)] + $r, $statement->fetchAll()));
    }

    /**
     * Gives a draft its number — the next one in the year it is issued, or
     * the one it had before it was opened again — and writes down each
     * hour's rate and the totals. Returns the number.
     */
    public function issue(int $id, int $userId, string $currency): string
    {
        $this->db->beginTransaction();

        try {
            $row = $this->db->prepare('SELECT number FROM statements WHERE id = :id FOR UPDATE');
            $row->execute(['id' => $id]);
            $number = $row->fetchColumn();

            if (!is_string($number) || $number === '') {
                $year = date('Y');
                $last = $this->db->prepare('SELECT MAX(number) FROM statements WHERE number LIKE :year FOR UPDATE');
                $last->execute(['year' => $year . '-%']);
                $count = (int) substr((string) $last->fetchColumn(), 5);
                $number = sprintf('%s-%03d', $year, $count + 1);
            }

            $this->db->prepare(
                'UPDATE worklogs w JOIN tickets t ON t.id = w.ticket_id JOIN projects p ON p.id = t.project_id JOIN users u ON u.id = w.user_id
                 SET w.billed_rate = COALESCE(p.hourly_rate, u.hourly_rate, 0)
                 WHERE w.statement_id = :id'
            )->execute(['id' => $id]);

            $totals = $this->db->prepare(
                'SELECT COALESCE(SUM(minutes), 0) AS minutes, COALESCE(SUM(minutes * billed_rate / 60), 0) AS amount FROM worklogs WHERE statement_id = :id'
            );
            $totals->execute(['id' => $id]);
            $sum = (array) $totals->fetch();

            $this->db->prepare(
                "UPDATE statements SET state = 'issued', number = :number, currency = :currency, minutes = :minutes, amount = :amount,
                        issued_by = :user, issued_at = NOW() WHERE id = :id"
            )->execute([
                'number' => $number,
                'currency' => $currency,
                'minutes' => (int) $sum['minutes'],
                'amount' => round((float) $sum['amount'], 2),
                'user' => $userId,
                'id' => $id,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $number;
    }

    /** An issued statement back to a draft, keeping its number for when it is issued again. */
    public function reopen(int $id): void
    {
        $this->db->prepare('UPDATE worklogs SET billed_rate = NULL WHERE statement_id = :id')->execute(['id' => $id]);
        $this->db->prepare(
            "UPDATE statements SET state = 'draft', currency = NULL, minutes = NULL, amount = NULL, issued_by = NULL, issued_at = NULL WHERE id = :id"
        )->execute(['id' => $id]);
    }

    /** A draft thrown away: its hours are free to go on another. */
    public function delete(int $id): void
    {
        $this->db->prepare('UPDATE worklogs SET statement_id = NULL, billed_rate = NULL WHERE statement_id = :id')->execute(['id' => $id]);
        $this->db->prepare('DELETE FROM statements WHERE id = :id')->execute(['id' => $id]);
    }

    public function setWording(int $id, ?string $billTo, ?string $note): void
    {
        $this->db->prepare('UPDATE statements SET bill_to = :bill_to, note = :note WHERE id = :id')
            ->execute(['bill_to' => $billTo, 'note' => $note, 'id' => $id]);
    }

    /** One hour taken off a draft, and not billed: it was not billable after all. */
    public function takeOff(int $id, int $worklogId): bool
    {
        $statement = $this->db->prepare(
            "UPDATE worklogs w JOIN statements s ON s.id = w.statement_id AND s.state = 'draft'
             SET w.statement_id = NULL, w.billable = 0 WHERE w.id = :worklog AND s.id = :id"
        );
        $statement->execute(['worklog' => $worklogId, 'id' => $id]);

        return $statement->rowCount() > 0;
    }

    /** Who a client's last statement was addressed to, for the next one. */
    public function lastBillTo(int $clientId): ?string
    {
        $statement = $this->db->prepare('SELECT bill_to FROM statements WHERE client_id = :client AND bill_to IS NOT NULL ORDER BY id DESC LIMIT 1');
        $statement->execute(['client' => $clientId]);
        $billTo = $statement->fetchColumn();

        return is_string($billTo) ? $billTo : null;
    }

    /** The issued statement an hour is on, if it is on one. */
    public function issuedFor(int $worklogId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT s.* FROM worklogs w JOIN statements s ON s.id = w.statement_id WHERE w.id = :id AND s.state = 'issued'"
        );
        $statement->execute(['id' => $worklogId]);

        return $statement->fetch() ?: null;
    }

    /**
     * The clients a person sees every project of that has hours on a
     * statement — whose issued statements a guest from that client may read.
     *
     * @param list<int> $projectIds the projects the person may see
     * @return list<int>
     */
    public function clientsSeenBy(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $statement = $this->db->query(
            'SELECT c.id FROM clients c
             WHERE EXISTS (SELECT 1 FROM projects p WHERE p.client_id = c.id AND p.id IN (' . implode(',', array_map('intval', $projectIds)) . '))'
        );

        return $statement === false ? [] : array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** Whether every hour on a statement is in one of these projects. */
    public function onlyIn(int $id, array $projectIds): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM worklogs w JOIN tickets t ON t.id = w.ticket_id
             WHERE w.statement_id = :id' . ($projectIds === [] ? '' : ' AND t.project_id NOT IN (' . implode(',', array_map('intval', $projectIds)) . ')')
        );
        $statement->execute(['id' => $id]);

        return (int) $statement->fetchColumn() === 0;
    }
}
