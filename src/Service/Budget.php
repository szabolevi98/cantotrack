<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * How much of a project's budget is used — see the 0039 migration: its
 * hours (every hour logged in it) against the hours it has, and the worth
 * of its billable hours against the money it has.
 */
final class Budget
{
    /** What an hour logged is worth, the project's rate first: SQL over worklogs w, projects p, users u. */
    public const WORTH = 'CASE WHEN w.billable = 1 THEN w.minutes * COALESCE(p.hourly_rate, u.hourly_rate, 0) / 60 ELSE 0 END';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * The project's budget as used so far, or null when it has none.
     *
     * @param array<string, mixed> $project
     * @return array{hours: ?float, hours_used: float, hours_share: ?int, amount: ?float, amount_used: float, amount_share: ?int, alert: int, warn: bool, over: bool}|null
     */
    public function of(array $project): ?array
    {
        $hours = $project['budget_hours'] === null ? null : (float) $project['budget_hours'];
        $amount = $project['budget_amount'] === null ? null : (float) $project['budget_amount'];

        if ($hours === null && $amount === null) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(w.minutes), 0) AS minutes, COALESCE(SUM(' . self::WORTH . '), 0) AS worth
             FROM worklogs w JOIN tickets t ON t.id = w.ticket_id JOIN projects p ON p.id = t.project_id JOIN users u ON u.id = w.user_id
             WHERE p.id = :project'
        );
        $statement->execute(['project' => $project['id']]);
        $used = (array) $statement->fetch();

        return self::shares($hours, (int) $used['minutes'] / 60, $amount, (float) $used['worth'], (int) ($project['budget_alert'] ?? 80));
    }

    /**
     * The shares of a budget used, and whether they call for a word.
     *
     * @return array{hours: ?float, hours_used: float, hours_share: ?int, amount: ?float, amount_used: float, amount_share: ?int, alert: int, warn: bool, over: bool}
     */
    public static function shares(?float $hours, float $hoursUsed, ?float $amount, float $amountUsed, int $alert): array
    {
        $share = static fn(?float $of, float $used): ?int => $of === null || $of <= 0 ? null : (int) round($used / $of * 100);
        $hoursShare = $share($hours, $hoursUsed);
        $amountShare = $share($amount, $amountUsed);
        $most = max($hoursShare ?? 0, $amountShare ?? 0);

        return [
            'hours' => $hours,
            'hours_used' => round($hoursUsed, 2),
            'hours_share' => $hoursShare,
            'amount' => $amount,
            'amount_used' => round($amountUsed, 2),
            'amount_share' => $amountShare,
            'alert' => $alert,
            'warn' => $most >= $alert,
            'over' => $most > 100,
        ];
    }

    /**
     * The projects past their alert — for the administrators' dashboard.
     *
     * @param array<int, array<string, mixed>> $projects
     * @return list<array{project: array<string, mixed>, budget: array<string, mixed>}>
     */
    public function running(array $projects): array
    {
        $out = [];

        foreach ($projects as $project) {
            if (!array_key_exists('budget_hours', $project)) {
                continue;
            }
            $budget = $this->of($project);
            if ($budget !== null && $budget['warn']) {
                $out[] = ['project' => $project, 'budget' => $budget];
            }
        }

        return $out;
    }
}
