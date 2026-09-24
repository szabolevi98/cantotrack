<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Service\Outbox;

final class OutboxTest extends DatabaseTestCase
{
    /** @var list<string> who each delivery went to */
    private array $delivered = [];

    /** What the mail server says to each try, in turn; null is "taken". */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec('DELETE FROM outbox');
        $this->delivered = [];
        $this->answers = [];
    }

    private function outbox(): Outbox
    {
        return new Outbox($this->db, function (string $to): ?string {
            $this->delivered[] = $to;

            return $this->answers === [] ? null : array_shift($this->answers);
        });
    }

    private function row(int $id): ?array
    {
        return $this->db->query('SELECT * FROM outbox WHERE id = ' . $id)->fetch() ?: null;
    }

    public function testWhatIsDueIsSentOnceAndMarkedSent(): void
    {
        $outbox = $this->outbox();
        $id = $outbox->add('anna@example.com', 'Anna', 'Hello', 'Text');

        self::assertSame(1, $outbox->sendDue());
        self::assertSame(0, $outbox->sendDue());
        self::assertSame(['anna@example.com'], $this->delivered);
        self::assertSame('sent', $this->row($id)['state']);
    }

    public function testARefusedMessageWaitsLongerEachTimeAndThenStaysFailed(): void
    {
        $outbox = $this->outbox();
        $id = $outbox->add('anna@example.com', 'Anna', 'Hello', 'Text');
        $this->answers = ['Connection refused'];

        $outbox->sendDue();
        $row = $this->row($id);
        self::assertSame('waiting', $row['state']);
        self::assertSame('1', (string) $row['attempts']);
        self::assertSame('Connection refused', $row['last_error']);
        self::assertSame('1', (string) $this->db->query('SELECT next_attempt_at > NOW() FROM outbox WHERE id = ' . $id)->fetchColumn());

        // Not due yet: not tried again.
        self::assertSame(0, $outbox->sendDue());

        for ($attempts = 1; $attempts < count(Outbox::BACKOFF) + 1; $attempts++) {
            $outbox->failed($id, $attempts, 'Still refused');
        }
        self::assertSame('failed', $this->row($id)['state']);
    }

    public function testAFailedOneTriedAgainByHandGoes(): void
    {
        $outbox = $this->outbox();
        $id = $outbox->add('anna@example.com', 'Anna', 'Hello', 'Text');
        $outbox->failed($id, count(Outbox::BACKOFF), 'Refused');

        self::assertSame(['waiting' => 0, 'failed' => 1, 'sent_today' => 0], $outbox->counts());
        self::assertTrue($outbox->retry($id));
        self::assertSame(1, $outbox->sendDue());
        self::assertSame(['waiting' => 0, 'failed' => 0, 'sent_today' => 1], $outbox->counts());
    }

    public function testANotificationIsMarkedEmailedWhenItHasGoneNotBefore(): void
    {
        $me = $this->person();
        $ticket = (new \CantoTrack\Service\TicketService($this->db))->create(['project_id' => $this->project(), 'title' => 'x'], $me);
        $notifications = new \CantoTrack\Model\NotificationRepository($this->db);
        $notification = $notifications->create(['user_id' => $me, 'ticket_id' => $ticket, 'actor_id' => null, 'reason' => 'watching', 'kind' => 'commented', 'field' => null, 'old_value' => null, 'new_value' => 'Hi']);

        $outbox = $this->outbox();
        $outbox->add('anna@example.com', 'Anna', 'Hello', 'Text', 'notification', $notification);
        self::assertNull($notifications->find($notification)['emailed_at']);

        $outbox->sendDue();
        self::assertNotNull($notifications->find($notification)['emailed_at']);
    }

    public function testARunThatDiedGivesItsMessagesBack(): void
    {
        $outbox = $this->outbox();
        $id = $outbox->add('anna@example.com', 'Anna', 'Hello', 'Text');
        $this->db->exec("UPDATE outbox SET state = 'sending', taken_at = NOW() - INTERVAL 1 HOUR WHERE id = " . $id);

        self::assertSame(1, $outbox->sendDue());
        self::assertSame('sent', $this->row($id)['state']);
    }

    public function testOldSentMessagesAreCleared(): void
    {
        $outbox = $this->outbox();
        $old = $outbox->add('anna@example.com', 'Anna', 'Old', 'Text');
        $new = $outbox->add('anna@example.com', 'Anna', 'New', 'Text');
        $outbox->sendDue();
        $this->db->exec('UPDATE outbox SET sent_at = NOW() - INTERVAL 40 DAY WHERE id = ' . $old);

        self::assertSame(1, $outbox->prune());
        self::assertNull($this->row($old));
        self::assertSame('sent', $this->row($new)['state']);
    }
}
