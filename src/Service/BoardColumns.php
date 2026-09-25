<?php

namespace CantoTrack\Service;

/**
 * The columns of a shared board, and which of its projects' statuses go in
 * which — worked out, not stored, apart from what somebody set by hand.
 *
 * Each project has columns of its own (see the 0005 migration), and a board
 * holding several projects has to put them somewhere. The rule, in order:
 *
 * 1. Without columns of its own, the board has one column per name its
 *    projects' columns have — "In progress" in two projects is one column —
 *    in the order the projects have them. A name only one project has goes
 *    after the one it comes after there.
 * 2. With columns of its own, a status goes where it was put by hand;
 * 3. failing that, to the column with its name;
 * 4. failing that, by its category: to the first column already holding one
 *    of its kind, or else a waiting one to the first column, a finished one
 *    to the last, and one in progress to the second.
 *
 * So every ticket is somewhere on the board. A board that quietly leaves
 * out the tickets nobody gave a column is a board that hides work.
 */
final class BoardColumns
{
    /**
     * @param array<int, list<array>> $statusesByProject each project's columns in their order
     * @param list<array{id: int, name: string, status_ids: list<int>}> $own the board's own columns (BoardRepository::columns)
     * @return array{columns: list<array>, of: array<int, string>} the columns in order — each with
     *         `id`, `name`, `colour`, `status_ids` and `done` — and for each status the id of the column it is in
     */
    public static function work(array $statusesByProject, array $own = []): array
    {
        return $own === [] ? self::byName($statusesByProject) : self::byHand($statusesByProject, $own);
    }

    /** A name as it is compared: "In Progress", "in progress" and "In-progress" are one. */
    public static function plain(string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($name));
    }

    /**
     * The statuses of one project in a column — where a card of that project
     * dropped there goes. The one it is in already comes first, so a move
     * within the column only puts it in order.
     *
     * @param array $column one of work()'s columns
     * @param array<int, array> $projectStatuses the ticket's project's columns, in order
     */
    public static function statusFor(array $column, array $projectStatuses, int $currentStatusId): ?array
    {
        $candidates = array_values(array_filter(
            $projectStatuses,
            static fn(array $s): bool => in_array((int) $s['id'], $column['status_ids'], true)
        ));

        foreach ($candidates as $status) {
            if ((int) $status['id'] === $currentStatusId) {
                return $status;
            }
        }

        return $candidates[0] ?? null;
    }

    /** @return array{columns: list<array>, of: array<int, string>} */
    private static function byName(array $statusesByProject): array
    {
        /** @var list<string> $order */
        $order = [];
        $columns = [];

        foreach ($statusesByProject as $statuses) {
            $previous = null;

            foreach ($statuses as $status) {
                $key = self::plain((string) $status['name']);

                if (!isset($columns[$key])) {
                    $columns[$key] = [
                        'id' => 'n-' . $key,
                        'name' => (string) $status['name'],
                        'colour' => (string) $status['colour'],
                        'status_ids' => [],
                        'categories' => [],
                    ];

                    // Right after the column the one before it is in, or first.
                    $at = $previous === null ? 0 : array_search($previous, $order, true) + 1;
                    array_splice($order, (int) $at, 0, [$key]);
                }

                $columns[$key]['status_ids'][] = (int) $status['id'];
                $columns[$key]['categories'][] = (string) $status['category'];
                $previous = $key;
            }
        }

        $ordered = array_map(static fn(string $key): array => $columns[$key], $order);

        return self::finish($ordered);
    }

    /** @return array{columns: list<array>, of: array<int, string>} */
    private static function byHand(array $statusesByProject, array $own): array
    {
        $columns = [];
        $byName = [];

        foreach ($own as $index => $column) {
            $columns[$index] = [
                'id' => 'c-' . $column['id'],
                'name' => (string) $column['name'],
                'colour' => '',
                'status_ids' => [],
                'categories' => [],
            ];
            $byName[self::plain((string) $column['name'])] ??= $index;
        }

        $placed = [];
        $all = [];
        foreach ($statusesByProject as $statuses) {
            foreach ($statuses as $status) {
                $all[] = $status;
            }
        }

        // By hand first, then by name: those decide what kind each column is.
        foreach ($own as $index => $column) {
            foreach ($all as $status) {
                if (in_array((int) $status['id'], $column['status_ids'], true) && !isset($placed[(int) $status['id']])) {
                    self::put($columns[$index], $status);
                    $placed[(int) $status['id']] = true;
                }
            }
        }

        foreach ($all as $status) {
            $index = $byName[self::plain((string) $status['name'])] ?? null;

            if ($index !== null && !isset($placed[(int) $status['id']])) {
                self::put($columns[$index], $status);
                $placed[(int) $status['id']] = true;
            }
        }

        $last = count($columns) - 1;
        foreach ($all as $status) {
            if (isset($placed[(int) $status['id']])) {
                continue;
            }

            $category = (string) $status['category'];
            $index = null;
            foreach ($columns as $i => $column) {
                if (in_array($category, $column['categories'], true)) {
                    $index = $i;
                    break;
                }
            }

            $index ??= match ($category) {
                'todo' => 0,
                'done' => $last,
                default => min(1, $last),
            };

            self::put($columns[$index], $status);
        }

        return self::finish(array_values($columns));
    }

    private static function put(array &$column, array $status): void
    {
        $column['status_ids'][] = (int) $status['id'];
        $column['categories'][] = (string) $status['category'];

        if ($column['colour'] === '') {
            $column['colour'] = (string) $status['colour'];
        }
    }

    /**
     * Each column says whether it is where finished work ends up, and each status where it is.
     *
     * @return array{columns: list<array>, of: array<int, string>}
     */
    private static function finish(array $columns): array
    {
        $of = [];

        foreach ($columns as &$column) {
            $column['done'] = $column['categories'] !== [] && array_unique($column['categories']) === ['done'];
            $column['colour'] = $column['colour'] ?: 'slate';
            unset($column['categories']);

            foreach ($column['status_ids'] as $statusId) {
                $of[$statusId] = $column['id'];
            }
        }
        unset($column);

        return ['columns' => array_values($columns), 'of' => $of];
    }
}
