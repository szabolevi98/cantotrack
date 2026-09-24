<?php

namespace CantoTrack\Service;

use CantoTrack\Core\Access;
use CantoTrack\Core\Config;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\I18n;
use CantoTrack\Core\Logger;
use CantoTrack\Core\Mailer;
use CantoTrack\Core\Markdown;
use CantoTrack\Core\View;
use CantoTrack\Model\NotificationRepository;
use CantoTrack\Model\UserRepository;
use PDO;

/**
 * Who hears about what.
 *
 * It listens to everything that happens to tickets (see Activity) and decides,
 * for each thing, who should be told: the people who follow the ticket — its
 * reporter, its assignee, its watchers — about the changes that matter; the
 * new assignee about being given it; anybody @mentioned in a comment about
 * that. Nobody is told about what they did themselves.
 *
 * What does not reach anybody is as deliberate as what does: hours logged,
 * labels, points and estimates are part of the history, and would be noise in
 * an inbox.
 */
class Notifier
{
    /** The changes worth telling the ticket's followers about. */
    private const TOLD = ['status', 'commented', 'attached', 'linked', 'created'];

    private const TOLD_FIELDS = ['assignee', 'priority', 'due', 'title'];

    private NotificationRepository $notifications;
    private UserRepository $users;

    public function __construct(?PDO $db = null)
    {
        $db ??= DatabaseConnection::get();

        $this->notifications = new NotificationRepository($db);
        $this->users = new UserRepository($db);
    }

    /** Starts listening. Called once, when the application starts. */
    public static function register(): void
    {
        Activity::listen(static function (array $ticket, ?int $actorId, string $kind, ?string $field, ?string $old, ?string $new): void {
            try {
                (new self())->handle($ticket, $actorId, $kind, $field, $old, $new);
            } catch (\Throwable $e) {
                // A notification that cannot be written must not undo the
                // change it was about.
                Logger::error('A notification could not be made: ' . $e->getMessage());
            }
        });
    }

    public function handle(array $ticket, ?int $actorId, string $kind, ?string $field, ?string $old, ?string $new): void
    {
        $ticketId = (int) $ticket['id'];
        $told = [];

        // Taking part is following: whoever comments on a ticket, attaches to
        // it or creates it hears about it from then on.
        if ($actorId !== null && in_array($kind, ['commented', 'attached', 'created'], true)) {
            $this->notifications->watch($ticketId, $actorId);
        }

        // Mentioned in a comment: told, whether they followed the ticket or
        // not, and following it from now on.
        if ($kind === 'commented') {
            foreach (Markdown::mentions($new) as $userId) {
                if ($userId !== $actorId && $this->users->findActive($userId) !== null) {
                    $this->notifications->watch($ticketId, $userId);
                    $told[$userId] = 'mentioned';
                }
            }
        }

        // Given the ticket: told as the new assignee, not as one follower
        // among many.
        if ($kind === 'changed' && $field === 'assignee' && !empty($ticket['assignee_id'])) {
            $assignee = (int) $ticket['assignee_id'];
            $this->notifications->watch($ticketId, $assignee);

            if ($assignee !== $actorId) {
                $told[$assignee] = 'assigned';
            }
        }

        if ($kind === 'created' && !empty($ticket['assignee_id']) && (int) $ticket['assignee_id'] !== $actorId) {
            $told[(int) $ticket['assignee_id']] = 'assigned';
        }

        $relevant = in_array($kind, self::TOLD, true) && $kind !== 'created'
            || ($kind === 'changed' && in_array($field, self::TOLD_FIELDS, true));

        if ($relevant) {
            foreach ($this->notifications->audience($ticketId) as $person) {
                $id = (int) $person['id'];

                if ($id !== $actorId && !isset($told[$id])) {
                    $told[$id] = 'watching';
                }
            }
        }

        foreach ($told as $userId => $reason) {
            // Nobody hears about a ticket in a project they cannot see — a
            // watcher taken off a private project, a guest @mentioned in one
            // they were never added to.
            $person = $this->users->find($userId);
            $visible = $person === null ? [] : Access::forUser($person);

            if ($visible !== null && !in_array((int) ($ticket['project_id'] ?? 0), $visible, true)) {
                continue;
            }

            // And each hears about it the way they chose, or not at all.
            $way = NotifySettings::way((array) $person, $reason, $kind);
            if ($way === 'off') {
                continue;
            }

            $notificationId = $this->notifications->create([
                'user_id' => $userId,
                'ticket_id' => $ticketId,
                'actor_id' => $actorId,
                'reason' => $reason,
                'kind' => $kind,
                'field' => $field,
                'old_value' => $old,
                'new_value' => $new,
            ]);

            if ($way === 'email') {
                $this->email($notificationId, $userId, $ticket);
            }
        }
    }

    /**
     * The same notification by email, to anybody who has not turned that off
     * — written in their own language, not in the language of whoever made
     * the change.
     */
    private function email(int $notificationId, int $userId, array $ticket): void
    {
        $person = $this->users->find($userId);

        if ($person === null || !Mailer::isConfigured()) {
            return;
        }

        $notification = $this->notifications->forUser($userId, 1)[0] ?? null;
        if ($notification === null || (int) $notification['id'] !== $notificationId) {
            return;
        }

        $previous = I18n::locale();
        I18n::setLocale((string) ($person['locale'] ?: Config::get('app.locale', 'en')));

        try {
            $key = $ticket['project_code'] . '-' . $ticket['number'];
            $text = View::twig()->render('emails/notification.txt.twig', [
                'notification' => $notification,
                'ticket' => $ticket,
                'key' => $key,
                'person' => $person,
                'link' => rtrim((string) Config::get('app.base_url'), '/') . '/tickets/' . $ticket['id'],
            ]);

            if (Mailer::send((string) $person['email'], (string) $person['name'], '[' . $key . '] ' . $ticket['title'], $text)) {
                $this->notifications->markEmailed($notificationId);
            }
        } finally {
            I18n::setLocale($previous);
        }
    }
}
