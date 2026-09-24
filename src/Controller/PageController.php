<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\ConflictError;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Core\View;
use CantoTrack\Model\PageRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\ReleaseRepository;
use CantoTrack\Service\Pages;
use CantoTrack\Service\ReleaseService;

/**
 * A project's pages: the tree, one page, its form, its history.
 *
 * Read by everybody who sees the project, a client's guest among them;
 * written by the team.
 */
class PageController extends Controller
{
    public function index(int $projectId): void
    {
        Auth::require();

        $project = $this->projectOr404($projectId);
        $pages = new PageRepository();
        $words = trim((string) ($_GET['q'] ?? ''));

        $this->render('pages/index.twig', [
            'templates' => Pages::templates(date('Y-m-d')),
            'project' => $project,
            'tree' => $pages->tree($projectId),
            'words' => $words,
            'found' => $words === '' ? [] : $pages->search($projectId, $words),
        ]);
    }

    public function show(int $id): void
    {
        Auth::require();

        $page = $this->pageOr404($id);
        $pages = new PageRepository();
        (new \CantoTrack\Model\RecentRepository())->viewed((int) Auth::id(), 'page', $id);

        $this->render('pages/show.twig', [
            'page' => $page,
            'project' => (new ProjectRepository())->find((int) $page['project_id']),
            'parts' => (new Pages())->render($page, Auth::id()),
            'contents' => Pages::contents((string) $page['body']),
            'ancestors' => $pages->ancestors($page),
            'tree' => $pages->tree((int) $page['project_id']),
            'children' => array_values(array_filter($pages->forProject((int) $page['project_id']), static fn(array $p): bool => (int) $p['parent_id'] === $id)),
            'comments' => $pages->comments($id),
        ]);
    }

    /**
     * Something said under a page — by anybody who can read it, a guest
     * included, the same as under a ticket.
     */
    public function comment(int $id): void
    {
        Auth::require();

        $page = $this->pageOr404($id);
        $body = trim((string) ($_POST['body'] ?? ''));

        if ($body === '') {
            $this->flash(__('A comment needs some words.'), 'danger');
            $this->redirect('/pages/' . $id . '#comments');
        }

        $commentId = (new PageRepository())->addComment((int) $page['id'], (int) Auth::id(), mb_substr($body, 0, 20000));

        $this->redirect('/pages/' . $id . '#page-comment-' . $commentId);
    }

    /** Takes a comment back: the one who wrote it, or an administrator. */
    public function deleteComment(int $id): void
    {
        Auth::require();

        $pages = new PageRepository();
        $comment = $pages->findComment($id);

        if ($comment === null) {
            $this->notFound(__('There is no such comment.'));
        }

        if ((int) $comment['user_id'] !== (int) Auth::id() && !Auth::isAdmin()) {
            $this->forbidden(__('Only the person who wrote a comment, or an administrator, can delete it.'));
        }

        $pages->deleteComment($id);

        $this->flash(__('Comment deleted.'), 'warning');
        $this->redirect('/pages/' . $comment['page_id'] . '#comments');
    }

    public function createForm(int $projectId): void
    {
        Auth::requireMember();

        $project = $this->projectOr404($projectId);
        $template = Pages::templates(date('Y-m-d'))[(string) ($_GET['template'] ?? '')] ?? null;

        $this->form($project, null, [
            'title' => trim((string) ($_GET['title'] ?? '')) ?: ($template['title'] ?? ''),
            'parent_id' => ctype_digit((string) ($_GET['parent'] ?? '')) ? (int) $_GET['parent'] : null,
            'body' => $template['body'] ?? '',
        ]);
    }

    public function create(int $projectId): void
    {
        Auth::requireMember();

        $project = $this->projectOr404($projectId);

        try {
            $id = (new Pages())->create($projectId, $this->idInput('parent_id'), $this->input('title'), (string) ($_POST['body'] ?? ''), (int) Auth::id());
        } catch (ValidationError $e) {
            $this->form($project, null, $this->typed(), $e->getMessage());

            return;
        }

        $this->flash(__('Page created.'));
        $this->redirect('/pages/' . $id);
    }

    public function editForm(int $id): void
    {
        Auth::requireMember();

        $page = $this->pageOr404($id);
        $this->form((array) (new ProjectRepository())->find((int) $page['project_id']), $page, $page);
    }

