<?php

namespace CantoTrack\Core;

use PDO;
use PDOException;

/**
 * The one database connection the request uses.
 *
 * A single connection rather than one per model: a request that opens four
 * connections spends more time on handshakes than on queries, and loses any
 * chance of wrapping several writes in one transaction.
 */
class DatabaseConnection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            Config::get('database.host', '127.0.0.1'),
            Config::get('database.port', 3306),
            Config::get('database.name'),
            Config::get('database.charset', 'utf8mb4')
        );

        // The connection's collation has to match the schema's. Without this the
        // bound string parameters get the server default while the columns keep
        // theirs, and any expression that brings the two together fails with
        // "Illegal mix of collations" — at run time, on a query that worked
        // everywhere else.
        $collation = (string) Config::get('database.collation', 'utf8mb4_unicode_ci');

        try {
            self::$instance = new PDO(
                $dsn,
                (string) Config::get('database.user'),
                (string) Config::get('database.password'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Real prepared statements, so the server types the
                    // parameters. The consequence to remember: a named
                    // placeholder may appear only once in a statement.
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => sprintf(
                        'SET NAMES %s COLLATE %s',
                        Config::get('database.charset', 'utf8mb4'),
                        $collation
                    ),
                ]
            );
        } catch (PDOException $e) {
            // The message carries the credentials in some drivers, so it is
            // logged rather than shown; the page gets the generic failure.
            Logger::error('Database connection failed: ' . $e->getMessage());

            throw new \RuntimeException('Database connection failed.', (int) $e->getCode(), $e);
        }

        return self::$instance;
    }
}
