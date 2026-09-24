<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Access;
use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Model\TicketRepository;

/**
 * What a text box offers while somebody types in it: the people an "@"
 * could mean, the tickets a "#" or the start of a key could — see
 * suggest.js. Only ever what the person may see anyway.
 */
class SuggestController extends Controller
{
    public function suggest(): void
    {
        Auth::require();

        $kind = (string) ($_GET['kind'] ?? '');
        $q = trim((string) ($_GET['q'] ?? ''));

        $this->json(['items' => match ($kind) {
            'people' => $this->people($q),
            'tickets' => $this->tickets($q),
            default => [],
        }]);
    }

    /** @return list<array{value: string, label: string, hint: string}> */
    private function people(string $q): array
    {
        $like = addcslashes(mb_strtolower($q), '%_\\') . '%';
        $statement = DatabaseConnection::get()->prepare(
            'SELECT name, handle FROM users
             WHERE is_active = 1 AND handle IS NOT NULL
               AND (LOWER(handle) LIKE :q OR LOWER(name) LIKE :q2 OR LOWER(name) LIKE :q3)
             ORDER BY LOWER(handle) LIKE :q4 DESC, name LIMIT 8'
        );
        $statement->execute(['q' => $like, 'q2' => $like, 'q3' => '% ' . $like, 'q4' => $like]);

        return array_values(array_map(static fn(array $u): array => [
            'value' => '@' . $u['handle'],
            'label' => (string) $u['name'],
            'hint' => '@' . $u['handle'],
        ], $statement->fetchAll()));
    }

    /** @return list<array{value: string, label: string, hint: string}> */
    private function tickets(string $q): array
    {
        $shown = static fn(array $t): array => [
            'value' => $t['project_code'] . '-' . $t['number'],
            'label' => (string) $t['title'],
            'hint' => $t['project_code'] . '-' . $t['number'],
        ];

        // The start of a key: "BIKE-2" is BIKE-2, BIKE-20, BIKE-21…
        if (preg_match('/^([A-Za-z][A-Za-z0-9]{1,9})-(\d*)$/', $q, $m) === 1) {
            $statement = DatabaseConnection::get()->prepare(
                'SELECT t.number, t.title, p.code AS project_code FROM tickets t JOIN projects p ON p.id = t.project_id
                 WHERE p.code = :code AND CAST(t.number AS CHAR) LIKE :number' . Access::sql('t.project_id') . '
                 ORDER BY t.number = :exact DESC, t.number DESC LIMIT 8'
            );
            $statement->execute(['code' => strtoupper($m[1]), 'number' => $m[2] . '%', 'exact' => (int) $m[2]]);

            return array_values(array_map($shown, $statement->fetchAll()));
        }

        if (mb_strlen($q) < 2) {
            return array_values(array_map($shown, (new TicketRepository())->search(['status' => 'in_progress'], 8)));
        }

        return array_values(array_map($shown, (new TicketRepository())->search(['q' => $q, 'order' => 'relevance'], 8)));
    }
}
