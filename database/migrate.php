<?php

/**
 * The migration runner.
 *
 * Creates the database if it is not there, then runs every file under
 * database/migrations that has not run yet, in name order, and writes down that
 * it did. A file runs once per database and never again:
 *
 *   php database/migrate.php
 *   php database/migrate.php --status          (what has run, what is waiting)
 *   php database/migrate.php --config=config/live.ini
 *
 * The first three files are the schema as it was before this runner kept a
 * record, and they are written to be repeatable (CREATE TABLE IF NOT EXISTS), so
 * an installation made before the record existed simply has them written down
 * on its next run. Everything after them is an ALTER or a new table, and those
 * are only safe to run once — which is why there is a record at all.
 *
 * A migration is never edited after it has run anywhere. A change to the schema
 * is a new file. The runner keeps each file's checksum and says so when one no
 * longer matches, because an edited migration is one that two installations
 * have run in two different versions of.
 *
 * MySQL cannot roll a schema change back — CREATE and ALTER commit on their
 * own — so a file that fails halfway stays halfway. The runner stops at the
 * first failure and names the statement, and the file is not written down, so
 * after the cause is fixed the rest of it runs again. Every statement in a
 * migration should therefore be one that can be run twice (IF NOT EXISTS, IF
 * EXISTS) wherever MariaDB allows it.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use CantoTrack\Core\Config;

$root = dirname(__DIR__);
$configPath = $root . '/config/config.ini';
$statusOnly = in_array('--status', $argv ?? [], true);

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
$server->exec(sprintf('SET NAMES %s COLLATE %s', $charset, $collation));

$server->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        name VARCHAR(190) NOT NULL,
        checksum CHAR(64) NOT NULL,
        ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (name)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci'
);

$ran = [];
foreach ($server->query('SELECT name, checksum FROM schema_migrations') as $row) {
    $ran[$row['name']] = $row['checksum'];
}

$files = glob($root . '/database/migrations/*.sql') ?: [];
sort($files, SORT_NATURAL);

$pending = 0;

foreach ($files as $file) {
    $name = basename($file);
    $sql = (string) file_get_contents($file);
    $checksum = hash('sha256', str_replace("\r\n", "\n", $sql));

    if (isset($ran[$name])) {
        if (!hash_equals($ran[$name], $checksum)) {
            fwrite(STDERR, sprintf(
                "  warning: %s was edited after it ran here. It is not run again —%s"
                . "  a change to the schema belongs in a new migration.%s",
                $name,
                PHP_EOL,
                PHP_EOL
            ));
        }

        if ($statusOnly) {
            printf("  ran      %s%s", $name, PHP_EOL);
        }

        continue;
    }

    $pending++;

    if ($statusOnly) {
        printf("  waiting  %s%s", $name, PHP_EOL);
        continue;
    }

    foreach (migrationStatements($sql) as $number => $statement) {
        try {
            $server->exec($statement);
        } catch (PDOException $e) {
            fwrite(STDERR, sprintf(
                "Failed in %s, statement %d: %s%s%s%s",
                $name,
                $number + 1,
                $e->getMessage(),
                PHP_EOL,
                preg_replace('/\s+/', ' ', substr($statement, 0, 300)),
                PHP_EOL
            ));
            exit(1);
        }
    }

    $server->prepare('INSERT INTO schema_migrations (name, checksum) VALUES (:name, :checksum)')
        ->execute(['name' => $name, 'checksum' => $checksum]);

    printf("  ran %s%s", $name, PHP_EOL);
}

if ($statusOnly) {
    printf('%d waiting.%s', $pending, PHP_EOL);
    exit(0);
}

printf(
    'Database %s is up to date (%d migration%s, %d new).%s',
    $database,
    count($files),
    count($files) === 1 ? '' : 's',
    $pending,
    PHP_EOL
);

/**
 * A migration file split into its statements.
 *
 * One statement at a time rather than the whole file in one exec(): with
 * several statements in one call, MySQL reports an error only for the first,
 * and a failure in the fourth would pass without a word — leaving a schema that
 * is half one version and half another, and a record saying it is the new one.
 *
 * Comment lines go first, because a comment may end in a semicolon of its own.
 * A statement ends at a semicolon at the end of a line; that is a convention
 * these files keep rather than a parser, and it is enough for DDL.
 *
 * @return list<string>
 */
function migrationStatements(string $sql): array
{
    $lines = preg_split('/\R/', $sql) ?: [];
    $kept = array_filter($lines, static fn(string $line): bool => !preg_match('/^\s*--/', $line));

    $statements = preg_split('/;\s*$/m', implode("\n", $kept)) ?: [];

    return array_values(array_filter(
        array_map('trim', $statements),
        static fn(string $statement): bool => $statement !== ''
    ));
}
