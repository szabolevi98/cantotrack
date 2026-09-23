<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\ValidationError;
use PDO;

/**
 * Handing a week in, and an administrator approving it or sending it back.
 *
 * A handed-in week's hours stop changing, so the one approving it approves
 * what they saw. Sent back, it opens again with the reason beside it.
 */
class WeekReview
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** @throws ValidationError */
    public function submit(int $userId, string $monday): void
    {
        $current = (new Calendar($this->db))->weekState($userId, $monday);

        if ($current !== null && $current['state'] !== 'rejected') {
            throw new ValidationError(__('That week has been handed in already.'));
        }

        if ($monday > date('Y-m-d')) {
            throw new ValidationError(__('A week that has not started cannot be handed in.'));
        }

        $this->db->prepare(
            'INSERT INTO timesheet_weeks (user_id, week_start, state, submitted_at, reviewed_by, reviewed_at, comment)
             VALUES (:user, :week, \'submitted\', NOW(), NULL, NULL, NULL)
             ON DUPLICATE KEY UPDATE state = \'submitted\', submitted_at = NOW(), reviewed_by = NULL, reviewed_at = NULL, comment = NULL'
        )->execute(['user' => $userId, 'week' => $monday]);
    }

    /** @throws ValidationError */
    public function review(int $userId, string $monday, int $reviewerId, bool $approve, string $comment): void
    {
        $current = (new Calendar($this->db))->weekState($userId, $monday);

        if ($current === null || $current['state'] !== 'submitted') {
            throw new ValidationError(__('Only a week that has been handed in can be approved or sent back.'));
        }

        if (!$approve && trim($comment) === '') {
            throw new ValidationError(__('Say what is wrong with it, so it can be put right.'));
        }

        $this->db->prepare(
            'UPDATE timesheet_weeks SET state = :state, reviewed_by = :reviewer, reviewed_at = NOW(), comment = :comment
             WHERE user_id = :user AND week_start = :week'
        )->execute([
            'state' => $approve ? 'approved' : 'rejected',
            'reviewer' => $reviewerId,
            'comment' => mb_substr(trim($comment), 0, 500) ?: null,
            'user' => $userId,
            'week' => $monday,
        ]);
    }

    /**
     * Where everybody's week stands: user id => its row in timesheet_weeks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forWeek(string $monday): array
    {
        $statement = $this->db->prepare('SELECT * FROM timesheet_weeks WHERE week_start = :week');
        $statement->execute(['week' => $monday]);
        $states = [];

        foreach ($statement->fetchAll() as $row) {
            $states[(int) $row['user_id']] = $row;
        }

        return $states;
    }

    /** The weeks waiting for somebody to look at them, with what is in them. */
    public function pending(): array
    {
        $statement = $this->db->prepare(
            'SELECT tw.*, u.name AS user_name,
                    (SELECT COALESCE(SUM(w.minutes), 0) FROM worklogs w
                      WHERE w.user_id = tw.user_id AND w.work_date BETWEEN tw.week_start AND tw.week_start + INTERVAL 6 DAY) AS minutes
             FROM timesheet_weeks tw JOIN users u ON u.id = tw.user_id
             WHERE tw.state = \'submitted\'
             ORDER BY tw.week_start, u.name'
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    /** Recent decisions, for the approvals page's history. */
    public function recent(int $limit = 20): array
    {
        $statement = $this->db->prepare(
            'SELECT tw.*, u.name AS user_name, r.name AS reviewer_name
             FROM timesheet_weeks tw JOIN users u ON u.id = tw.user_id LEFT JOIN users r ON r.id = tw.reviewed_by
             WHERE tw.state <> \'submitted\'
             ORDER BY tw.reviewed_at DESC LIMIT ' . max(1, $limit)
        );
        $statement->execute();

        return $statement->fetchAll();
    }
}
