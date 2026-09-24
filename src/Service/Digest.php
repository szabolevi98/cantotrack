<?php

namespace CantoTrack\Service;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Config;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\I18n;
use CantoTrack\Core\Logger;
use CantoTrack\Core\Mailer;
use CantoTrack\Core\ValidationError;
use CantoTrack\Core\View;
use CantoTrack\Model\CustomFieldRepository;
use CantoTrack\Model\TicketRepository;
use DateTimeImmutable;
use PDO;

/**
 * The morning email some people ask for — see the 0037 migration: what
 * their own query finds, what of theirs is due this week, and how many
 * notifications wait unread. On working days, once a day, from cron
 * (bin/digest.php).
 */
final class Digest
{
    /** What the query is, until somebody writes their own. */
    public const DEFAULT_QUERY = 'assignee = me AND category != done ORDER BY priority DESC';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    /** Sends every digest that is due today. Returns how many went. */
    public function sendDue(DateTimeImmutable $today): int
    {
        if ((int) $today->format('N') >= 6 || !Mailer::isConfigured()) {
            return 0;
        }

        $statement = $this->db->prepare(
            'SELECT * FROM users WHERE is_active = 1 AND digest_query IS NOT NULL
               AND (digest_sent_on IS NULL OR digest_sent_on < :today)'
        );
        $statement->execute(['today' => $today->format('Y-m-d')]);
        $sent = 0;

        foreach ($statement->fetchAll() as $user) {
            try {
                [$subject, $text] = $this->compose($user, $today);

                if (Mailer::send((string) $user['email'], (string) $user['name'], $subject, $text)) {
                    $this->db->prepare('UPDATE users SET digest_sent_on = :today WHERE id = :id')
                        ->execute(['today' => $today->format('Y-m-d'), 'id' => $user['id']]);
                    $sent++;
                }
            } catch (\Throwable $e) {
                Logger::error('A digest could not be sent to user ' . $user['id'] . ': ' . $e->getMessage());
            }
        }

        return $sent;
    }

    /**
     * One person's digest: its subject and its text, in their language and
     * as they would see it signed in.
     *
     * @param array<string, mixed> $user
     * @return array{0: string, 1: string}
     */
    public function compose(array $user, DateTimeImmutable $today): array
    {
        $before = Auth::user();
        Auth::actAs($user);
        $previous = I18n::locale();
        I18n::setLocale((string) ($user['locale'] ?: Config::get('app.locale', 'en')));

        try {
            $tickets = new TicketRepository($this->db);
            $query = trim((string) ($user['digest_query'] ?? '')) ?: self::DEFAULT_QUERY;
            $error = null;
            $found = [];
            $total = 0;

            try {
                $compiled = TicketQuery::compile($query, (int) $user['id'], $today, (new CustomFieldRepository($this->db))->kindsByName());
                $filters = ['query_where' => $compiled['where'], 'query_params' => $compiled['params'], 'query_order' => $compiled['order']];
                $found = $tickets->search($filters, 15);
                $total = $tickets->count($filters);
            } catch (ValidationError $e) {
                $error = $e->getMessage();
            }

            $sunday = $today->modify('sunday this week')->format('Y-m-d');
            $due = array_values(array_filter(
                $tickets->search(['assignee_id' => (int) $user['id']], 100),
                static fn(array $t): bool => $t['status_category'] !== 'done' && $t['due_on'] !== null && $t['due_on'] <= $sunday
            ));

            $unread = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user AND read_at IS NULL');
            $unread->execute(['user' => $user['id']]);

            $base = rtrim((string) Config::get('app.base_url'), '/');
            $text = View::twig()->render('emails/digest.txt.twig', [
                'person' => $user,
                'query' => $query,
                'error' => $error,
                'found' => $found,
                'total' => $total,
                'due' => $due,
                'today' => $today->format('Y-m-d'),
                'unread' => (int) $unread->fetchColumn(),
                'base' => $base,
                'list' => $base . '/tickets?' . http_build_query(['query' => $query]),
            ]);

            return [__('Your morning in {app}: {count} to look at', ['app' => (string) Config::get('app.name', 'CantoTrack'), 'count' => $total]), $text];
        } finally {
            Auth::actAs($before);
            I18n::setLocale($previous);
        }
    }
}
