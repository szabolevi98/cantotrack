<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Service\AuditLog;
use PDO;

/** Reading the record of who did what — see AuditLog. */
class AuditRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * @param array{user_id?: ?int, group?: string, q?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function page(array $filters, int $limit = 100, int $offset = 0): array
    {
        [$where, $parameters] = $this->conditions($filters);
        $statement = $this->db->prepare(
            'SELECT * FROM audit_log' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $statement->execute($parameters);

        return array_values($statement->fetchAll());
    }

    /** @param array{user_id?: ?int, group?: string, q?: string} $filters */
    public function count(array $filters): int
    {
        [$where, $parameters] = $this->conditions($filters);
        $statement = $this->db->prepare('SELECT COUNT(*) FROM audit_log' . $where);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array{id: int, name: string}> everybody who is in the record */
    public function people(): array
    {
        $statement = $this->db->query('SELECT DISTINCT user_id AS id, user_name AS name FROM audit_log WHERE user_id IS NOT NULL ORDER BY user_name');

        return $statement === false ? [] : array_values(array_map(
            static fn(array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $statement->fetchAll()
        ));
    }

    /**
     * @param array{user_id?: ?int, group?: string, q?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function conditions(array $filters): array
    {
        $where = [];
        $parameters = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = :user';
            $parameters['user'] = (int) $filters['user_id'];
        }

        $actions = AuditLog::GROUPS[$filters['group'] ?? ''] ?? null;
        if ($actions !== null) {
            $marks = [];
            foreach ($actions as $i => $action) {
                $marks[] = ':a' . $i;
                $parameters['a' . $i] = $action;
            }
            $where[] = 'action IN (' . implode(', ', $marks) . ')';
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(subject_label LIKE :q OR details LIKE :q2 OR user_name LIKE :q3 OR ip LIKE :q4)';
            $parameters += ['q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like];
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $parameters];
    }
}
