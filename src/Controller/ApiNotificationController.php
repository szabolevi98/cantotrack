<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\View;
use CantoTrack\Model\NotificationRepository;
use CantoTrack\Service\Presenter;

/**
 * The bell, through the API: what you were told about, newest first, and
 * marking it read — for an app that wants to show the same list the web
 * does. Each carries a sentence in your own language, the one the bell and
 * the browser's own notification say.
 */
class ApiNotificationController extends ApiEndpoint
{
    /** ?unread=1&page=2&per_page=50 */
    public function index(): never
    {
        $notifications = new NotificationRepository();
        $me = (int) Auth::id();
        $filters = empty($_GET['unread']) ? [] : ['unread' => true];
        [$page, $perPage, $offset] = $this->paging();
        $total = $notifications->countForUser($me, $filters);

        $this->json([
            'data' => array_map(fn(array $n): array => $this->notificationData($n), $notifications->forUser($me, $perPage, $offset, $filters)),
            'meta' => self::pageMeta($page, $perPage, $total) + ['unread' => $notifications->unreadCount($me)],
        ]);
    }

    public function read(int $id): never
    {
        $notifications = new NotificationRepository();
        $notification = $notifications->find($id);

        if ($notification === null || (int) $notification['user_id'] !== (int) Auth::id()) {
            $this->notFound(__('There is no such notification.'));
        }

        $notifications->markRead($id);

        $this->noContent();
    }

    /** Everything read at once. */
    public function readAll(): never
    {
        (new NotificationRepository())->markAllRead((int) Auth::id());

        $this->noContent();
    }

    private function notificationData(array $n): array
    {
        $epic = $n['epic_id'] !== null;
        $text = View::twig()->render('notifications/toast_text.twig', ['n' => $n]);

        return [
            'id' => (int) $n['id'],
            // assigned, mentioned, status, commented or changes: what the
            // profile's notification settings are chosen by.
            'kind' => $n['told_kind'],
            'reason' => $n['reason'],
            'read' => $n['read_at'] !== null,
            'text' => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5))),
            'actor' => $n['actor_id'] === null ? null : ['id' => (int) $n['actor_id'], 'name' => $n['user_name']],
            'ticket' => $epic ? null : ['key' => $n['project_code'] . '-' . $n['ticket_number'], 'title' => $n['ticket_title']],
            'epic' => $epic ? ['id' => (int) $n['epic_id'], 'title' => $n['epic_title']] : null,
            'project' => $n['project_code'],
            'created_at' => $n['created_at'],
            'url' => Presenter::url($epic ? '/epics/' . $n['epic_id'] : '/t/' . $n['project_code'] . '-' . $n['ticket_number']),
        ];
    }
}
