<?php

namespace CantoTrack\Core;

use PDO;

/**
 * Which projects somebody may see.
 *
 * - An administrator sees every project.
 * - A member sees the team's projects, and the private ones they were added to.
 * - A guest sees only the projects they were added to.
 *
 * The rule is applied where the rows are read — the ticket, project, epic,
 * sprint, worklog and report queries all narrow themselves to what the
 * signed-in person may see — rather than in each of the seventy places a
 * controller asks for one. A ticket in a project somebody cannot see is then
 * simply not there for them: "not found", on a page, in a search, in the API.
 *
 * Without anybody signed in (the command line, an integration that proves
 * itself with a signature) nothing is narrowed: those are not a person
 * looking at something.
 */
final class Access
{
    /** @var list<int>|null|false false: not worked out yet this request */
    private static array|null|false $visible = false;

    /**
     * The ids of the projects the signed-in person may see, or null for "all
     * of them".
     *
     * @return list<int>|null
     */
    public static function projectIds(): ?array
    {
        if (self::$visible !== false) {
            return self::$visible;
        }

        $user = Auth::user();

        return self::$visible = $user === null ? null : self::forUser($user);
    }

    /**
     * The same for anybody — whoever a notification is about to be sent to,
     * say, who is not the person signed in.
     *
     * @return list<int>|null
     */
    public static function forUser(array $user, ?PDO $db = null): ?array
    {
        if (($user['role'] ?? '') === 'admin') {
            return null;
        }

        $statement = ($db ?? DatabaseConnection::get())->prepare(
            'SELECT p.id FROM projects p
             WHERE (p.visibility = \'team\' AND :role <> \'guest\')
                OR EXISTS (SELECT 1 FROM project_members m WHERE m.project_id = p.id AND m.user_id = :user)'
        );
        $statement->execute(['role' => (string) ($user['role'] ?? 'member'), 'user' => (int) $user['id']]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    public static function canSeeProject(int $projectId): bool
    {
        $ids = self::projectIds();

        return $ids === null || in_array($projectId, $ids, true);
    }

    /**
     * A condition for a query's WHERE: "AND <column> IN (…)", or nothing when
     * the signed-in person sees everything. The ids are integers read from
     * the database, so they go into the statement as they are.
     *
     * $orOwn keeps a person's own rows in view whatever project they are in
     * — hours they logged in a project they have since been taken off are
     * still their hours.
     */
    public static function sql(string $column, ?string $ownColumn = null): string
    {
        $ids = self::projectIds();

        if ($ids === null) {
            return '';
        }

        $in = $ids === [] ? 'FALSE' : $column . ' IN (' . implode(',', $ids) . ')';

        if ($ownColumn !== null && Auth::id() !== null) {
            return ' AND (' . $in . ' OR ' . $ownColumn . ' = ' . (int) Auth::id() . ')';
        }

        return ' AND ' . $in;
    }

    /** The same condition without the "AND", for a list of conditions; null for none. */
    public static function where(string $column, ?string $ownColumn = null): ?string
    {
        $sql = self::sql($column, $ownColumn);

        return $sql === '' ? null : substr($sql, strlen(' AND '));
    }

    /** A guest reads and comments; changing the work is for the team. */
    public static function isGuest(): bool
    {
        return (Auth::user()['role'] ?? '') === 'guest';
    }

    /** Forgets what was worked out, after a membership changed. */
    public static function reset(): void
    {
        self::$visible = false;
    }
}
