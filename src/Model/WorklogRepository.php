<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * The hours: one row per stretch of work.
 *
 * Every total this application shows is a sum over this table, worked out when
 * it is asked for. Nothing keeps a running total anywhere else — two places
 * holding the same number eventually hold two different ones, and the one that
 * is wrong is always the one somebody is looking at.
 */
class WorklogRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** What every list of hours needs beside the row itself. */
    private const SELECT = 'SELECT w.*,
                   t.title AS ticket_title,
                   t.number AS ticket_number,
                   p.id AS project_id,
                   p.code AS project_code,
                   p.name AS project_name,
                   u.name AS user_name
            FROM worklogs w
            JOIN tickets t ON t.id = w.ticket_id
            JOIN projects p ON p.id = t.project_id
            JOIN users u ON u.id = w.user_id';

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(self::SELECT . ' WHERE w.id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** One ticket's hours, newest day first — what the ticket page lists. */
    public function forTicket(int $ticketId): array
    {
        $statement = $this->db->prepare(
            self::SELECT . ' WHERE w.ticket_id = :ticket ORDER BY w.work_date DESC, w.id DESC'
        );

        $statement->execute(['ticket' => $ticketId]);

        return $statement->fetchAll();
    }

    /** One person's hours between two days, which is what a timesheet is. */
    public function forRange(int $userId, string $from, string $to): array
    {
        $statement = $this->db->prepare(
            self::SELECT . ' WHERE w.user_id = :user AND w.work_date BETWEEN :from AND :to
             ORDER BY w.work_date, w.id'
        );

        $statement->execute(['user' => $userId, 'from' => $from, 'to' => $to]);

        return $statement->fetchAll();
    }

    /**
     * How much each person logged on each day of a range.
     *
     * One query for the whole table rather than one per person per day: a team
     * of ten over a week is seventy questions, and asking them one at a time is
     * how a page that looks simple takes a second to draw.
     *
     * @return array<int, array<string, int>> user id => day => minutes
     */
    public function minutesByUserAndDay(string $from, string $to): array
    {
        $statement = $this->db->prepare(
            'SELECT user_id, work_date, SUM(minutes) AS minutes
             FROM worklogs
             WHERE work_date BETWEEN :from AND :to
             GROUP BY user_id, work_date'
        );

        $statement->execute(['from' => $from, 'to' => $to]);

        $totals = [];
        foreach ($statement->fetchAll() as $row) {
            $totals[(int) $row['user_id']][$row['work_date']] = (int) $row['minutes'];
        }

        return $totals;
    }

    /** One person's week, totalled per project — the "where did it go" answer. */
    public function minutesByProject(int $userId, string $from, string $to): array
    {
        $statement = $this->db->prepare(
            'SELECT p.id, p.code, p.name, SUM(w.minutes) AS minutes
             FROM worklogs w
             JOIN tickets t ON t.id = w.ticket_id
             JOIN projects p ON p.id = t.project_id
             WHERE w.user_id = :user AND w.work_date BETWEEN :from AND :to
             GROUP BY p.id
             ORDER BY minutes DESC'
        );

        $statement->execute(['user' => $userId, 'from' => $from, 'to' => $to]);

        return $statement->fetchAll();
    }

    public function totalMinutes(int $userId, string $from, string $to): int
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(minutes), 0) FROM worklogs
             WHERE user_id = :user AND work_date BETWEEN :from AND :to'
        );

        $statement->execute(['user' => $userId, 'from' => $from, 'to' => $to]);

        return (int) $statement->fetchColumn();
    }

    public function create(int $ticketId, int $userId, string $workDate, int $minutes, ?string $note, bool $billable = true): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO worklogs (ticket_id, user_id, work_date, minutes, billable, note)
             VALUES (:ticket, :user, :work_date, :minutes, :billable, :note)'
        );

        $statement->execute([
            'ticket' => $ticketId,
            'user' => $userId,
            'work_date' => $workDate,
            'minutes' => $minutes,
            'billable' => $billable ? 1 : 0,
            'note' => trim((string) $note) ?: null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function setBillable(int $id, bool $billable): void
    {
        $this->db->prepare('UPDATE worklogs SET billable = :billable WHERE id = :id')
            ->execute(['billable' => $billable ? 1 : 0, 'id' => $id]);
    }

    public function update(int $id, string $workDate, int $minutes, ?string $note): void
    {
        // The ticket and the person are not editable. A worklog that can move to
        // another ticket is one that can be moved off a project after the month
        // was reported, and one that can change owner is one somebody else's
        // hours can be written into.
        $statement = $this->db->prepare(
            'UPDATE worklogs SET work_date = :work_date, minutes = :minutes, note = :note WHERE id = :id'
        );

        $statement->execute([
            'work_date' => $workDate,
            'minutes' => $minutes,
            'note' => trim((string) $note) ?: null,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM worklogs WHERE id = :id');
        $statement->execute(['id' => $id]);
    }
}
