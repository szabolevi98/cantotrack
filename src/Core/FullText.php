<?php

namespace CantoTrack\Core;

/**
 * Words typed into a search box, made into what the full-text index can
 * answer — see the 0032 migration — and the excerpt a result shows them in.
 *
 * The index has two blind spots, and both would turn a search into "nothing
 * found": words shorter than three letters are not in it, and neither are
 * the few dozen English words it leaves out as too common. Asked for one as
 * a word that must be there, it finds no row at all. So those are dropped
 * from what is asked; a search made only of them is answered the slow way,
 * by reading the text.
 */
final class FullText
{
    /** The shortest word the index keeps (innodb_ft_min_token_size). */
    public const MIN_LENGTH = 3;

    /** The words InnoDB does not index (INNODB_FT_DEFAULT_STOPWORD). */
    private const STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is',
        'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will',
        'with', 'und', 'www',
    ];

    /**
     * "+refund* +twice*": every word that can be looked up, each required,
     * each also as the start of a longer one — "refund" finds "refunds". Null
     * when no word can be.
     */
    public static function booleanQuery(string $words): ?string
    {
        $terms = [];

        foreach (self::words($words) as $word) {
            if (mb_strlen($word) >= self::MIN_LENGTH && !in_array($word, self::STOPWORDS, true)) {
                $terms[] = '+' . $word . '*';
            }
        }

        return $terms === [] ? null : implode(' ', $terms);
    }

    /**
     * The words in what was typed, lower case, each once. Anything that is
     * not a letter or a digit separates them — and so can never be read by
     * the index as one of its operators.
     *
     * @return list<string>
     */
    public static function words(string $typed): array
    {
        preg_match_all('/[\pL\pN_]+/u', mb_strtolower($typed), $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * A stretch of a text around the first of the words it has, HTML-escaped,
     * with each of the words marked. The start of the text when none is in
     * it (a match in a comment, say).
     */
    public static function excerpt(string $text, string $typed, int $length = 180): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
        $words = array_values(array_filter(self::words($typed), static fn(string $w): bool => mb_strlen($w) >= 2));

        if ($text === '') {
            return '';
        }

        $lower = mb_strtolower($text);
        $at = null;
        foreach ($words as $word) {
            $found = mb_strpos($lower, $word);
            if ($found !== false && ($at === null || $found < $at)) {
                $at = $found;
            }
        }

        $start = $at === null ? 0 : max(0, $at - intdiv($length, 3));

        // From the start of a word, not the middle of one.
        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start);
            $start = $space !== false && $space < $at ? $space + 1 : $start;
        }

        $piece = mb_substr($text, $start, $length);
        $escaped = htmlspecialchars($piece, ENT_QUOTES, 'UTF-8');

        // Marked before it is escaped, a piece at a time — marking the
        // escaped text would find "gt" inside "&gt;".
        if ($words !== []) {
            $pattern = '/(' . implode('|', array_map(static fn(string $w): string => preg_quote($w, '/'), $words)) . ')/iu';
            $escaped = '';
            foreach ((array) preg_split($pattern, $piece, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part) {
                $part = htmlspecialchars((string) $part, ENT_QUOTES, 'UTF-8');
                $escaped .= $i % 2 === 1 ? '<mark>' . $part . '</mark>' : $part;
            }
        }

        return ($start > 0 ? '…' : '') . $escaped . ($start + $length < mb_strlen($text) ? '…' : '');
    }
}
