<?php

namespace CantoTrack\Tests\Integration;

use CantoTrack\Core\ValidationError;
use CantoTrack\Model\CommentRepository;
use CantoTrack\Service\CommentService;
use CantoTrack\Service\TicketService;
use CantoTrack\Service\Undo;

final class UndoTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testADeletedCommentComesBackWithItsId(): void
    {
        $me = $this->person();
        $ticket = (new TicketService($this->db))->create(['project_id' => $this->project(), 'title' => 'x'], $me);
        $id = (new CommentService($this->db))->add($ticket, $me, 'Worth keeping.');
        $comments = new CommentRepository($this->db);
        $undo = new Undo($this->db);

        $undo->keep('comments', $id, $me, '/tickets/' . $ticket);
        (new CommentService($this->db))->remove((array) $comments->find($id));
        self::assertNull($comments->find($id));

        self::assertSame('The comment is deleted.', Undo::offer($me)['label'] ?? null);
        self::assertNull(Undo::offer($me), 'offered once');

        self::assertSame('/tickets/' . $ticket, $undo->restore($me));
        self::assertSame('Worth keeping.', $comments->find($id)['body']);
    }

    public function testOnlyTheOneWhoDeletedCanTakeItBackAndOnlyForAWhile(): void
    {
        $me = $this->person();
        $other = $this->person('Bence Tóth');
        $ticket = (new TicketService($this->db))->create(['project_id' => $this->project(), 'title' => 'x'], $me);
        $id = (new CommentService($this->db))->add($ticket, $me, 'Gone.');
        $undo = new Undo($this->db);

        $undo->keep('comments', $id, $me, '/');
        $this->db->prepare('DELETE FROM comments WHERE id = :id')->execute(['id' => $id]);
        self::assertNull(Undo::offer($other));

        $_SESSION['_undo']['at'] = time() - 3600;

        $this->expectException(ValidationError::class);
        $undo->restore($me);
    }

    public function testOnlyKnownKindsAreKept(): void
    {
        $me = $this->person();
        (new Undo($this->db))->keep('users', $me, $me, '/');

        self::assertArrayNotHasKey('_undo', $_SESSION);
    }
}
