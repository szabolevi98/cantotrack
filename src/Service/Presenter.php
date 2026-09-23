<?php

namespace CantoTrack\Service;

use CantoTrack\Core\Config;
use CantoTrack\Model\TicketRepository;

/**
 * How a ticket or a person looks from outside: in the API's answers and in
 * the webhooks' messages — the same shape in both, so a program that reads
 * one can read the other.
 */
final class Presenter
{
    public static function url(string $path): string
    {
        return rtrim((string) Config::get('app.base_url'), '/') . $path;
    }

    public static function person(array $user, bool $self = false): array
    {
        $out = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'handle' => $user['handle'] ?? null,
        ];

        if ($self) {
            $out += ['email' => $user['email'], 'role' => $user['role']];
        }

        return $out;
    }

    public static function ticket(array $ticket, bool $full = false): array
    {
        $key = $ticket['project_code'] . '-' . $ticket['number'];

        $out = [
            'key' => $key,
            'id' => (int) $ticket['id'],
            'project' => $ticket['project_code'],
            'type' => $ticket['type'],
            'title' => $ticket['title'],
            'status' => ['id' => (int) $ticket['status_id'], 'name' => $ticket['status_name'], 'category' => $ticket['status_category']],
            'priority' => $ticket['priority'],
            'assignee' => $ticket['assignee_id'] === null ? null : ['id' => (int) $ticket['assignee_id'], 'name' => $ticket['assignee_name']],
            'labels' => $ticket['label_names'] === null ? [] : explode("\n", (string) $ticket['label_names']),
            'sprint' => $ticket['sprint_name'],
            'epic' => $ticket['epic_id'] === null ? null : ['id' => (int) $ticket['epic_id'], 'title' => $ticket['epic_title']],
            'parent' => $ticket['parent_id'] === null ? null : $ticket['project_code'] . '-' . $ticket['parent_number'],
            'release' => $ticket['release_id'] === null ? null : $ticket['release_name'],
            'subtasks' => ['count' => (int) ($ticket['subtask_count'] ?? 0), 'done' => (int) ($ticket['subtasks_done'] ?? 0)],
            'story_points' => $ticket['story_points'] === null ? null : (int) $ticket['story_points'],
            'estimate_minutes' => $ticket['estimate_minutes'] === null ? null : (int) $ticket['estimate_minutes'],
            'logged_minutes' => (int) $ticket['logged_minutes'],
            'remaining_minutes' => TicketRepository::remaining($ticket),
            'due_on' => $ticket['due_on'],
            'version' => (int) $ticket['version'],
            'created_at' => $ticket['created_at'],
            'updated_at' => $ticket['updated_at'],
            'url' => self::url('/t/' . $key),
        ];

        if ($full) {
            $out['description'] = (string) $ticket['description'];
            $out['reporter'] = $ticket['reporter_id'] === null ? null : ['id' => (int) $ticket['reporter_id'], 'name' => $ticket['reporter_name']];
        }

        return $out;
    }
}
