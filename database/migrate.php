<?php

/**
 * The migration runner.
 *
 * Creates the database if it is not there, then runs the .sql files under
 * database/core in name order. Every step is written to be repeatable, so this
 * can be run at any time:
 *
 *   php database/migrate.php
 *
 * Against another database — which is what a deployment does:
 *
 *   php database/migrate.php --config=config/live.ini
 *
 * Without the option the config file would have to be edited back and forth,
 * and whoever forgets to edit it back has a local application pointing at the
 * live database.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use CantoTrack\Core\Config;

$root = dirname(__DIR__);
$configPath = $root . '/config/config.ini';

foreach ($argv ?? [] as $argument) {
    if (str_starts_with($argument, '--config=')) {
        $given = substr($argument, strlen('--config='));

        // A relative path is relative to the PROJECT ROOT, not to the working
        // directory: this script can be started from database/ as easily as
        // from the root, and "where am I" is the wrong question to ask in the
        // middle of an installation.
        $configPath = str_starts_with($given, '/') || preg_match('/^[A-Za-z]:/', $given) === 1
            ? $given
            : $root . '/' . $given;
    }
}

Config::load($configPath);

$database = (string) Config::get('database.name');
$charset = (string) Config::get('database.charset', 'utf8mb4');
$collation = (string) Config::get('database.collation', 'utf8mb4_unicode_ci');

// The server is connected to without a database first, because on a fresh
// machine the database is what this script is here to create.
$server = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;charset=%s',
        Config::get('database.host', '127.0.0.1'),
        Config::get('database.port', 3306),
        $charset
    ),
    (string) Config::get('database.user'),
    (string) Config::get('database.password'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$server->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
    str_replace('`', '', $database),
    $charset,
    $collation
));

$server->exec('USE `' . str_replace('`', '', $database) . '`');

$files = glob($root . '/database/core/*.sql') ?: [];
sort($files, SORT_NATURAL);

foreach ($files as $file) {
    $sql = trim((string) file_get_contents($file));

    if ($sql === '') {
        continue;
    }

    try {
        $server->exec($sql);
        printf("  ran %s%s", basename($file), PHP_EOL);
    } catch (PDOException $e) {
        fwrite(STDERR, sprintf('Failed in %s: %s%s', basename($file), $e->getMessage(), PHP_EOL));
        exit(1);
    }
}

printf('Database %s is up to date (%d file%s).%s', $database, count($files), count($files) === 1 ? '' : 's', PHP_EOL);
