<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/** The few settings an administrator changes from the application. */
class SettingRepository
{
    /** @var array<string, ?string> read once per request */
    private static array $cache = [];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public function get(string $name, ?string $default = null): ?string
    {
        if (!array_key_exists($name, self::$cache)) {
            $statement = $this->db->prepare('SELECT value FROM settings WHERE name = :name');
            $statement->execute(['name' => $name]);
            $value = $statement->fetchColumn();
            self::$cache[$name] = $value === false ? null : (string) $value;
        }

        return self::$cache[$name] ?? $default;
    }

    public function set(string $name, ?string $value): void
    {
        $this->db->prepare(
            'INSERT INTO settings (name, value) VALUES (:name, :value) ON DUPLICATE KEY UPDATE value = VALUES(value)'
        )->execute(['name' => $name, 'value' => $value]);

        self::$cache[$name] = $value;
    }

    public static function forget(): void
    {
        self::$cache = [];
    }
}
