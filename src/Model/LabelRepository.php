<?php

namespace CantoTrack\Model;

use CantoTrack\Core\DatabaseConnection;
use PDO;

/**
 * Labels: words that cut across projects and epics — "security", "customer",
 * "tech debt".
 *
 * There is no page for making them. A label comes into being the first time
 * somebody types it on a ticket and is offered to everybody after that, which
 * is how they get used; a list an administrator has to maintain first is a
 * list that stays empty.
 */
class LabelRepository
{
    public const MAX_LENGTH = 40;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** Every label, with how many tickets carry it, most used first. */
    public function all(): array
    {
        $statement = $this->db->prepare(
            'SELECT l.*, COUNT(tl.ticket_id) AS ticket_count
             FROM labels l
             LEFT JOIN ticket_labels tl ON tl.label_id = l.id
             GROUP BY l.id
             ORDER BY ticket_count DESC, l.name'
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    public function findByName(string $name): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM labels WHERE name = :name');
        $statement->execute(['name' => $name]);

        return $statement->fetch() ?: null;
    }

    /** @return list<string> the ticket's labels, by name */
    public function forTicket(int $ticketId): array
    {
        $statement = $this->db->prepare(
            'SELECT l.name FROM ticket_labels tl JOIN labels l ON l.id = tl.label_id
             WHERE tl.ticket_id = :ticket ORDER BY l.name'
        );
        $statement->execute(['ticket' => $ticketId]);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Gives a ticket exactly these labels, making any that do not exist yet.
     *
     * The comparison ignores case (the collation does), so "Security" typed on
     * one ticket and "security" on the next are the same label, spelled the
     * way it was first typed.
     *
     * @param list<string> $names
     */
    public function sync(int $ticketId, array $names): void
    {
        $ids = [];

        foreach ($names as $name) {
            $existing = $this->findByName($name);

            if ($existing === null) {
                $this->db->prepare('INSERT INTO labels (name) VALUES (:name)')->execute(['name' => $name]);
                $ids[] = (int) $this->db->lastInsertId();
            } else {
                $ids[] = (int) $existing['id'];
            }
        }

        $this->db->prepare('DELETE FROM ticket_labels WHERE ticket_id = :ticket')->execute(['ticket' => $ticketId]);

        $insert = $this->db->prepare('INSERT IGNORE INTO ticket_labels (ticket_id, label_id) VALUES (:ticket, :label)');
        foreach (array_unique($ids) as $id) {
            $insert->execute(['ticket' => $ticketId, 'label' => $id]);
        }
    }

    /**
     * What was typed into a labels field, as a clean list: split on commas,
     * trimmed, cut to length, without repeats.
     *
     * @return list<string>
     */
    public static function parse(mixed $given): array
    {
        $parts = is_array($given) ? $given : explode(',', (string) $given);
        $names = [];

        foreach ($parts as $part) {
            $name = trim(preg_replace('/\s+/', ' ', (string) $part) ?? '');
            $name = mb_substr($name, 0, self::MAX_LENGTH);

            if ($name !== '' && !in_array(mb_strtolower($name), array_map('mb_strtolower', $names), true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The colour a label is drawn in, the same every time for the same word —
     * picked from the name rather than stored, so there is nothing to choose
     * and nothing to keep in step.
     */
    public static function colour(string $name): string
    {
        $colours = StatusRepository::COLOURS;

        return $colours[crc32(mb_strtolower($name)) % count($colours)];
    }
}
