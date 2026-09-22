<?php

/**
 * Concatenates the layered CSS sources under src/View/Css into the one
 * web/assets/css/app.css the application links. No Sass, no PostCSS, no
 * bundler — this is the whole build.
 *
 * The order matters (a later file may rely on the custom properties and base
 * rules an earlier one defined) and has to be kept in step by hand when a new
 * source file appears. That is the cost of not having a bundler, and it is
 * cheaper than the bundler.
 *
 * Usage: php bin/build_css.php
 */

$root = dirname(__DIR__);
$sourceDirectory = $root . '/src/View/Css';
$outputFile = $root . '/web/assets/css/app.css';

$files = [
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
    'pages/auth.css',
];

$output = "/* Built by bin/build_css.php — do not edit.\n"
    . " * Edit the sources under src/View/Css/ and run the script again. */\n\n";

foreach ($files as $file) {
    $path = $sourceDirectory . '/' . $file;

    if (!is_file($path)) {
        fwrite(STDERR, "Missing CSS source: $file\n");
        exit(1);
    }

    $output .= rtrim((string) file_get_contents($path)) . "\n\n";
}

if (!is_dir(dirname($outputFile))) {
    mkdir(dirname($outputFile), 0775, true);
}

file_put_contents($outputFile, rtrim($output) . "\n");

printf("Built %s from %d source files.%s", $outputFile, count($files), PHP_EOL);
