<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\CommentRepository;
use CantoTrack\Service\CommentService;

/** Saying something on a ticket, and correcting or taking back what was said. */
class CommentController extends Controller
{
    public function create(int $ticketId): void
    {
        Auth::require();

        try {
            $id = (new CommentService())->add($ticketId, (int) Auth::id(), $this->input('body'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->redirect('/tickets/' . $ticketId . '#comment-form');
        }

        $this->redirect('/tickets/' . $ticketId . '#comment-' . $id);
    }

    public function update(int $id): void
    {
        Auth::require();

        $comment = $this->commentOr404($id);

        if (!CommentService::canEdit($comment, (int) Auth::id())) {
            $this->forbidden(__('Only the person who wrote a comment can change it.'));
        }

        try {
            (new CommentService())->edit($comment, $this->input('body'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/tickets/' . $comment['ticket_id'] . '#comment-' . $id);
    }

    public function delete(int $id): void
    {
        Auth::require();

        $comment = $this->commentOr404($id);

        if (!CommentService::canDelete($comment, (int) Auth::id(), Auth::isAdmin())) {
            $this->forbidden(__('That is somebody else’s comment.'));
        }

        (new \CantoTrack\Service\Undo())->keep('comments', $id, (int) Auth::id(), '/tickets/' . $comment['ticket_id'] . '#comment-' . $id);
        (new CommentService())->remove($comment);

        $this->redirect('/tickets/' . $comment['ticket_id']);
    }

    private function commentOr404(int $id): array
    {
        $comment = (new CommentRepository())->find($id);

        if ($comment === null) {
            $this->notFound(__('There is no such comment.'));
        }

        return $comment;
    }
}
