<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\HttpError;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\UserRepository;
use CantoTrack\Model\WorklogRepository;
use CantoTrack\Service\Calendar;
use CantoTrack\Service\WeekReview;
use DateTimeImmutable;

/**
 * The week through the API, the way the timesheet reads it: each day's hours
 * against what the day asks for — the person's own week, less holidays and
 * days away — where the week stands with the one approving it, and handing
 * it in. And the days away themselves, which are what make a day ask for
 * nothing.
 */
class ApiWeekController extends ApiEndpoint
{
    /** What a day away can be: the kinds the timesheet offers. */
    private const KINDS = ['vacation', 'sick', 'other'];

    /** ?week=2026-09-22 (any day of it; this week when not given) &user=3 (yours when not given) */
    public function show(): never
    {
        $this->member();
        $monday = $this->mondayOf((string) ($_GET['week'] ?? ''));
        $userId = ctype_digit((string) ($_GET['user'] ?? '')) ? (int) $_GET['user'] : (int) Auth::id();

        $this->json(['data' => $this->weekData($userId, $monday)]);
    }

    /**
     * {"week": "2026-09-21"} — hands one's own week in, for an administrator
     * to approve. From then on its hours do not change, unless it is sent
     * back. One handed in already, or one not begun, is 422.
     */
    public function submit(): never
    {
        $this->member();
        $monday = $this->mondayOf((string) ($this->body()['week'] ?? ''));

        try {
            (new WeekReview())->submit((int) Auth::id(), $monday);
        } catch (ValidationError $e) {
            throw new HttpError(422, $e->getMessage());
        }

        $this->json(['data' => $this->weekData((int) Auth::id(), $monday)]);
    }

    /**
     * {"starts_on": "2026-10-12", "ends_on": "2026-10-16", "kind": "vacation", "note": "…"}
     * — days away of one's own (anybody's, as an administrator, with "user").
     * Those days ask for no hours.
     */
    public function addAbsence(): never
    {
        $this->member();
        $input = $this->body();
        $userId = isset($input['user']) && ctype_digit((string) $input['user']) ? (int) $input['user'] : (int) Auth::id();

        if ($userId !== (int) Auth::id() && !Auth::isAdmin()) {
            $this->forbidden(__('Only your own days away, or anybody’s as an administrator.'));
        }

        $kind = (string) ($input['kind'] ?? 'vacation');
        if (!in_array($kind, self::KINDS, true)) {
            throw new HttpError(422, __('A day away is vacation, sick or other.'));
        }

        $calendar = new Calendar();

        try {
            $id = $calendar->addAbsence($userId, (string) ($input['starts_on'] ?? ''), (string) ($input['ends_on'] ?? ''), $kind, (string) ($input['note'] ?? ''));
        } catch (ValidationError $e) {
            throw new HttpError(422, $e->getMessage());
        }

        $this->json(['data' => self::absenceData((array) $calendar->findAbsence($id))], 201);
    }

    public function deleteAbsence(int $id): never
    {
        $this->member();
        $calendar = new Calendar();
        $absence = $calendar->findAbsence($id);

        if ($absence === null) {
            $this->notFound(__('There is no such absence.'));
        }

        if ((int) $absence['user_id'] !== (int) Auth::id() && !Auth::isAdmin()) {
            $this->forbidden(__('Only your own days away, or anybody’s as an administrator.'));
        }

        $calendar->removeAbsence($id);

        $this->noContent();
    }

    /**
     * The week: each day, the totals, and where it stands.
     *
     * "expected_to_date_minutes" stops at today, as the team's week does —
     * Thursday's hours are not missing on a Tuesday.
     */
    private function weekData(int $userId, string $monday): array
    {
        $person = (new UserRepository())->find($userId);

        if ($person === null) {
            $this->notFound(__('There is no such person.'));
        }

        $sunday = (new DateTimeImmutable($monday))->modify('+6 days')->format('Y-m-d');
        $today = date('Y-m-d');
        $calendar = new Calendar();
        $logged = [];

        foreach ((new WorklogRepository())->forRange($userId, $monday, $sunday) as $entry) {
            $logged[(string) $entry['work_date']] = ($logged[(string) $entry['work_date']] ?? 0) + (int) $entry['minutes'];
        }

        $days = [];
        foreach ($calendar->days($person, $monday, $sunday) as $date => $day) {
            $days[] = [
                'date' => $date,
                'expected_minutes' => $day['expected'],
                'logged_minutes' => $logged[$date] ?? 0,
                'holiday' => $day['holiday'],
                'absence' => $day['absence'],
            ];
        }

        $state = $calendar->weekState($userId, $monday);
        $reviewer = $state !== null && $state['reviewed_by'] !== null ? (new UserRepository())->find((int) $state['reviewed_by']) : null;

        return [
            'monday' => $monday,
            'sunday' => $sunday,
            'user' => ['id' => (int) $person['id'], 'name' => $person['name']],
            'days' => $days,
            'logged_minutes' => array_sum(array_column($days, 'logged_minutes')),
            'expected_minutes' => array_sum(array_column($days, 'expected_minutes')),
            'expected_to_date_minutes' => array_sum(array_map(
                static fn(array $d): int => $d['date'] <= $today ? $d['expected_minutes'] : 0,
                $days
            )),
            'state' => $state === null ? null : [
                'state' => $state['state'],
                'submitted_at' => $state['submitted_at'],
                'reviewed_at' => $state['reviewed_at'],
                'reviewer' => $reviewer === null ? null : ['id' => (int) $reviewer['id'], 'name' => $reviewer['name']],
                'comment' => $state['comment'],
            ],
            'can_submit' => $userId === (int) Auth::id() && $monday <= $today && ($state === null || $state['state'] === 'rejected'),
            'locked_until' => $calendar->lockedUntil() ?: null,
            'absences' => array_map(self::absenceData(...), $calendar->absences($userId, $monday, $sunday)),
        ];
    }

    private static function absenceData(array $absence): array
    {
        return [
            'id' => (int) $absence['id'],
            'starts_on' => $absence['starts_on'],
            'ends_on' => $absence['ends_on'],
            'kind' => $absence['kind'],
            'note' => $absence['note'],
        ];
    }

    /** The Monday of the week a day is in; this week's when none is given. */
    private function mondayOf(string $given): string
    {
        $day = $this->date($given, date('Y-m-d'));

        return (new DateTimeImmutable($day))->modify('monday this week')->format('Y-m-d');
    }
}
