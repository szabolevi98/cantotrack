<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Format;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\SettingRepository;
use PDO;

/**
 * Which days are working days, for whom, and for how long — and which days'
 * hours can no longer change.
 *
 * A day's expected time is the person's own week for that weekday, unless the
 * day is a holiday or they were away: then nothing is expected, and the
 * timesheet says why instead of showing a short day.
 */
class Calendar
{
    public const LOCK_SETTING = 'hours_locked_until';

    private PDO $db;

    /** @var array<string, string>|null day => holiday name, read once */
    private ?array $holidays = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** @return list<int> minutes per weekday, Monday first */
    public static function week(array $user): array
    {
        $given = trim((string) ($user['working_week'] ?? ''));
        $day = Format::minutesPerDay();

        if ($given !== '') {
            $parts = array_map('intval', explode(',', $given));

            if (count($parts) === 7) {
                return array_map(static fn(int $m): int => max(0, min(1440, $m)), $parts);
            }
        }

        return [$day, $day, $day, $day, $day, 0, 0];
    }

    /**
     * Each day of a span as the timesheet reads it: what is expected, and why
     * less (a holiday, an absence).
     *
     * @return array<string, array{expected: int, holiday: ?string, absence: ?string}>
     */
    public function days(array $user, string $from, string $to): array
    {
        $week = self::week($user);
        $absences = $this->absences((int) $user['id'], $from, $to);
        $days = [];

        for ($day = new \DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $holiday = $this->holiday($date);
            $absence = null;

            foreach ($absences as $away) {
                if ($away['starts_on'] <= $date && $away['ends_on'] >= $date) {
                    $absence = (string) $away['kind'];
                }
            }

            $days[$date] = [
                'expected' => $holiday !== null || $absence !== null ? 0 : $week[(int) $day->format('N') - 1],
                'holiday' => $holiday,
                'absence' => $absence,
            ];
        }

        return $days;
    }

    public function holiday(string $date): ?string
    {
        if ($this->holidays === null) {
            $statement = $this->db->prepare('SELECT day, name FROM holidays');
            $statement->execute();
            $this->holidays = [];

            foreach ($statement->fetchAll() as $row) {
                $this->holidays[(string) $row['day']] = (string) $row['name'];
            }
        }

        return $this->holidays[$date] ?? null;
    }

    public function holidays(int $year): array
    {
        $statement = $this->db->prepare('SELECT * FROM holidays WHERE day BETWEEN :from AND :to ORDER BY day');
        $statement->execute(['from' => $year . '-01-01', 'to' => $year . '-12-31']);

        return $statement->fetchAll();
    }

    public function addHoliday(string $date, string $name): void
    {
        $this->db->prepare('INSERT INTO holidays (day, name) VALUES (:day, :name) ON DUPLICATE KEY UPDATE name = VALUES(name)')
            ->execute(['day' => $date, 'name' => mb_substr(trim($name), 0, 120)]);
        $this->holidays = null;
    }

    public function removeHoliday(string $date): void
    {
        $this->db->prepare('DELETE FROM holidays WHERE day = :day')->execute(['day' => $date]);
        $this->holidays = null;
    }

    /**
     * Hungary's public holidays in a year: the fixed ones, and the four that
     * move with Easter. Added to the calendar by an administrator with one
     * click, and changeable after — a company's own days off are its business.
     *
     * @return array<string, string>
     */
    public static function hungarianHolidays(int $year): array
    {
        $easter = self::easter($year);

        $days = [
            $year . '-01-01' => 'Újév',
            $year . '-03-15' => 'Nemzeti ünnep',
            $easter->modify('-2 days')->format('Y-m-d') => 'Nagypéntek',
            $easter->format('Y-m-d') => 'Húsvétvasárnap',
            $easter->modify('+1 day')->format('Y-m-d') => 'Húsvéthétfő',
            $year . '-05-01' => 'A munka ünnepe',
            $easter->modify('+49 days')->format('Y-m-d') => 'Pünkösdvasárnap',
            $easter->modify('+50 days')->format('Y-m-d') => 'Pünkösdhétfő',
            $year . '-08-20' => 'Az államalapítás ünnepe',
            $year . '-10-23' => 'Nemzeti ünnep',
            $year . '-11-01' => 'Mindenszentek',
            $year . '-12-24' => 'Szenteste',
            $year . '-12-25' => 'Karácsony',
            $year . '-12-26' => 'Karácsony másnapja',
        ];

        ksort($days);

        return $days;
    }

    /**
     * Easter Sunday, by the anonymous Gregorian algorithm — worked out here
     * rather than with easter_date(), which needs an extension a server may
     * not have.
     */
    public static function easter(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    // -----------------------------------------------------------------------
    // Absences
    // -----------------------------------------------------------------------

    public function absences(int $userId, string $from, string $to): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM absences WHERE user_id = :user AND starts_on <= :to AND ends_on >= :from ORDER BY starts_on'
        );
        $statement->execute(['user' => $userId, 'from' => $from, 'to' => $to]);

        return $statement->fetchAll();
    }

    /** @throws ValidationError */
    public function addAbsence(int $userId, string $from, string $to, string $kind, string $note): void
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to ?: $from);

        if ($start === false || $end === false || $end < $start) {
            throw new ValidationError(__('An absence needs a first and a last day, in that order.'));
        }

        $this->db->prepare(
            'INSERT INTO absences (user_id, starts_on, ends_on, kind, note) VALUES (:user, :from, :to, :kind, :note)'
        )->execute([
            'user' => $userId,
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
            'kind' => in_array($kind, ['vacation', 'sick', 'other'], true) ? $kind : 'vacation',
            'note' => mb_substr(trim($note), 0, 200) ?: null,
        ]);
    }

    public function findAbsence(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM absences WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetch() ?: null;
    }

    public function removeAbsence(int $id): void
    {
        $this->db->prepare('DELETE FROM absences WHERE id = :id')->execute(['id' => $id]);
    }

    // -----------------------------------------------------------------------
    // Closed hours
    // -----------------------------------------------------------------------

    /** The last day whose hours are closed for everybody, or null. */
    public function lockedUntil(): ?string
    {
        return (new SettingRepository($this->db))->get(self::LOCK_SETTING);
    }

    /** A handed-in week of a person, or null. */
    public function weekState(int $userId, string $date): ?array
    {
        $monday = (new \DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d');
        $statement = $this->db->prepare('SELECT * FROM timesheet_weeks WHERE user_id = :user AND week_start = :week');
        $statement->execute(['user' => $userId, 'week' => $monday]);

        return $statement->fetch() ?: null;
    }

    /**
     * Refuses a change to a person's hours on a day that is closed: before
     * the lock date, or in a week they handed in or had approved.
     *
     * @throws ValidationError
     */
    public function ensureOpen(int $userId, string $date): void
    {
        $locked = $this->lockedUntil();

        if ($locked !== null && $locked !== '' && $date <= $locked) {
            throw new ValidationError(__('Hours up to {day} are closed and no longer change.', ['day' => Format::day($locked)]));
        }

        $week = $this->weekState($userId, $date);

        if ($week !== null && in_array($week['state'], ['submitted', 'approved'], true)) {
            throw new ValidationError($week['state'] === 'approved'
                ? __('That week has been approved; its hours no longer change.')
                : __('That week has been handed in. It opens again if it is sent back.'));
        }
    }
}
