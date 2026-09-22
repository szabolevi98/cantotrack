<?php

namespace CantoTrack\Core;

/**
 * Writes what went wrong to var/log, and nothing to the response.
 *
 * Nothing here ever throws. A logger that can fail takes the page with it, and
 * the first thing it would fail on is a disk that is full or a folder that is
 * read-only — exactly the moments when the log is the only way to find out what
 * happened.
 */
class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        try {
            $directory = dirname(__DIR__, 2) . '/var/log';
            if (!is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            $line = sprintf(
                "[%s] %s: %s%s\n",
                date('Y-m-d H:i:s'),
                $level,
                $message,
                $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            // One file per day, so a long-running installation does not end up
            // with a single log nobody can open.
            @file_put_contents($directory . '/' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Deliberately silent: see the class comment.
        }
    }
}
