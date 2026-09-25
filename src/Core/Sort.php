<?php

namespace CantoTrack\Core;

/**
 * A list put in order by clicking a column header: once ascending, again
 * descending, and a third time back to the list's own order.
 *
 * The order travels in the address, ?sort=key&dir=asc|desc, so a sorted list
 * is a URL like a filtered one. The key only ever picks from the columns the
 * list offers — what reaches the SQL is the list's own expression for it,
 * never the text in the address.
 */
final class Sort
{
    /**
     * The order a list asked for, if it offers that column: the clicked one
     * first, and the list's own order after it to break the ties.
     *
     * @param array<string, string> $columns key => the SQL that orders by it
     */
    public static function orderBy(array $columns, string $default): string
    {
        $current = self::current();

        if ($current === null || !isset($columns[$current['key']])) {
            return $default;
        }

        return $columns[$current['key']] . ' ' . strtoupper($current['dir']) . ', ' . $default;
    }

    /**
     * The column and the direction in the address, if they are well formed.
     * Whether the list offers the column is for the list to say.
     *
     * @return ?array{key: string, dir: 'asc'|'desc'}
     */
    public static function current(): ?array
    {
        $key = $_GET['sort'] ?? null;

        if (!is_string($key) || preg_match('/^[a-z0-9_]{1,40}$/', $key) !== 1) {
            return null;
        }

        return ['key' => $key, 'dir' => ($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc'];
    }

    /** @return 'asc'|'desc'|null how a column is sorted now, if it is */
    public static function state(string $key): ?string
    {
        $current = self::current();

        return $current !== null && $current['key'] === $key ? $current['dir'] : null;
    }

    /**
     * Where a click on a header goes: the next of the three states, with every
     * other part of the address kept — the filters, the query — and the page
     * dropped, since a new order starts on the first one.
     */
    public static function url(string $key): string
    {
        $query = array_diff_key($_GET, ['sort' => true, 'dir' => true, 'page' => true]);

        $query += match (self::state($key)) {
            null => ['sort' => $key, 'dir' => 'asc'],
            'asc' => ['sort' => $key, 'dir' => 'desc'],
            'desc' => [],
        };

        return '?' . http_build_query($query);
    }

    /** A header's text as the link that sorts by it, with an arrow for its state. */
    public static function link(string $key, string $label): string
    {
        $state = self::state($key);
        $title = match ($state) {
            null => __('Sort ascending'),
            'asc' => __('Sort descending'),
            'desc' => __('Back to the list’s own order'),
        };
        $arrow = match ($state) {
            null => '↕',
            'asc' => '▲',
            'desc' => '▼',
        };
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<a class="sort' . ($state !== null ? ' is-sorted' : '') . '" href="' . $escape(self::url($key)) . '" title="' . $escape($title) . '">'
            . $escape($label) . '<span class="sort__arrow" aria-hidden="true">' . $arrow . '</span></a>';
    }

    /** The header's aria-sort, for a screen reader, when it is the sorted one. */
    public static function aria(string $key): string
    {
        return match (self::state($key)) {
            'asc' => 'aria-sort="ascending"',
            'desc' => 'aria-sort="descending"',
            null => '',
        };
    }

    /** The order as hidden fields, so a GET form sent from a sorted list keeps it. */
    public static function inputs(): string
    {
        $current = self::current();

        if ($current === null) {
            return '';
        }

        return '<input type="hidden" name="sort" value="' . htmlspecialchars($current['key'], ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="dir" value="' . $current['dir'] . '">';
    }
}
