<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Session;
use CantoTrack\Core\ValidationError;
use PDO;

/**
 * Taking a delete back, for a few minutes after it: the hours deleted by a
 * slip of the finger, a comment removed from the wrong ticket.
 *
 * The row as it was is kept in the person's own session — never in the
 * page, where it could be changed — and put back with the id it had, so
 * every link to it works again. Only rows of the kinds listed here, only
 * for whoever deleted them, and only for ten minutes.
 */
final class Undo
{
    private const SESSION = '_undo';

    private const SECONDS = 600;

    /** What can be brought back: the table, and what it is called. */
    private const KINDS = ['worklogs' => 'hours', 'comments' => 'comment', 'epic_comments' => 'comment'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /**
     * Keeps a row about to be deleted, to offer it back. Called before the
     * delete, while the row is still there to be read.
     */
    public function keep(string $table, int $id, int $userId, string $back): void
    {
        if (!isset(self::KINDS[$table])) {
            return;
        }

        $statement = $this->db->prepare('SELECT * FROM `' . $table . '` WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if ($row !== false) {
            Session::put(self::SESSION, ['table' => $table, 'row' => $row, 'user' => $userId, 'at' => time(), 'back' => $back, 'shown' => false]);
        }
    }

    /**
     * What to offer on the page drawn next, once: its words, or null.
     *
     * @return array{label: string, back: string}|null
     */
    public static function offer(?int $userId): ?array
    {
        $kept = Session::get(self::SESSION);

        if (!is_array($kept) || $kept['shown'] || $userId === null || (int) $kept['user'] !== $userId || time() - (int) $kept['at'] > self::SECONDS) {
            return null;
        }

        $kept['shown'] = true;
        Session::put(self::SESSION, $kept);

        return [
            'label' => self::KINDS[$kept['table']] === 'hours' ? __('The hours are deleted.') : __('The comment is deleted.'),
            'back' => (string) $kept['back'],
        ];
    }

    /**
     * Puts the kept row back. Returns where to go to see it.
     *
     * @throws ValidationError when there is nothing to bring back any more
     */
    public function restore(int $userId): string
    {
        $kept = Session::get(self::SESSION);
        Session::forget(self::SESSION);

        if (!is_array($kept) || (int) $kept['user'] !== $userId || time() - (int) $kept['at'] > self::SECONDS || !isset(self::KINDS[$kept['table']])) {
            throw new ValidationError(__('There is nothing to take back any more.'));
        }

        $table = (string) $kept['table'];
        /** @var array<string, mixed> $row */
        $row = $kept['row'];

        // Hours still keep to the calendar: a week handed in since, or a
        // month closed, takes no hours back either.
        if ($table === 'worklogs') {
            (new Calendar($this->db))->ensureOpen((int) $row['user_id'], (string) $row['work_date']);
        }

        $columns = array_keys($row);
        foreach ($columns as $column) {
            if (preg_match('/^[a-z_]+$/', (string) $column) !== 1) {
                throw new ValidationError(__('There is nothing to take back any more.'));
            }
        }

        $this->db->prepare(
            'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES (:' . implode(', :', $columns) . ')'
        )->execute($row);

        return (string) $kept['back'];
    }
}
