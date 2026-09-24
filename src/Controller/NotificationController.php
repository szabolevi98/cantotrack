<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Model\NotificationRepository;
use CantoTrack\Model\TicketRepository;

/** What somebody has been told about, and following a ticket or not. */
class NotificationController extends Controller
{
    public function index(): void
    {
        Auth::require();

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $notifications = new NotificationRepository();

        $this->render('notifications/index.twig', [
            'notifications' => $notifications->forUser((int) Auth::id(), 50, ($page - 1) * 50),
            'unread' => $notifications->unreadCount((int) Auth::id()),
            'page' => $page,
        ]);
    }

    /** Opening one: it is read now, and it leads to what it was about. */
    public function open(int $id): void
    {
        Auth::require();

        $notifications = new NotificationRepository();
        $notification = $notifications->find($id);

        if ($notification === null || (int) $notification['user_id'] !== (int) Auth::id()) {
            $this->notFound(__('There is no such notification.'));
        }

        $notifications->markRead($id);

        if ($notification['epic_id'] !== null) {
            $this->redirect('/epics/' . $notification['epic_id'] . ($notification['kind'] === 'commented' ? '#activity' : ''));
        }

        $this->redirect('/tickets/' . $notification['ticket_id'] . ($notification['kind'] === 'commented' ? '#activity' : ''));
    }

    public function readAll(): void
    {
        Auth::require();

        (new NotificationRepository())->markAllRead((int) Auth::id());

        $this->flash(__('Everything is marked as read.'));
        $this->redirect('/notifications');
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
            $this->flash(__('You no longer follow this ticket.'));
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
}
