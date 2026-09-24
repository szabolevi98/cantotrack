<?php

namespace CantoTrack\Service;

use CantoTrack\Core\Access;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Logger;
use CantoTrack\Core\Markdown;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\NotificationRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\UserRepository;
use PDO;

/**
 * An epic as a thing people talk about, not only a grouping: what changes in
 * it goes into its history, whoever makes it, comments on it or attaches to
 * it follows it, and its followers hear about what is said in it and about it
 * being finished or opened again — the way a ticket's do (see Notifier).
 *
 * Its dates and its description changing are history, not news: the roadmap
 * is dragged all day, and nobody wants an email for every day it moved.
 */
class EpicService
{
    /** What can be changed one at a time, where it is shown. */
    public const FIELDS = ['title', 'description', 'starts_on', 'ends_on'];

    private EpicRepository $epics;
    private NotificationRepository $notifications;
    private UserRepository $users;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();

        $this->epics = new EpicRepository($this->db);
        $this->notifications = new NotificationRepository($this->db);
        $this->users = new UserRepository($this->db);
    }

    /** @throws ValidationError */
    public function create(int $projectId, string $title, ?string $description, ?string $startsOn, ?string $endsOn, ?int $actorId): int
    {
        $title = self::title($title);
        self::order($startsOn, $endsOn);

        $id = $this->epics->create($projectId, $title, $description, $startsOn, $endsOn);
        $this->epics->addEvent($id, $actorId, 'created');

        if ($actorId !== null) {
            $this->epics->watch($id, $actorId);
        }

        return $id;
    }

    /**
     * Some of an epic's facts changed — from its form, from its page, or from
     * the roadmap. Only what is given changes; each change is a line of its
     * history, and finishing or reopening it is told to its followers.
     *
     * @param array{title?: string, description?: ?string, is_done?: bool, starts_on?: ?string, ends_on?: ?string} $changes
     * @throws ValidationError
     */
    public function update(int $id, array $changes, ?int $actorId): void
    {
        $epic = $this->epics->find($id);

        if ($epic === null) {
            throw new ValidationError(__('There is no such epic.'));
        }

        $title = array_key_exists('title', $changes) ? self::title((string) $changes['title']) : (string) $epic['title'];
        $description = array_key_exists('description', $changes)
            ? (trim(str_replace("\r\n", "\n", (string) $changes['description'])) ?: null)
            : $epic['description'];
        $done = array_key_exists('is_done', $changes) ? (bool) $changes['is_done'] : (bool) $epic['is_done'];
        $starts = array_key_exists('starts_on', $changes) ? $changes['starts_on'] : $epic['starts_on'];
        $ends = array_key_exists('ends_on', $changes) ? $changes['ends_on'] : $epic['ends_on'];

        self::order($starts, $ends);

        $this->epics->update($id, $title, $description, $done, $starts, $ends);

        foreach (['title' => [$epic['title'], $title], 'starts' => [$epic['starts_on'], $starts], 'ends' => [$epic['ends_on'], $ends]] as $field => [$old, $new]) {
            if ((string) $old !== (string) $new) {
                $this->epics->addEvent($id, $actorId, 'changed', $field, $old === null ? null : (string) $old, $new === null ? null : (string) $new);
            }
        }

        if ((string) $epic['description'] !== (string) $description) {
            $this->epics->addEvent($id, $actorId, 'changed', 'description');
        }

        if ((bool) $epic['is_done'] !== $done) {
            $this->epics->addEvent($id, $actorId, 'done', null, null, $done ? '1' : null);
            $this->tell($epic + ['title' => $title], $actorId, 'done', [], $done ? '1' : null);
        }
    }

    /** One fact from the epic's own page, as the form sent it. @throws ValidationError */
    public function setField(int $id, string $field, string $value, ?int $actorId): void
    {
        if (!in_array($field, self::FIELDS, true)) {
            throw new ValidationError(__('That cannot be changed here.'));
        }

        $this->update($id, match ($field) {
            'title' => ['title' => $value],
            'description' => ['description' => $value],
            'starts_on' => ['starts_on' => self::day($value)],
            default => ['ends_on' => self::day($value)],
        }, $actorId);
    }

    /** @throws ValidationError */
    public function comment(int $epicId, int $userId, string $body): int
    {
        $epic = $this->epics->find($epicId);

        if ($epic === null) {
            throw new ValidationError(__('There is no such epic.'));
        }

        $body = self::body($body);
        $id = $this->epics->addComment($epicId, $userId, $body);

        // Taking part is following, and a mention is being told whether one
        // followed it or not — and following it from then on.
        $this->epics->watch($epicId, $userId);
        $mentioned = [];

        foreach (Markdown::mentions($body) as $mention) {
            if ($mention !== $userId && $this->users->findActive($mention) !== null) {
                $this->epics->watch($epicId, $mention);
                $mentioned[$mention] = 'mentioned';
            }
        }

        $this->tell($epic, $userId, 'commented', $mentioned, $body);

        return $id;
    }

    /** @throws ValidationError */
    public function editComment(array $comment, string $body): void
    {
        $this->epics->editComment((int) $comment['id'], self::body($body));
    }

    public function removeComment(array $comment): void
    {
        $this->epics->deleteComment((int) $comment['id']);
    }

    /** A file taken onto the epic, or taken off it: a line of its history. */
    public function attached(int $epicId, ?int $userId, string $name, bool $removed = false): void
    {
        $this->epics->addEvent($epicId, $userId, $removed ? 'detached' : 'attached', 'attachment', $removed ? $name : null, $removed ? null : $name);

        if (!$removed && $userId !== null) {
            $this->epics->watch($epicId, $userId);
        }
    }

    /**
     * Tells the epic's followers — and whoever else is in `$told` — about
     * something, each the way they chose (see NotifySettings), and nobody
     * about what they did themselves or about a project they cannot see.
     *
     * @param array<int, string> $told user id => why they are told
     */
    private function tell(array $epic, ?int $actorId, string $kind, array $told, ?string $new): void
    {
        foreach ($this->epics->watchers((int) $epic['id']) as $person) {
            $told[(int) $person['id']] ??= 'watching';
        }

        unset($told[(int) $actorId]);
        $project = (new ProjectRepository($this->db))->find((int) $epic['project_id']);

        foreach ($told as $userId => $reason) {
            try {
                $person = $this->users->find($userId);
                $visible = $person === null ? [] : Access::forUser($person);

                if ($person === null || ($visible !== null && !in_array((int) $epic['project_id'], $visible, true))) {
                    continue;
                }

                $way = NotifySettings::way($person, $reason, $kind);
                if ($way === 'off') {
                    continue;
                }

                $notificationId = $this->notifications->create([
                    'user_id' => $userId,
                    'epic_id' => (int) $epic['id'],
                    'actor_id' => $actorId,
                    'reason' => $reason,
                    'kind' => $kind,
                    'field' => null,
                    'old_value' => null,
                    'new_value' => $new,
                ]);

                if ($way === 'email') {
                    (new Notifier($this->db))->email(
                        $notificationId,
                        $userId,
                        '“' . $epic['title'] . '”',
                        (string) $epic['title'],
                        '/epics/' . $epic['id'],
                        (string) ($project['code'] ?? '')
                    );
                }
            } catch (\Throwable $e) {
                // As with a ticket's: a notification that cannot be written
                // must not undo what it was about.
                Logger::error('A notification could not be made: ' . $e->getMessage());
            }
        }
    }

    /** @throws ValidationError */
    public static function title(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            throw new ValidationError(__('An epic needs a title.'));
        }

        return mb_substr($title, 0, 200);
    }

    /**
     * A day as the form sent it — empty for none — or what is wrong with it.
     *
     * @throws ValidationError
     */
    public static function day(string $given): ?string
    {
        $given = trim($given);
        $date = $given === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $given);

        if ($date === false || ($date !== null && $date->format('Y-m-d') !== $given)) {
            throw new ValidationError(__('“{value}” is not a date.', ['value' => $given]));
        }

        return $date?->format('Y-m-d');
    }

    /** @throws ValidationError */
    public static function order(?string $startsOn, ?string $endsOn): void
    {
        if ($startsOn !== null && $endsOn !== null && $endsOn < $startsOn) {
            throw new ValidationError(__('An epic cannot end before it starts.'));
        }
    }

    /** @throws ValidationError */
    private static function body(string $body): string
    {
        $body = trim(str_replace("\r\n", "\n", $body));

        if ($body === '') {
            throw new ValidationError(__('An empty comment says nothing.'));
        }

        if (mb_strlen($body) > CommentService::MAX_LENGTH) {
            throw new ValidationError(__('A comment can be at most {count} characters.', ['count' => CommentService::MAX_LENGTH]));
        }

        return $body;
    }
}
