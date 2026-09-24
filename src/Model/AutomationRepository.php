<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * The automation rules, and the log of what they did.
 */
class AutomationRepository
{
    private const SELECT = 'SELECT r.*, p.code AS project_code, p.name AS project_name, u.name AS creator_name
            FROM automation_rules r
            LEFT JOIN projects p ON p.id = r.project_id
            LEFT JOIN users u ON u.id = r.created_by';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $statement = $this->db->query(self::SELECT . ' ORDER BY r.is_active DESC, p.code IS NOT NULL, p.code, r.name');

        return $statement === false ? [] : array_values(array_map([self::class, 'decoded'], $statement->fetchAll()));
    }

    /** @return list<array<string, mixed>> the active rules for a trigger in a project, every project's among them */
    public function forTrigger(string $trigger, ?int $projectId): array
    {
        $statement = $this->db->prepare(
            self::SELECT . ' WHERE r.is_active = 1 AND r.`trigger` = :trigger AND (r.project_id IS NULL OR r.project_id = :project) ORDER BY r.id'
        );
        $statement->execute(['trigger' => $trigger, 'project' => $projectId]);

        return array_values(array_map([self::class, 'decoded'], $statement->fetchAll()));
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(self::SELECT . ' WHERE r.id = :id');
        $statement->execute(['id' => $id]);
        $rule = $statement->fetch();

        return $rule === false ? null : self::decoded($rule);
    }

    /** @param list<array{type: string, value: string}> $actions */
    public function save(?int $id, ?int $projectId, string $name, string $trigger, string $condition, array $actions, ?int $createdBy): int
    {
        $values = [
            'project' => $projectId, 'name' => $name, 'trigger' => $trigger, 'condition' => $condition,
            'actions' => (string) json_encode($actions, JSON_UNESCAPED_UNICODE),
        ];

        if ($id !== null) {
            $this->db->prepare(
                'UPDATE automation_rules SET project_id = :project, name = :name, `trigger` = :trigger, `condition` = :condition, actions = :actions WHERE id = :id'
            )->execute($values + ['id' => $id]);

            return $id;
        }

        $this->db->prepare(
            'INSERT INTO automation_rules (project_id, name, `trigger`, `condition`, actions, created_by)
             VALUES (:project, :name, :trigger, :condition, :actions, :creator)'
        )->execute($values + ['creator' => $createdBy]);

        return (int) $this->db->lastInsertId();
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->prepare('UPDATE automation_rules SET is_active = :active WHERE id = :id')->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM automation_rules WHERE id = :id')->execute(['id' => $id]);
    }

    public function log(int $ruleId, ?int $ticketId, string $outcome, ?string $message): void
    {
        $this->db->prepare('INSERT INTO automation_log (rule_id, ticket_id, outcome, message) VALUES (:rule, :ticket, :outcome, :message)')
            ->execute(['rule' => $ruleId, 'ticket' => $ticketId, 'outcome' => $outcome, 'message' => $message === null ? null : mb_substr($message, 0, 500)]);

        if ($outcome === 'done') {
            $this->db->prepare('UPDATE automation_rules SET run_count = run_count + 1, last_run_at = NOW() WHERE id = :id')->execute(['id' => $ruleId]);
        }
    }

    /** Whether a rule did something to a ticket today already — a daily rule does it once. */
    public function ranToday(int $ruleId, int $ticketId): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM automation_log WHERE rule_id = :rule AND ticket_id = :ticket AND created_at >= CURDATE() LIMIT 1');
        $statement->execute(['rule' => $ruleId, 'ticket' => $ticketId]);

        return $statement->fetchColumn() !== false;
    }

    /** @return list<array<string, mixed>> what a rule did lately, the latest first */
    public function recentLog(int $ruleId, int $limit = 25): array
    {
        $statement = $this->db->prepare(
            'SELECT l.*, t.number AS ticket_number, p.code AS project_code, t.title AS ticket_title
             FROM automation_log l
             LEFT JOIN tickets t ON t.id = l.ticket_id
             LEFT JOIN projects p ON p.id = t.project_id
             WHERE l.rule_id = :rule ORDER BY l.id DESC LIMIT ' . max(1, $limit)
        );
        $statement->execute(['rule' => $ruleId]);

        return array_values($statement->fetchAll());
    }

    /** @param array<string, mixed> $rule */
    private static function decoded(array $rule): array
    {
        $actions = json_decode((string) $rule['actions'], true);
        $rule['actions'] = is_array($actions) ? array_values(array_filter($actions, 'is_array')) : [];

        return $rule;
    }
}
