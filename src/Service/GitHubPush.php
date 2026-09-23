<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Logger;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\EventRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use PDO;

/**
 * A push from GitHub, read for ticket names.
 *
 * A commit that says "CT-14" anywhere in its message is written into CT-14's
 * history, by whoever made it when their address is one somebody signs in
 * with here. "Fixes CT-14" (or closes, resolves) on the default branch also
 * moves the ticket to its project's done column — work that has landed is
 * done, and nobody has to remember to drag the card.
 */
class GitHubPush
{
    private const KEY = '/\b([A-Z][A-Z0-9]{1,9}-\d+)\b/';
    private const CLOSING = '/\b(?:fix(?:e[sd])?|close[sd]?|resolve[sd]?)\s*:?\s+((?:[A-Z][A-Z0-9]{1,9}-\d+(?:\s*(?:,|and)\s*)?)+)/i';

    private TicketRepository $tickets;
    private EventRepository $events;
    private UserRepository $users;
    private Activity $activity;

    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= DatabaseConnection::get();
        $this->tickets = new TicketRepository($this->db);
        $this->events = new EventRepository($this->db);
        $this->users = new UserRepository($this->db);
        $this->activity = new Activity($this->db);
    }

    /**
     * @param array<string, mixed> $payload GitHub's push event
     * @return array{commits: int, mentions: int, closed: list<string>}
     */
    public function handle(array $payload): array
    {
        $branch = (string) ($payload['ref'] ?? '');
        $default = 'refs/heads/' . (string) ($payload['repository']['default_branch'] ?? 'main');
        $onDefault = $branch === $default;
        $commits = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];
        $mentions = 0;
        $closed = [];

        foreach ($commits as $commit) {
            if (!is_array($commit) || !is_string($commit['message'] ?? null) || !is_string($commit['id'] ?? null)) {
                continue;
            }

            $message = $commit['message'];
            $sha = substr($commit['id'], 0, 40);
            $firstLine = mb_substr(trim(strtok($message, "\n") ?: ''), 0, 200);
            $author = $this->users->findByEmail((string) ($commit['author']['email'] ?? ''));
            $actorId = $author !== null && (int) $author['is_active'] === 1 ? (int) $author['id'] : null;

            preg_match_all(self::KEY, $message, $found);

            foreach (array_unique($found[1]) as $key) {
                $ticket = $this->tickets->findByKey($key);

                if ($ticket === null || $this->events->hasCommit((int) $ticket['id'], $sha)) {
                    continue;
                }

                $this->activity->happened($ticket, $actorId, 'commit', 'commit', $sha, $firstLine);
                $mentions++;
            }

            if (!$onDefault) {
                continue;
            }

            preg_match_all(self::CLOSING, $message, $closing);

            foreach ($closing[1] as $list) {
                preg_match_all(self::KEY, strtoupper($list), $keys);

                foreach ($keys[1] as $key) {
                    $ticket = $this->tickets->findByKey($key);

                    if ($ticket === null || $ticket['status_category'] === 'done') {
                        continue;
                    }

                    try {
                        (new TicketService($this->db))->changeStatus((int) $ticket['id'], 'done', $actorId);
                        $closed[] = $key;
                    } catch (ValidationError $e) {
                        Logger::error('A commit could not close ' . $key . ': ' . $e->getMessage());
                    }
                }
            }
        }

        return ['commits' => count($commits), 'mentions' => $mentions, 'closed' => array_values(array_unique($closed))];
    }
}
