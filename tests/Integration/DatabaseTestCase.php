<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * A test that talks to the test database, emptied before every test.
 *
 * Emptied rather than wrapped in a transaction that is rolled back: the code
 * under test opens transactions of its own (a ticket and its number are one),
 * and MySQL has no nested ones.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static bool $migrated = false;

    protected PDO $db;

    protected function setUp(): void
    {
        if (!defined('CANTOTRACK_TEST_CONFIG')) {
            self::markTestSkipped('No test database configured (config/test.ini or CANTOTRACK_TEST_CONFIG).');
        }

        if (!self::$migrated) {
            $output = [];
            exec(sprintf(
                '%s %s --config=%s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(dirname(__DIR__, 2) . '/database/migrate.php'),
                escapeshellarg(CANTOTRACK_TEST_CONFIG)
            ), $output, $status);

            self::assertSame(0, $status, "Migrating the test database failed:\n" . implode("\n", $output));
            self::$migrated = true;
        }

        $this->db = DatabaseConnection::get();
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if ($table !== 'schema_migrations') {
                $this->db->exec('DELETE FROM `' . $table . '`');
            }
        }

        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** A person, active unless told otherwise. */
    protected function person(string $name = 'Anna Kovács', bool $active = true, string $role = 'member'): int
    {
        $users = new UserRepository($this->db);
        $email = 'person' . random_int(100000, 999999) . '@example.test';
        $id = $users->create($name, $email, 'a long enough password', $role);

        if (!$active) {
            $users->update($id, $name, $email, $role, false);
        }

        return $id;
    }

    protected function project(string $code = 'CT', bool $archived = false): int
    {
        $projects = new ProjectRepository($this->db);
        $id = $projects->create($code, 'Project ' . $code, null);

        if ($archived) {
            $projects->update($id, 'Project ' . $code, null, true);
        }

        return $id;
    }
}
