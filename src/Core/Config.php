<?php

namespace CantoTrack\Core;

/**
 * The settings file, read once and asked for by "section.key".
 *
 * An INI file rather than PHP, so that a deployment can be reconfigured without
 * anyone editing code, and so that a syntax error in it cannot take the whole
 * application down with a parse error.
 */
class Config
{
    private static ?array $data = null;

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException(
                "Config file not found: $path — copy config/config.ini.dist to config/config.ini."
            );
        }

        $parsed = parse_ini_file($path, true);
        if ($parsed === false) {
            throw new \RuntimeException("Config file could not be parsed: $path");
        }

        self::$data = $parsed;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$data === null) {
            throw new \RuntimeException('Config not loaded yet.');
        }

        [$section, $name] = array_pad(explode('.', $key, 2), 2, null);

        if ($name === null) {
            return self::$data[$section] ?? $default;
        }

        return self::$data[$section][$name] ?? $default;
    }

    /**
     * An integer setting. An empty value in the INI file reads as an empty
     * string rather than as missing, and `(int) ''` is zero — which for an
     * interval or a limit is the one value that quietly breaks things.
     */
    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return ($value === null || $value === '') ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return ($value === null || $value === '') ? $default : (bool) (int) $value;
    }
}
