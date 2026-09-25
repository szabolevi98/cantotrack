<?php

namespace CantoTrack\Service;

/**
 * A board split into swimlanes — a project's board or a shared one.
 */
final class BoardLanes
{
    /**
     * The swimlanes: one row across the board per epic or per person, in the
     * order the project's epics and the team's names come in, and only the
     * ones that have a card on the board. "No epic" and "Unassigned" come
     * last — they are where things are waiting to be given somewhere.
     *
     * Without lanes, the board is a single lane holding everything.
     *
     * @param array<int|string, array> $columns the cards of each column, by the column's id
     * @return list<array{key: string, title: string, field: ?string, value: ?int, columns: array<int|string, array>}>
     */
    public static function split(array $columns, string $by, array $epics): array
    {
        if ($by === 'none') {
            return [['key' => 'all', 'title' => '', 'field' => null, 'value' => null, 'columns' => $columns]];
        }

        $field = $by === 'epic' ? 'epic_id' : 'assignee_id';
        $names = [];

        if ($by === 'epic') {
            foreach ($epics as $epic) {
                $names[(int) $epic['id']] = (string) $epic['title'];
            }
        }

        $lanes = [];
        foreach ($columns as $statusId => $tickets) {
            foreach ($tickets as $ticket) {
                $value = $ticket[$field] === null ? 0 : (int) $ticket[$field];

                if (!isset($lanes[$value])) {
                    $title = $value === 0
                        ? ($by === 'epic' ? __('No epic') : __('Unassigned'))
                        : ($by === 'epic' ? ($names[$value] ?? (string) $ticket['epic_title']) : (string) $ticket['assignee_name']);

                    $lanes[$value] = [
                        'key' => $by . '-' . $value,
                        'title' => $title,
                        'field' => $field,
                        'value' => $value === 0 ? null : $value,
                        'columns' => array_fill_keys(array_keys($columns), []),
                    ];
                }

                $lanes[$value]['columns'][$statusId][] = $ticket;
            }
        }

        uasort($lanes, static function (array $a, array $b): int {
            // The ones without an epic or a person last; the rest by name.
            return [$a['value'] === null, mb_strtolower($a['title'])] <=> [$b['value'] === null, mb_strtolower($b['title'])];
        });

        return array_values($lanes);
    }
}
