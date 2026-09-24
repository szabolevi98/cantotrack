<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Session;
use CantoTrack\Core\ValidationError;
use CantoTrack\Core\View;
use CantoTrack\Model\AttachmentRepository;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\NotificationRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\AttachmentService;
use CantoTrack\Service\EpicService;

/**
 * Epics: the middle level.
 *
 * They are edited by anyone signed in rather than by administrators only.
 * Grouping tickets is part of doing the work, and a grouping that needs somebody
 * else's permission is one people stop maintaining.
 *
 * And they are talked about like tickets: comments, files, a history, and
 * followers who hear about them — see EpicService.
 */
class EpicController extends Controller
{
    public function show(int $id): void
    {
        Auth::require();

        $epic = $this->epicOr404($id);
        $epics = new EpicRepository();
        $showing = in_array($_GET['activity'] ?? '', ['comments', 'history'], true) ? (string) $_GET['activity'] : 'all';

        (new NotificationRepository())->markEpicRead((int) Auth::id(), $id);

        View::render('epics/show.twig', [
            'epic' => $epic,
            'project' => (new ProjectRepository())->find((int) $epic['project_id']),
            'tickets' => (new TicketRepository())->search(['epic_id' => $id], 500),
            'progress' => $epics->progress($id),
            'attachments' => (new AttachmentRepository())->forEpic($id),
            'watchers' => $epics->watchers($id),
            'watching' => $epics->isWatching($id, (int) Auth::id()),
            'timeline' => $this->timeline($id, $showing),
            'showing' => $showing,
            'max_upload_mb' => intdiv(AttachmentService::maxBytes(), 1048576),
        ]);
    }

    /**
     * What was said and what happened, in one stream, the oldest first — the
     * way a ticket's page reads.
     *
     * @return list<array<string, mixed>>
     */
    private function timeline(int $epicId, string $showing): array
    {
        $epics = new EpicRepository();
        $items = [];

        if ($showing !== 'history') {
            foreach ($epics->comments($epicId) as $comment) {
                $items[] = ['kind' => 'comment', 'at' => $comment['created_at'], 'order' => (int) $comment['id'], 'comment' => $comment];
            }
        }

        if ($showing !== 'comments') {
            foreach ($epics->events($epicId) as $event) {
                $items[] = ['kind' => 'event', 'at' => $event['created_at'], 'order' => (int) $event['id'], 'event' => $event];
            }
        }

        usort($items, static fn(array $a, array $b): int => [$a['at'], $a['order']] <=> [$b['at'], $b['order']]);

        return $items;
    }

    public function createForm(int $projectId): void
    {
        Auth::requireMember();

        View::render('epics/form.twig', [
            'project' => $this->projectOr404($projectId),
            'epic' => null,
            'error' => null,
        ]);
    }

    public function create(int $projectId): void
    {
        Auth::requireMember();

        $project = $this->projectOr404($projectId);

        try {
            $id = (new EpicService())->create(
                $projectId,
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                EpicService::day((string) ($_POST['starts_on'] ?? '')),
                EpicService::day((string) ($_POST['ends_on'] ?? '')),
                Auth::id()
            );
        } catch (ValidationError $e) {
            View::render('epics/form.twig', [
                'project' => $project,
                'epic' => [
                    'title' => trim((string) ($_POST['title'] ?? '')),
                    'description' => $_POST['description'] ?? '',
                    'is_done' => 0,
                    'starts_on' => $_POST['starts_on'] ?? '',
                    'ends_on' => $_POST['ends_on'] ?? '',
                ],
                'error' => $e->getMessage(),
            ]);

            return;
        }

        Session::flash(__('Epic created.'));
        $this->redirect('/epics/' . $id);
    }

    public function editForm(int $id): void
    {
        Auth::requireMember();

        $epic = $this->epicOr404($id);

        View::render('epics/form.twig', [
            'project' => (new ProjectRepository())->find((int) $epic['project_id']),
            'epic' => $epic,
            'error' => null,
        ]);
    }

