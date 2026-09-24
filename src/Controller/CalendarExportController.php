<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Config;
use CantoTrack\Core\Controller;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\CalendarExport;

/**
 * The private calendar address itself: asked for by a calendar, not a
 * browser, with no session — the address is the key (see CalendarExport).
 */
class CalendarExportController extends Controller
{
    public function feed(string $secret): void
    {
        $secret = preg_replace('/\.ics$/', '', $secret) ?? '';
        $user = preg_match('/^[a-f0-9]{48}$/', $secret) === 1
            ? (new UserRepository())->findByCalendarExport(hash('sha256', $secret))
            : null;

        if ($user === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "There is no such calendar.\n";
            exit;
        }

        // As the person, for this request only: their projects, their words.
        Auth::actAs($user);

        $host = (string) (parse_url((string) Config::get('app.base_url'), PHP_URL_HOST) ?: 'cantotrack');
        $name = __('{app} — {name}', ['app' => (string) Config::get('app.name', 'CantoTrack'), 'name' => (string) $user['name']]);

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="cantotrack.ics"');
        header('Cache-Control: private, max-age=900');
        header('X-Robots-Tag: noindex');

        echo CalendarExport::ics((new CalendarExport())->events((int) $user['id']), $name, $host, gmdate('Ymd\THis\Z'));
        exit;
    }
}
