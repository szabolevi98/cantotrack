<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Format;
use CantoTrack\Model\SettingRepository;
use CantoTrack\Service\Calendar;

/**
 * The workspace's calendar, for administrators: the public holidays, and the
 * day up to which hours are closed.
 *
 * Closing hours is what happens once a month has been invoiced or paid out —
 * after that, a changed entry is a changed invoice, and nobody should be able
 * to make one by accident.
 */
class SettingsController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $year = (int) ($_GET['year'] ?? 0) ?: (int) date('Y');
        $year = max(2000, min(2100, $year));
        $calendar = new Calendar();

        $this->render('settings/index.twig', [
            'year' => $year,
            'holidays' => $calendar->holidays($year),
            'suggested' => Calendar::hungarianHolidays($year),
            'locked_until' => $calendar->lockedUntil(),
        ]);
    }

    public function lock(): void
    {
        Auth::requireAdmin();

        $given = $this->input('locked_until');

        if ($given !== '' && \DateTimeImmutable::createFromFormat('!Y-m-d', $given) === false) {
            $this->flash(__('That is not a date.'), 'danger');
            $this->back('/settings');
        }

        (new SettingRepository())->set(Calendar::LOCK_SETTING, $given === '' ? null : $given);

        $this->flash($given === ''
            ? __('Hours are open again, apart from handed-in weeks.')
            : __('Hours up to {day} are closed.', ['day' => Format::day($given)]));
        $this->back('/settings');
    }

    public function addHoliday(): void
    {
        Auth::requireAdmin();

        $day = $this->input('day');
        $name = $this->input('name');

        if (\DateTimeImmutable::createFromFormat('!Y-m-d', $day) === false || $name === '') {
            $this->flash(__('A holiday needs a day and a name.'), 'danger');
            $this->back('/settings');
        }

        (new Calendar())->addHoliday($day, $name);

        $this->flash(__('{day} is a holiday now.', ['day' => Format::day($day)]));
        $this->back('/settings?year=' . substr($day, 0, 4));
    }

    /** A year's national holidays, all at once; ones already there keep their names. */
    public function addNational(): void
    {
        Auth::requireAdmin();

        $year = max(2000, min(2100, (int) $this->input('year')));
        $calendar = new Calendar();
        $have = array_column($calendar->holidays($year), 'day');
        $added = 0;

        foreach (Calendar::hungarianHolidays($year) as $day => $name) {
            if (!in_array($day, $have, true)) {
                $calendar->addHoliday($day, $name);
                $added++;
            }
        }

        $this->flash(__n('{count} holiday added.', '{count} holidays added.', $added), $added > 0 ? 'success' : 'warning');
        $this->back('/settings?year=' . $year);
    }

    public function removeHoliday(): void
    {
        Auth::requireAdmin();

        $day = $this->input('day');
        (new Calendar())->removeHoliday($day);

        $this->flash(__('{day} is a working day again.', ['day' => Format::day($day)]), 'warning');
        $this->back('/settings?year=' . substr($day, 0, 4));
    }
}
