<?php

/**
 * Lists the sentences the interface uses that the Hungarian catalogue does
 * not have, and the ones it has that nothing uses any more.
 *
 *   php bin/i18n_check.php            what is missing, and exit 1 if anything is
 *   php bin/i18n_check.php --unused   also what is no longer used
 *   php bin/i18n_check.php --dump     every sentence, one per line (for a translator)
 *
 * The sentences are found where they are written: __('…'), __n('…', '…'),
 * I18n::translate/plural in PHP; '…'|t and plural('…', '…') in the templates.
 * The few that are only known when the page runs — the names of the
 * weekdays, a priority read from the database — are listed below by hand.
 */

$root = dirname(__DIR__);

/** Sentences that reach |t or __() through a variable. */
const DYNAMIC = [
    // Weekdays and their short names, from date('l') and the week grids.
    'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
    'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun',
    // Priorities, types, and the default columns' names and kinds.
    'low', 'normal', 'high', 'urgent', 'task', 'bug', 'story',
    'Backlog', 'To do', 'In progress', 'Review', 'Done', 'todo', 'in_progress', 'done',
    // The sidebar's items, and the roles.
    'Dashboard', 'Projects', 'Tickets', 'Timesheet', 'Reports', 'Administrator', 'Member', 'Guest',
    // The fields a ticket's history names.
    'title', 'status', 'priority', 'type', 'assignee', 'epic', 'estimate', 'due date', 'story points', 'labels', 'description',
    // Days away, and the link kinds.
    'Day off', 'Ill', 'Away',
    'blocks', 'is blocked by', 'relates to', 'duplicates', 'is duplicated by',
    // The error page's titles (ErrorPage::TITLES).
    'That request did not make sense', 'Sign in first', 'Not yours to open', 'Nothing here', 'Not like that',
    'Somebody got there first', 'That is too big', 'That did not look right', 'Too many tries', 'Something went wrong',
];

/** @return list<string> */
function files(string $dir, string $extension): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), $extension)) {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

/** A PHP or Twig string literal, either quote, as its value. */
function literal(string $quote, string $body): string
{
    return $quote === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body) : stripcslashes($body);
}

/**
 * The value of whichever of a pair of groups matched — the single-quoted
 * literal or the double-quoted one.
 *
 * @param array<int, string> $hit
 */
function picked(array $hit, int $single): string
{
    $double = $hit[$single + 1] ?? '';

    return $double !== '' ? literal('"', $double) : literal("'", $hit[$single] ?? '');
}

$single = "'((?:[^'\\\\]|\\\\.)*)'";
$double = '"((?:[^"\\\\]|\\\\.)*)"';
$string = '(?:' . $single . '|' . $double . ')';

$used = [];
$note = static function (string $text, string $file) use (&$used, $root): void {
    if ($text !== '') {
        $used[$text][] = substr($file, strlen($root) + 1);
    }
};

foreach (files($root . '/src', '.php') as $file) {
    $code = (string) file_get_contents($file);

    // __('one') and I18n::translate('one')
    preg_match_all('/(?<![\w>])(?:__|I18n::translate)\(\s*' . $string . '/', $code, $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        $note(picked($hit, 1), $file);
    }

    // __n('one', 'many') and I18n::plural('one', 'many')
    preg_match_all('/(?<![\w>])(?:__n|I18n::plural)\(\s*' . $string . '\s*,\s*' . $string . '/', $code, $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        $note(picked($hit, 1), $file);
        $note(picked($hit, 3), $file);
    }
}

foreach (files($root . '/src/View', '.twig') as $file) {
    $code = (string) file_get_contents($file);

    // 'one'|t — with any filters or a call in between: 'x'|t({…})
    preg_match_all('/' . $string . '\s*\|\s*t\b/', $code, $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        $note(picked($hit, 1), $file);
    }

    // The values of a literal map — { low: 'Low', high: 'High' } — which a
    // template looks a word up in and then translates.
    // Keys that hold something other than words — an icon's name, a path,
    // an element's id, which tab is the active one, which field a form
    // changes, what a command does — are left out.
    preg_match_all("/\\b([a-z_]+):\\s*'([A-Za-z][^'\\\\]*)'/", $code, $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        if (!in_array($hit[1], ['icon', 'path', 'id', 'active', 'field', 'action'], true)) {
            $note($hit[2], $file);
        }
    }

    // A ternary's two sentences both translated: cond ? 'a'|t : 'b'|t is
    // covered above; plural('one', 'many', n) here.
    preg_match_all('/\bplural\(\s*' . $string . '\s*,\s*' . $string . '/', $code, $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        $note(picked($hit, 1), $file);
        $note(picked($hit, 3), $file);
    }
}

foreach (DYNAMIC as $text) {
    $note($text, 'bin/i18n_check.php');
}

ksort($used);

if (in_array('--dump', $argv, true)) {
    foreach (array_keys($used) as $text) {
        echo str_replace("\n", '\\n', $text), PHP_EOL;
    }

    exit(0);
}

$catalogue = require $root . '/lang/hu.php';
$missing = array_diff_key($used, $catalogue);

foreach ($missing as $text => $where) {
    printf('missing  %s   (%s)%s', $text, $where[0], PHP_EOL);
}

if (in_array('--unused', $argv, true)) {
    foreach (array_diff_key($catalogue, $used) as $text => $_) {
        printf('unused   %s%s', $text, PHP_EOL);
    }
}

// A translation has to keep every {placeholder} of the English: a missing
// one would print the sentence without the number in it.
$broken = 0;
foreach ($catalogue as $english => $hungarian) {
    preg_match_all('/\{\w+\}/', (string) $english, $a);
    preg_match_all('/\{\w+\}/', (string) $hungarian, $b);
    $lost = array_diff($a[0], $b[0]);

    if ($lost !== []) {
        printf('placeholder %s lost in: %s%s', implode(', ', $lost), $english, PHP_EOL);
        $broken++;
    }
}

printf('%d sentences, %d translated, %d missing.%s', count($used), count($used) - count($missing), count($missing), PHP_EOL);

exit($missing === [] && $broken === 0 ? 0 : 1);
