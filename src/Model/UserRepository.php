<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Password;
use PDO;

/**
 * The people who can sign in, and who tickets are assigned to.
 *
 * Users are never deleted, only deactivated: a ticket carries who reported it
 * and who it is assigned to, and a worklog carries whose hours those were.
 * Deleting the row would either take that history with it or leave it pointing
 * at nothing.
 */
class UserRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    /** An account that can be assigned work and can sign in — or nothing. */
    public function findActive(int $id): ?array
    {
        $user = $this->find($id);

        return $user !== null && (int) $user['is_active'] === 1 ? $user : null;
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE email = :email');
        $statement->execute(['email' => mb_strtolower(trim($email))]);

        return $statement->fetch() ?: null;
    }

    /** Everyone, for the assignee lists. Inactive people are kept out of those. */
    /**
     * What a person hears about and how (see NotifySettings), and the
     * morning digest's query, or null for none.
     */
    public function setNotifications(int $id, string $prefs, ?string $digestQuery): void
    {
        // The choices are what counts now: the old "email me at all" is on.
        $this->db->prepare(
            'UPDATE users SET notify_prefs = :prefs, notify_email = 1, digest_query = :digest WHERE id = :id'
        )->execute(['prefs' => $prefs, 'digest' => $digestQuery, 'id' => $id]);
    }

    /** What an hour of a person's is worth, or null for no rate. */
    public function setRate(int $id, ?float $rate): void
    {
        $this->db->prepare('UPDATE users SET hourly_rate = :rate WHERE id = :id')->execute(['rate' => $rate, 'id' => $id]);
    }

    /** The hash of a person's private calendar address, or null to have none — see the 0036 migration. */
    public function setCalendarExport(int $id, ?string $hash): void
    {
        $this->db->prepare('UPDATE users SET calendar_export_hash = :hash WHERE id = :id')->execute(['hash' => $hash, 'id' => $id]);
    }

    /** The active person a private calendar address belongs to. */
    public function findByCalendarExport(string $hash): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE calendar_export_hash = :hash AND is_active = 1');
        $statement->execute(['hash' => $hash]);

        return $statement->fetch() ?: null;
    }

    public function active(): array
    {
        return $this->rows(
            'SELECT * FROM users WHERE is_active = 1 ORDER BY name'
        );
    }

    public function all(): array
    {
        return $this->rows('SELECT * FROM users ORDER BY is_active DESC, name');
    }

    public function create(string $name, string $email, string $password, string $role = 'member'): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO users (name, handle, email, password_hash, role, created_at, updated_at)
             VALUES (:name, :handle, :email, :hash, :role, NOW(), NOW())'
        );

        $statement->execute([
            'name' => trim($name),
            'handle' => $this->freeHandle($email),
            'email' => mb_strtolower(trim($email)),
            'hash' => Password::hash($password),
            'role' => self::role($role),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The name somebody is @mentioned by: the part of their address before
     * the @, letters and digits only — the same rule the migration used for
     * the accounts that existed then — with a number added if it is taken.
     */
    public function freeHandle(string $email): string
    {
        $base = substr((string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(strstr(trim($email), '@', true) ?: $email)), 0, 30);
        $base = strlen($base) < 2 ? 'user' . $base : $base;

        $taken = $this->db->prepare('SELECT EXISTS (SELECT 1 FROM users WHERE handle = :handle)');

        for ($suffix = 0; ; $suffix++) {
            $handle = $suffix === 0 ? $base : $base . ($suffix + 1);
            $taken->execute(['handle' => $handle]);

            if (!(bool) $taken->fetchColumn()) {
                return $handle;
            }
        }
    }

    /**
     * Whether an address is already somebody's, ignoring one id — which is what
     * an edit form needs, since a person keeping their own address is not a
     * clash.
     */
    public function emailTaken(string $email, ?int $exceptId = null): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM users WHERE email = :email AND (:except IS NULL OR id <> :except2)'
        );

        $statement->execute([
            'email' => mb_strtolower(trim($email)),
            'except' => $exceptId,
            'except2' => $exceptId,
        ]);

        return ((int) $statement->fetchColumn()) > 0;
    }

    public function update(int $id, string $name, string $email, string $role, bool $isActive): void
    {
        $statement = $this->db->prepare(
            'UPDATE users SET name = :name, email = :email, role = :role, is_active = :active WHERE id = :id'
        );

        $statement->execute([
            'name' => trim($name),
            'email' => mb_strtolower(trim($email)),
            'role' => self::role($role),
            'active' => $isActive ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * A person's working week, as minutes per weekday from Monday; null goes
     * back to the workspace's usual five days.
     *
     * @param list<int>|null $minutes
     */
    public function setWorkingWeek(int $id, ?array $minutes): void
    {
        $this->db->prepare('UPDATE users SET working_week = :week WHERE id = :id')->execute([
            'week' => $minutes === null ? null : implode(',', $minutes),
            'id' => $id,
        ]);
    }

    /** The address of one's own calendar, or none. */
    public function setCalendarFeed(int $id, ?string $address): void
    {
        $this->db->prepare('UPDATE users SET calendar_feed = :feed WHERE id = :id')
            ->execute(['feed' => $address, 'id' => $id]);
    }

    /** The language alone: what the top bar's menu changes. */
    public function setLocale(int $id, string $locale): void
    {
        $this->db->prepare('UPDATE users SET locale = :locale WHERE id = :id')
            ->execute(['locale' => $locale, 'id' => $id]);
    }

    /** The appearance alone: what the sidebar's switch changes. */
    public function setTheme(int $id, string $theme): void
    {
        $this->db->prepare('UPDATE users SET theme = :theme WHERE id = :id')->execute([
            'theme' => in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system',
            'id' => $id,
        ]);
    }

    /** What a person may change about themselves on their profile. */
    public function updateProfile(int $id, string $name, ?string $shortName, ?string $locale, string $theme): void
    {
        $statement = $this->db->prepare(
            'UPDATE users SET name = :name, short_name = :short_name, locale = :locale, theme = :theme WHERE id = :id'
        );

        $statement->execute([
            'name' => trim($name),
            'short_name' => trim((string) $shortName) ?: null,
            'locale' => $locale ?: null,
            'theme' => in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system',
            'id' => $id,
        ]);
    }

    /**
     * Sets a new password.
     *
     * Every session of that person's is left alone deliberately: this is used to
     * hand somebody a password they have lost, not to lock them out of the
     * browser they are sitting at.
     */
    public function setPassword(int $id, string $password): void
    {
        $statement = $this->db->prepare(
            'UPDATE users SET password_hash = :hash, password_changed_at = NOW() WHERE id = :id'
        );
        $statement->execute(['hash' => Password::hash($password), 'id' => $id]);
    }

    /**
     * Stores the same password under a stronger hash. Not a change of password,
     * so the date it was last changed stays what it was.
     */
    public function rehash(int $id, string $password): void
    {
        $this->db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => Password::hash($password), 'id' => $id]);
    }

    /**
     * A password to hand over: readable, from an alphabet with no characters
     * that can be misread, and long enough that it does not matter which twelve
     * they are.
     */
    public static function newPassword(int $length = 12): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    /** How much each person has open and logged, for the people page. */
    public function withActivity(): array
    {
        return $this->rows(
            'SELECT u.*,
                    (SELECT COUNT(*) FROM tickets t JOIN statuses s ON s.id = t.status_id
                     WHERE t.assignee_id = u.id AND s.category <> \'done\') AS open_tickets,
                    (SELECT COALESCE(SUM(w.minutes), 0) FROM worklogs w
                     WHERE w.user_id = u.id AND w.work_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS minutes_30d
             FROM users u
             ORDER BY u.is_active DESC, u.name'
        );
    }

    public function touchLastLogin(int $id): void
    {
        $statement = $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /** What to call somebody in a sentence addressed to them. */
    public static function callName(array $user): string
    {
        return trim((string) ($user['short_name'] ?? '')) ?: (string) ($user['name'] ?? '');
    }

    private static function role(string $role): string
    {
        return in_array($role, ['admin', 'guest'], true) ? $role : 'member';
    }

    /** Every row a query without parameters returns. */
    private function rows(string $sql): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute();

        return $statement->fetchAll();
    }
}
