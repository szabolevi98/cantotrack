<?php

namespace CantoTrack\Service;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Format;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use PDO;

/**
 * Hours planned ahead: who works on what, how many hours a day, from when to
 * when — and, beside it, what they logged.
 *
 * A plan is a stretch of days. Only the working days in it count: the
 * person's own week, less the holidays and the days away, so four hours a
 * day over a week with a public holiday is sixteen hours, not twenty.
 */
class Planning
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * The plans that touch a span of days, with the ticket or project they
     * are for — only those in projects the reader can see.
     *
     * @return list<array<string, mixed>>
     */
    public function plans(string $from, string $to, ?int $userId = null): array
    {
        $statement = $this->db->prepare(
            'SELECT pl.*, u.name AS user_name, p.code AS project_code, p.name AS project_name,
                    t.number AS ticket_number, t.title AS ticket_title, c.name AS creator_name
             FROM plans pl
             JOIN users u ON u.id = pl.user_id
             JOIN projects p ON p.id = pl.project_id
             LEFT JOIN tickets t ON t.id = pl.ticket_id
             LEFT JOIN users c ON c.id = pl.created_by
             WHERE pl.starts_on <= :to AND pl.ends_on >= :from' . Access::sql('pl.project_id')
            . ($userId !== null ? ' AND pl.user_id = ' . $userId : '') . '
             ORDER BY u.name, pl.starts_on, p.code, t.number'
        );
        $statement->execute(['from' => $from, 'to' => $to]);

        return array_values($statement->fetchAll());
    }

    /**
     * Planned minutes per person per day: user id => day => minutes, the
     * working days only.
     *
     * @param list<array<string, mixed>> $plans
     * @return array<int, array<string, int>>
     */
    public function perDay(array $plans, string $from, string $to): array
    {
        $calendar = new Calendar($this->db);
        $users = new UserRepository($this->db);
        $days = [];
        $out = [];

        foreach ($plans as $plan) {
            $userId = (int) $plan['user_id'];
            $days[$userId] ??= $calendar->days((array) $users->find($userId), $from, $to);

            foreach ($days[$userId] as $date => $day) {
                if ($date >= $plan['starts_on'] && $date <= $plan['ends_on'] && $day['expected'] > 0) {
                    $out[$userId][$date] = ($out[$userId][$date] ?? 0) + (int) $plan['minutes_per_day'];
                }
            }
        }

        return $out;
    }

    /**
     * A new plan. The ticket is named by its key; without one, a project.
     *
     * @throws ValidationError
     */
    public function add(int $userId, string $ticketKey, ?int $projectId, string $from, string $to, string $perDay, string $note, int $createdBy): int
    {
        if ((new UserRepository($this->db))->findActive($userId) === null) {
            throw new ValidationError(__('That person cannot be given work: there is no such active account.'));
        }

        $ticket = null;

        if (trim($ticketKey) !== '') {
            $ticket = (new TicketRepository($this->db))->findByKey($ticketKey);

            if ($ticket === null) {
                throw new ValidationError(__('There is no ticket {key}.', ['key' => $ticketKey]));
            }

            $projectId = (int) $ticket['project_id'];
        }

        if ($projectId === null || (new ProjectRepository($this->db))->find($projectId) === null) {
            throw new ValidationError(__('A plan is for a ticket, or at least a project.'));
        }

        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to ?: $from);

        if ($start === false || $end === false || $end < $start) {
            throw new ValidationError(__('A plan needs a first and a last day, in that order.'));
        }

        if ((int) $start->diff($end)->days > 366) {
            throw new ValidationError(__('A plan is at most a year long.'));
        }

        $minutes = Format::parseDuration($perDay);

        if ($minutes === null || $minutes <= 0 || $minutes > 24 * 60) {
            throw new ValidationError(__('How long a day should read like "4h" or "2h 30m".'));
        }

        $this->db->prepare(
            'INSERT INTO plans (user_id, project_id, ticket_id, starts_on, ends_on, minutes_per_day, note, created_by)
             VALUES (:user, :project, :ticket, :from, :to, :minutes, :note, :by)'
        )->execute([
            'user' => $userId,
            'project' => $projectId,
            'ticket' => $ticket === null ? null : (int) $ticket['id'],
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
            'minutes' => $minutes,
            'note' => mb_substr(trim($note), 0, 200) ?: null,
            'by' => $createdBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM plans WHERE id = :id' . Access::sql('project_id'));
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function remove(int $id): void
    {
        $this->db->prepare('DELETE FROM plans WHERE id = :id')->execute(['id' => $id]);
    }
}
