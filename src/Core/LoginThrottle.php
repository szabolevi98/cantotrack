<?php

namespace CantoTrack\Core;

use PDO;

/**
 * The limit in front of the login form.
 *
 * Two counts over the last fifteen minutes: failures for one address, and
 * failures from one connection. The first stops somebody working through a
 * password list against one account; the second stops them working through
 * accounts from one machine. A successful sign-in clears the address's count,
 * so the person who mistyped twice and then got it right starts clean.
 *
 * The cost is that somebody who knows an address can keep its owner out for a
 * quarter of an hour by failing on purpose. That is the usual trade, and the
 * better side of it: fifteen minutes of "try again later" against an account
 * that can be guessed at forever.
 */
class LoginThrottle
{
    public const WINDOW_MINUTES = 15;
    public const PER_EMAIL = 5;
    public const PER_IP = 30;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public function isBlocked(string $email, string $ip): bool
    {
        $statement = $this->db->prepare(
            'SELECT
                 SUM(email = :email) AS for_email,
                 SUM(ip = :ip) AS for_ip
             FROM login_attempts
             WHERE attempted_at > NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE
               AND (email = :email2 OR ip = :ip2)'
        );

        $statement->execute([
            'email' => self::normalise($email),
            'ip' => $ip,
            'email2' => self::normalise($email),
            'ip2' => $ip,
        ]);

        $row = $statement->fetch() ?: [];

        return (int) ($row['for_email'] ?? 0) >= self::PER_EMAIL || (int) ($row['for_ip'] ?? 0) >= self::PER_IP;
    }

    public function recordFailure(string $email, string $ip): void
    {
        $this->db->prepare('INSERT INTO login_attempts (email, ip) VALUES (:email, :ip)')
            ->execute(['email' => self::normalise($email), 'ip' => $ip]);

        // Housekeeping, on the path that writes: nothing older than a day is
        // any use to the limit, and a table that only grows is a slow one.
        $this->db->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
    }

    public function clear(string $email): void
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE email = :email')
            ->execute(['email' => self::normalise($email)]);
    }

    private static function normalise(string $email): string
    {
        return mb_substr(mb_strtolower(trim($email)), 0, 190);
    }
}
