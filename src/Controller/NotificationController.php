<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Config;
use CantoTrack\Core\Controller;
use CantoTrack\Core\View;
use CantoTrack\Model\NotificationRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\NotifySettings;

/**
 * What somebody has been told about: the page of it, the bell's menu, what
 * the bell asks for every minute, and following a ticket or not.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): void
    {
        Auth::require();

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $kind = in_array($_GET['kind'] ?? '', NotifySettings::KINDS, true) ? (string) $_GET['kind'] : null;
        $project = ctype_digit((string) ($_GET['project'] ?? '')) ? (int) $_GET['project'] : null;
        $filters = ['unread' => ($_GET['unread'] ?? '') === '1', 'kind' => $kind, 'project_id' => $project];

        $notifications = new NotificationRepository();
        $me = (int) Auth::id();
        $found = $notifications->forUser($me, self::PER_PAGE, ($page - 1) * self::PER_PAGE, $filters);

        $this->render('notifications/index.twig', [
            'groups' => self::grouped($found),
            'more' => count($found) === self::PER_PAGE,
            'unread' => $notifications->unreadCount($me),
            'page' => $page,
            'filters' => $filters,
            'narrowed' => $filters['unread'] || $kind !== null || $project !== null,
            'kinds' => NotifySettings::KINDS,
            'projects' => $notifications->projectsOf($me),
            // The page's own address with its filters, for the pager.
            'query' => http_build_query(array_filter(['unread' => $filters['unread'] ? '1' : null, 'kind' => $kind, 'project' => $project])),
        ]);
    }

    /** The bell's menu: the newest few, drawn when it is opened. */
    public function menu(): void
    {
        Auth::require();

        $notifications = new NotificationRepository();
        $me = (int) Auth::id();

        header('Cache-Control: no-store');
        echo View::twig()->render('notifications/menu.twig', [
            'notifications' => $notifications->forUser($me, 8),
            'unread' => $notifications->unreadCount($me),
        ]);
    }

    /**
     * What the bell asks for every minute: how many are unread now, and the
     * ones that came after the newest it knows of — each in words, for the
     * note that pops up.
     */
    public function poll(): void
    {
        Auth::require();

        $notifications = new NotificationRepository();
        $me = (int) Auth::id();
        $after = ctype_digit((string) ($_GET['after'] ?? '')) ? (int) $_GET['after'] : $notifications->latestId($me);
        $twig = View::twig();
        $fresh = [];

        foreach ($notifications->unreadSince($me, $after) as $n) {
            $html = $twig->render('notifications/toast.twig', ['n' => $n]);
            $fresh[] = [
                'id' => (int) $n['id'],
                'html' => $html,
                'title' => ($n['epic_id'] !== null ? (string) $n['project_code'] : $n['project_code'] . '-' . $n['ticket_number'])
                    . ' · ' . ($n['epic_id'] !== null ? $n['epic_title'] : $n['ticket_title']),
                'text' => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($twig->render('notifications/toast_text.twig', ['n' => $n])), ENT_QUOTES | ENT_HTML5))),
                'url' => rtrim((string) Config::get('app.base_url', ''), '/') . '/notifications/' . $n['id'],
            ];
        }

        $this->json([
            'unread' => $notifications->unreadCount($me),
            'latest' => max($after, $notifications->latestId($me)),
            'fresh' => $fresh,
        ]);
    }

    /** Opening one: it is read now, and it leads to what it was about. */
    public function open(int $id): void
    {
        Auth::require();

        $notifications = new NotificationRepository();
        $notification = $this->mine($notifications, $id);

        $notifications->markRead($id);

        if ($notification['epic_id'] !== null) {
            $this->redirect('/epics/' . $notification['epic_id'] . ($notification['kind'] === 'commented' ? '#activity' : ''));
        }

        $this->redirect('/tickets/' . $notification['ticket_id'] . ($notification['kind'] === 'commented' ? '#activity' : ''));
    }

    /** One marked read, without going to it. */
    public function read(int $id): void
    {
        Auth::require();

        $notifications = new NotificationRepository();
        $this->mine($notifications, $id);
        $notifications->markRead($id);

        $this->answer();
    }

    /** One marked unread again: something to come back to. */
    public function unread(int $id): void
    {
        Auth::require();

        $notifications = new NotificationRepository();
        $this->mine($notifications, $id);
        $notifications->markUnread($id);

        $this->answer();
    }

    /** Everything read — or everything about one ticket or epic. */
    public function readAll(): void
    {
        Auth::require();

        $notifications = new NotificationRepository();
        $me = (int) Auth::id();

        if (($ticket = $this->idInput('ticket')) !== null) {
            $notifications->markTicketRead($me, $ticket);
        } elseif (($epic = $this->idInput('epic')) !== null) {
            $notifications->markEpicRead($me, $epic);
        } else {
            $notifications->markAllRead($me);
            $this->flash(__('Everything is marked as read.'));
        }

        $this->answer();
    }

    public function toggleWatch(int $ticketId): void
    {
        Auth::require();

        if ((new TicketRepository())->find($ticketId) === null) {
            $this->notFound(__('There is no such ticket.'));
        }

        $notifications = new NotificationRepository();
        $me = (int) Auth::id();

        if ($notifications->isWatching($ticketId, $me)) {
            $notifications->unwatch($ticketId, $me);
            $this->flash(__('You no longer hear about this ticket’s changes. Being given it or mentioned in it still reaches you.'));
        } else {
            $notifications->watch($ticketId, $me);
            $this->flash(__('You follow this ticket now: you hear about its changes and comments.'));
        }

        $this->redirect('/tickets/' . $ticketId);
    }

    public function toggleEpicWatch(int $epicId): void
    {
        Auth::require();

        $epics = new \CantoTrack\Model\EpicRepository();

        if ($epics->find($epicId) === null) {
            $this->notFound(__('There is no such epic.'));
        }

        $me = (int) Auth::id();

        if ($epics->isWatching($epicId, $me)) {
            $epics->unwatch($epicId, $me);
            $this->flash(__('You no longer follow this epic.'));
        } else {
            $epics->watch($epicId, $me);
            $this->flash(__('You follow this epic now: you hear about its comments and when it is finished.'));
        }

        $this->redirect('/epics/' . $epicId);
    }

    /**
     * The page's rows, one after another about the same ticket or epic put
     * together: the newest shown, the rest under it — six comments on one
     * ticket in an afternoon are one thing to look at, not six.
     *
     * @param array<int, array> $found newest first
     * @return list<array{first: array, rest: list<array>, unread: int}>
     */
    public static function grouped(array $found): array
    {
        $groups = [];

        foreach ($found as $n) {
            $about = $n['epic_id'] !== null ? 'e' . $n['epic_id'] : 't' . $n['ticket_id'];
            $last = count($groups) - 1;

            if ($last >= 0 && $groups[$last]['about'] === $about) {
                $groups[$last]['rest'][] = $n;
            } else {
                $groups[] = ['about' => $about, 'first' => $n, 'rest' => [], 'unread' => 0];
                $last++;
            }

            $groups[$last]['unread'] += $n['read_at'] === null ? 1 : 0;
        }

        return array_map(static fn(array $g): array => [
            'first' => $g['first'],
            'rest' => $g['rest'],
            'unread' => $g['unread'],
        ], $groups);
    }

    /** One of the signed-in person's own, or "not found". */
    private function mine(NotificationRepository $notifications, int $id): array
    {
        $notification = $notifications->find($id);

        if ($notification === null || (int) $notification['user_id'] !== (int) Auth::id()) {
            $this->notFound(__('There is no such notification.'));
        }

        return $notification;
    }

    /** Asked for by the bell's script: the new count. Otherwise back to where it was done. */
    private function answer(): never
    {
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            $this->json(['ok' => true, 'unread' => (new NotificationRepository())->unreadCount((int) Auth::id())]);
        }

        $this->back('/notifications');
    }
}