    public function update(int $id): void
    {
        Auth::requireMember();

        $page = $this->pageOr404($id);
        $project = (array) (new ProjectRepository())->find((int) $page['project_id']);

        try {
            (new Pages())->update($page, $this->idInput('parent_id'), $this->input('title'), (string) ($_POST['body'] ?? ''), (int) Auth::id(), (int) $this->input('version'));
        } catch (ValidationError $e) {
            $this->form($project, $page, $this->typed(), $e->getMessage());

            return;
        } catch (ConflictError $e) {
            $typed = $this->typed();
            $typed['version'] = $e->current['version'] ?? $page['version'];
            $this->form($project, $page, $typed, $e->getMessage());

            return;
        }

        $this->flash(__('Page saved.'));
        $this->redirect('/pages/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::requireMember();

        $page = $this->pageOr404($id);
        (new Pages())->delete($page);

        \CantoTrack\Service\AuditLog::record('page_deleted', 'page', (int) $page['id'], (string) $page['title']);
        $this->flash(__('The page “{title}” is gone; the pages under it moved up.', ['title' => $page['title']]), 'warning');
        $this->redirect('/projects/' . $page['project_id'] . '/pages');
    }

    public function history(int $id): void
    {
        Auth::require();

        $page = $this->pageOr404($id);
        $pages = new PageRepository();
        $version = ctype_digit((string) ($_GET['version'] ?? '')) ? $pages->version($id, (int) $_GET['version']) : null;

        $this->render('pages/history.twig', [
            'page' => $page,
            'project' => (new ProjectRepository())->find((int) $page['project_id']),
            'versions' => $pages->versions($id),
            'shown' => $version,
            'shown_parts' => $version === null ? [] : (new Pages())->render(['project_id' => $page['project_id'], 'body' => $version['body']], Auth::id()),
        ]);
    }

    public function restore(int $id): void
    {
        Auth::requireMember();

        $page = $this->pageOr404($id);

        try {
            (new Pages())->restore($page, (int) $this->input('version'), (int) Auth::id());
            $this->flash(__('The page is back as it was in version {version}.', ['version' => $this->input('version')]));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/pages/' . $id);
    }

    /** The text as it would be drawn, for the form's preview. */
    public function preview(int $projectId): void
    {
        Auth::requireMember();

        $this->projectOr404($projectId);
        $parts = (new Pages())->render(['project_id' => $projectId, 'body' => (string) ($_POST['body'] ?? '')], Auth::id());

        $this->json(['html' => View::twig()->render('pages/body.twig', ['parts' => $parts])]);
    }

    /** A release's notes, kept as a page of the project — under "Release notes". */
    public function fromRelease(int $releaseId): void
    {
        Auth::requireMember();

        $release = (new ReleaseRepository())->find($releaseId);

        if ($release === null) {
            $this->notFound(__('There is no such release.'));
        }

        $projectId = (int) $release['project_id'];
        $pages = new PageRepository();
        $service = new Pages();
        $parent = $pages->byTitle($projectId, __('Release notes'));
        $parentId = $parent === null
            ? $service->create($projectId, null, __('Release notes'), __('Every release, and what went out in it.'), (int) Auth::id())
            : (int) $parent['id'];
        $title = __('Release {name}', ['name' => $release['name']]);
        $existing = $pages->byTitle($projectId, $title);

        if ($existing !== null) {
            $this->flash(__('It is kept as a page already.'));
            $this->redirect('/pages/' . $existing['id']);
        }

        $id = $service->create($projectId, $parentId, $title, (new ReleaseService())->notes($release), (int) Auth::id());

        $this->flash(__('The release notes are a page of the project now.'));
        $this->redirect('/pages/' . $id);
    }

    /** @param array<string, mixed> $values */
    private function form(array $project, ?array $page, array $values, ?string $error = null): void
    {
        $tree = (new PageRepository())->flatTree((int) $project['id']);

        // A page cannot go under itself or its own pages: those are left out.
        if ($page !== null) {
            $below = array_merge([(int) $page['id']], (new PageRepository())->descendantIds((int) $page['id']));
            $tree = array_values(array_filter($tree, static fn(array $p): bool => !in_array((int) $p['id'], $below, true)));
        }

        $this->render('pages/form.twig', [
            'project' => $project,
            'page' => $page,
            'values' => $values,
            'parents' => $tree,
            'error' => $error,
        ], $error === null ? 200 : 422);
    }

    /** @return array<string, mixed> */
    private function typed(): array
    {
        return [
            'title' => $this->input('title'),
            'parent_id' => $this->idInput('parent_id'),
            'body' => (string) ($_POST['body'] ?? ''),
            'version' => $this->input('version'),
        ];
    }

    private function pageOr404(int $id): array
    {
        $page = (new PageRepository())->find($id);

        if ($page === null) {
            $this->notFound(__('There is no such page.'));
        }

        return $page;
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
