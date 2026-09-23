<?php

/*
 * Loads the autoloader and the test configuration.
 *
 * The configuration is the test database's, never the working one: the
 * integration tests delete every row they can reach before each test. The
 * guard below refuses to run against a database whose name does not end in
 * "_test", which is the one mistake that would make that deletion somebody's
 * real work.
 */

use CantoTrack\Core\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = getenv('CANTOTRACK_TEST_CONFIG') ?: dirname(__DIR__) . '/config/test.ini';

if (is_file($config)) {
    Config::load($config);

    $name = (string) Config::get('database.name', '');
    if (!str_ends_with($name, '_test')) {
        fwrite(STDERR, "The test database must be named something_test; $config names \"$name\".\n");
        exit(1);
    }

    define('CANTOTRACK_TEST_CONFIG', $config);
}

date_default_timezone_set('Europe/Budapest');
