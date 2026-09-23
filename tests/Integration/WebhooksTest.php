<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Model\WebhookRepository;
use CantoTrack\Service\Activity;
use CantoTrack\Service\GitHubPush;
use CantoTrack\Service\TicketService;
use CantoTrack\Service\Webhooks;

final class WebhooksTest extends DatabaseTestCase
{
    private int $me;
    private int $project;

    protected function setUp(): void
    {
        parent::setUp();
        Activity::forgetListeners();
        Webhooks::reset();

        $this->me = $this->person();
        $this->project = $this->project();
    }

    protected function tearDown(): void
    {
        Activity::forgetListeners();
        Webhooks::reset();
    }

    public function testAnEditOfSeveralFieldsIsOneMessageWithEveryChangeInIt(): void
    {
        $hooks = new WebhookRepository($this->db);
        $all = $hooks->create('All', 'https://example.com/a', [], null, $this->me);
        $hooks->create('Only status', 'https://example.com/b', ['ticket.status'], null, $this->me);

        Activity::listen(Webhooks::collect(...));
        $tickets = new TicketService($this->db);
        $id = $tickets->create(['project_id' => $this->project, 'title' => 'x'], $this->me);
        $tickets->update($id, ['title' => 'y', 'priority' => 'high'], $this->me);

        $ids = (new Webhooks($this->db))->flush();

        // created and changed to the first; nothing to the second.
        self::assertCount(2, $ids);
        $changed = $hooks->delivery($ids[1]);
        self::assertNotNull($changed);
        self::assertSame($all, (int) $changed['webhook_id']);
        self::assertSame('ticket.changed', $changed['event']);

        $payload = json_decode((string) $changed['payload'], true);
        self::assertSame('y', $payload['ticket']['title']);
        self::assertEqualsCanonicalizing(['title', 'priority'], array_column($payload['changes'], 'field'));
    }

    public function testAFailedMessageIsTriedAgainLaterAndThenGivenUp(): void
    {
        $hooks = new WebhookRepository($this->db);
        $hook = $hooks->create('Down', 'https://example.com/a', [], null, $this->me);
        $id = $hooks->queue($hook, 'ping', '{}');
        $failure = ['status' => 500, 'body' => 'nope', 'error' => null, 'ms' => 3];

        $hooks->recordAttempt($id, false, $failure);
        self::assertSame('pending', $hooks->delivery($id)['state'] ?? null);
        self::assertSame([], $hooks->due(), 'A failed message was due again at once.');

        for ($i = 1; $i < count(WebhookRepository::BACKOFF) + 1; $i++) {
            $hooks->recordAttempt($id, false, $failure);
        }

        self::assertSame('failed', $hooks->delivery($id)['state'] ?? null);

        $hooks->retry($id);
        self::assertSame([$id], $hooks->due());
    }

    public function testTheSignatureIsTheHmacOfTheBody(): void
    {
        self::assertSame('sha256=' . hash_hmac('sha256', '{"a":1}', 'k'), Webhooks::signature('{"a":1}', 'k'));
    }

    public function testACommitThatNamesATicketIsInItsHistoryOnceAndFixesClosesIt(): void
    {
        $tickets = new TicketService($this->db);
        $id = $tickets->create(['project_id' => $this->project, 'title' => 'x'], $this->me);
        $push = [
            'ref' => 'refs/heads/main',
            'repository' => ['default_branch' => 'main'],
            'commits' => [
                ['id' => str_repeat('a', 40), 'message' => "Tidy the parser, see CT-1\n\nMore text", 'author' => ['email' => 'nobody@example.test']],
                ['id' => str_repeat('b', 40), 'message' => 'Fixes CT-1', 'author' => ['email' => 'nobody@example.test']],
            ],
        ];

        $result = (new GitHubPush($this->db))->handle($push);
        self::assertSame(2, $result['mentions']);
        self::assertSame(['CT-1'], $result['closed']);

        // The same push again — another branch, a re-sent hook — adds nothing.
        self::assertSame(0, (new GitHubPush($this->db))->handle($push)['mentions']);

        $events = $this->db->query("SELECT old_value, new_value FROM ticket_events WHERE kind = 'commit' AND ticket_id = " . $id)->fetchAll();
        self::assertCount(2, $events);
        self::assertSame('Tidy the parser, see CT-1', $events[0]['new_value']);

        $category = $this->db->query('SELECT s.category FROM tickets t JOIN statuses s ON s.id = t.status_id WHERE t.id = ' . $id)->fetchColumn();
        self::assertSame('done', $category);
    }

    public function testFixesOnAnotherBranchDoesNotCloseAnything(): void
    {
        (new TicketService($this->db))->create(['project_id' => $this->project, 'title' => 'x'], $this->me);

        $result = (new GitHubPush($this->db))->handle([
            'ref' => 'refs/heads/feature',
            'repository' => ['default_branch' => 'main'],
            'commits' => [['id' => str_repeat('c', 40), 'message' => 'Fixes CT-1', 'author' => []]],
        ]);

        self::assertSame(1, $result['mentions']);
        self::assertSame([], $result['closed']);
    }
}
