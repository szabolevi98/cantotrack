<?php

/**
 * Builds the files the browser loads: web/assets/css/app.css from the layered
 * sources under src/View/Css, web/assets/js/app.js from the ones under
 * src/View/Js, and web/assets/js/sidebar.js, the one script that has to run
 * before the first paint and so cannot wait in the deferred bundle. Concatenation and nothing else — no Sass, no PostCSS, no
 * bundler. This is the whole build.
 *
 * The order matters (a later stylesheet relies on the custom properties an
 * earlier one defined; a later script on the helpers an earlier one set up) and
 * is kept here by hand. That is the cost of not having a bundler, and it is
 * cheaper than the bundler.
 *
 * Both outputs are committed, so a deployment is a `git pull` and nothing runs
 * on the server. Run this after editing any source, and commit the result.
 *
 *   php bin/build_assets.php
 */

$root = dirname(__DIR__);

$bundles = [
    'web/assets/css/app.css' => [
        'source' => 'src/View/Css',
        'comment' => ['/*', ' *', ' */'],
        'files' => [
            'base/variables.css',
            'base/reset.css',
            'layout/shell.css',
            'layout/page.css',
            'components/card.css',
            'components/form.css',
            'components/badge.css',
            'components/table.css',
            'components/board.css',
            'components/project.css',
            'components/worklog.css',
            'components/activity.css',
            'components/planning.css',
            'components/icons.css',
            'components/topbar.css',
            'components/navigation.css',
            'components/notifications.css',
            'components/time.css',
            'components/reports.css',
            'components/calendar.css',
            'components/roadmap.css',
            'components/inline.css',
            'components/billing.css',
            'pages/auth.css',
        ],
    ],
    'web/assets/js/app.js' => [
        'source' => 'src/View/Js',
        'comment' => ['/*', ' *', ' */'],
        'files' => [
            'core.js',
            'uploads.js',
            'board.js',
            'shortcuts.js',
            'timer.js',
            'quicklog.js',
            'calendar.js',
            'roadmap.js',
            'query.js',
            'pages.js',
            'inline.js',
            'suggest.js',
            'palette.js',
            'dashboard.js',
        ],
    ],
    'web/assets/js/sidebar.js' => [
        'source' => 'src/View/Js',
        'comment' => ['/*', ' *', ' */'],
        'files' => [
            'sidebar-scroll.js',
        ],
    ],
];

foreach ($bundles as $output => $bundle) {
    [$open, $middle, $close] = $bundle['comment'];

    $content = $open . ' Built by bin/build_assets.php — do not edit.' . "\n"
        . $middle . ' Edit the sources under ' . $bundle['source'] . '/ and run the script again.' . "\n"
        . $close . "\n\n";

    foreach ($bundle['files'] as $file) {
        $path = $root . '/' . $bundle['source'] . '/' . $file;

        if (!is_file($path)) {
            fwrite(STDERR, "Missing source: {$bundle['source']}/$file\n");
            exit(1);
        }

        // Line endings normalised, so a checkout on Windows and one on Linux
        // build the same bytes — and a deployment's `git status` stays clean.
        $content .= rtrim(str_replace("\r\n", "\n", (string) file_get_contents($path))) . "\n\n";
    }

    $target = $root . '/' . $output;

    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0775, true);
    }

    file_put_contents($target, rtrim($content) . "\n");

    printf('Built %s from %d source files.%s', $output, count($bundle['files']), PHP_EOL);
}