    public function update(int $id): void
    {
        Auth::requireMember();

        $epic = $this->epicOr404($id);

        try {
            (new EpicService())->update($id, [
                'title' => (string) ($_POST['title'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'is_done' => isset($_POST['is_done']),
                'starts_on' => EpicService::day((string) ($_POST['starts_on'] ?? '')),
                'ends_on' => EpicService::day((string) ($_POST['ends_on'] ?? '')),
            ], Auth::id());
        } catch (ValidationError $e) {
            View::render('epics/form.twig', [
                'project' => (new ProjectRepository())->find((int) $epic['project_id']),
                'epic' => [
                    'title' => trim((string) ($_POST['title'] ?? '')),
                    'description' => $_POST['description'] ?? '',
                    'is_done' => isset($_POST['is_done']) ? 1 : 0,
                    'starts_on' => $_POST['starts_on'] ?? '',
                    'ends_on' => $_POST['ends_on'] ?? '',
                ] + $epic,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        Session::flash(__('Epic saved.'));
        $this->redirect('/epics/' . $id);
    }

    /** One fact changed where it is shown on the epic's page — see inline.js. */
    public function field(int $id): void
    {
        Auth::requireMember();

        $this->epicOr404($id);
        $error = null;

        try {
            (new EpicService())->setField($id, $this->input('field'), (string) ($_POST['value'] ?? ''), Auth::id());
        } catch (ValidationError $e) {
            $error = $e->getMessage();
        }

        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            $this->json(['ok' => $error === null, 'error' => $error], $error === null ? 200 : 422);
        }

        if ($error !== null) {
            $this->flash($error, 'danger');
        }

        $this->redirect('/epics/' . $id);
    }

    /** Finished, or not after all. */
    public function toggleDone(int $id): void
    {
        Auth::requireMember();

        $epic = $this->epicOr404($id);
        $done = !$epic['is_done'];

        (new EpicService())->update($id, ['is_done' => $done], Auth::id());

        $this->flash($done ? __('The epic is done.') : __('The epic is open again.'));
        $this->redirect('/epics/' . $id);
    }

    public function comment(int $id): void
    {
        Auth::require();

        $this->epicOr404($id);

        try {
            $commentId = (new EpicService())->comment($id, (int) Auth::id(), $this->input('body'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->redirect('/epics/' . $id . '#comment-form');
        }

        $this->redirect('/epics/' . $id . '#comment-' . $commentId);
    }

    /** A comment is its author's to change — see CommentService for why. */
    public function editComment(int $id): void
    {
        Auth::require();

        $comment = $this->commentOr404($id);

        if ((int) $comment['user_id'] !== (int) Auth::id()) {
            $this->forbidden(__('Only the person who wrote a comment can change it.'));
        }

        try {
            (new EpicService())->editComment($comment, $this->input('body'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/epics/' . $comment['epic_id'] . '#comment-' . $id);
    }

    public function deleteComment(int $id): void
    {
        Auth::require();

        $comment = $this->commentOr404($id);

        if (!Auth::isAdmin() && (int) $comment['user_id'] !== (int) Auth::id()) {
            $this->forbidden(__('That is somebody else’s comment.'));
        }

        (new \CantoTrack\Service\Undo())->keep('epic_comments', $id, (int) Auth::id(), '/epics/' . $comment['epic_id'] . '#comment-' . $id);
        (new EpicService())->removeComment($comment);

        $this->redirect('/epics/' . $comment['epic_id']);
    }

    public function delete(int $id): void
    {
        Auth::requireMember();

        $epic = $this->epicOr404($id);

        // Its files go with it — read first, the rows cascade away.
        $files = (new AttachmentRepository())->pathsForEpics('ep.id = :id', ['id' => $id]);
        (new EpicRepository())->delete($id);
        AttachmentService::unlinkAll($files);

        // Worth saying, because it is the opposite of what deleting a project
        // does: the work stays, only the grouping is gone.
        Session::flash(__('Epic deleted. Its tickets are still in the project, without an epic.'), 'warning');
        $this->redirect('/projects/' . $epic['project_id']);
    }

    /**
     * The epic's days from the roadmap's drag: its new first and last day,
     * answered in JSON for the script that moved the bar. The history keeps
     * them; nobody is told.
     */
    public function moveDays(int $id): void
    {
        Auth::requireMember();

        $this->epicOr404($id);

        try {
            (new EpicService())->update($id, [
                'starts_on' => EpicService::day((string) ($_POST['starts_on'] ?? '')),
                'ends_on' => EpicService::day((string) ($_POST['ends_on'] ?? '')),
            ], Auth::id());
        } catch (ValidationError $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        $this->json(['ok' => true]);
    }

    private function epicOr404(int $id): array
    {
        $epic = (new EpicRepository())->find($id);

        if ($epic === null) {
            $this->notFound(__('There is no such epic.'));
        }

        return $epic;
    }

    private function commentOr404(int $id): array
    {
        $comment = (new EpicRepository())->findComment($id);

        if ($comment === null) {
            $this->notFound(__('There is no such comment.'));
        }

        return $comment;
    }

    private function projectOr404(int $id): array
    {
        $project = (new ProjectRepository())->find($id);

        if ($project === null) {
            $this->notFound(__('There is no such project.'));
        }

        return $project;
    }
}
