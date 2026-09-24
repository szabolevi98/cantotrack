<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\AttachmentRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Service\AttachmentService;

/**
 * Files on tickets: the upload (a form, a drop, or a screenshot pasted into a
 * comment), the download, and the delete.
 */
class AttachmentController extends Controller
{
    /**
     * One or several files onto a ticket. From the form this redirects back;
     * from the page's own script (a pasted screenshot) it answers with where
     * the file is now, so the script can put it into the comment being written.
     */
    public function upload(int $ticketId): void
    {
        Auth::require();

        if ((new TicketRepository())->find($ticketId) === null) {
            $this->notFound(__('There is no such ticket.'));
        }

        $this->receive(
            fn(array $file): array => (new AttachmentService())->store($ticketId, (int) Auth::id(), $file),
            '/tickets/' . $ticketId . '#attachments'
        );
    }

    /** Pictures pasted into a page being written — answered like the ticket's. */
    public function uploadToPage(int $pageId): void
    {
        Auth::requireMember();

        if ((new \CantoTrack\Model\PageRepository())->find($pageId) === null) {
            $this->notFound(__('There is no such page.'));
        }

        $this->receive(
            fn(array $file): array => (new AttachmentService())->storeOnPage($pageId, (int) Auth::id(), $file),
            '/pages/' . $pageId . '/edit'
        );
    }

    /** Files onto an epic, from its form, a drop, or a paste into a comment. */
    public function uploadToEpic(int $epicId): void
    {
        Auth::require();

        if ((new \CantoTrack\Model\EpicRepository())->find($epicId) === null) {
            $this->notFound(__('There is no such epic.'));
        }

        $this->receive(
            fn(array $file): array => (new AttachmentService())->storeOnEpic($epicId, (int) Auth::id(), $file),
            '/epics/' . $epicId . '#attachments'
        );
    }

    /**
     * Every file of an upload, each through `$store`: JSON back for the
     * page's own script, a redirect with a message for the form.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $store
     */
    private function receive(callable $store, string $back): never
    {
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $stored = [];
        $errors = [];

        // A post bigger than post_max_size arrives with nothing in it at all —
        // no fields, no files — and has to be told apart from "no file chosen".
        if ($_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $errors[] = __('That is too big: the limit is {mb} MB.', ['mb' => intdiv(AttachmentService::maxBytes(), 1048576)]);
        }

        foreach (self::files() as $file) {
            try {
                $stored[] = $store($file);
            } catch (ValidationError $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($wantsJson) {
            $this->json([
                'files' => array_map(fn(array $a): array => [
                    'id' => (int) $a['id'],
                    'name' => $a['original_name'],
                    'url' => $this->address($a),
                    'image' => in_array($a['mime'], AttachmentService::INLINE, true),
                ], $stored),
                'errors' => $errors,
            ], $stored === [] && $errors !== [] ? 422 : 200);
        }

        if ($errors !== []) {
            $this->flash(implode(' ', $errors), 'danger');
        } elseif ($stored !== []) {
            $this->flash(__n('{count} file attached.', '{count} files attached.', count($stored)));
        }

        $this->redirect($back);
    }

    /**
     * Hands a file out — to somebody signed in, and never in a way that lets
     * it act as a page of this application: its own type, no sniffing, and a
     * sandbox even for the ones shown in place.
     */
    public function download(int $id): void
    {
        Auth::require();

        $attachment = (new AttachmentRepository())->find($id);
        $path = $attachment === null ? null : AttachmentService::pathOf($attachment);

        if ($attachment === null || $path === null || !is_file($path)) {
            $this->notFound(__('There is no such file.'));
        }

        $mime = (string) $attachment['mime'];
        $inline = in_array($mime, AttachmentService::INLINE, true) && ($_GET['download'] ?? '') !== '1';
        $name = (string) $attachment['original_name'];
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'file';

        header('Content-Type: ' . $mime . (str_starts_with($mime, 'text/') ? '; charset=utf-8' : ''));
        header('Content-Length: ' . filesize($path));
        header(sprintf(
            'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
            $inline ? 'inline' : 'attachment',
            $ascii,
            rawurlencode($name)
        ));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
        header('Cache-Control: private, max-age=86400');

        readfile($path);
        exit;
    }

    public function delete(int $id): void
    {
        Auth::require();

        $attachment = (new AttachmentRepository())->find($id);

        if ($attachment === null) {
            $this->notFound(__('There is no such file.'));
        }

        if (!AttachmentService::canRemove($attachment, (int) Auth::id(), Auth::isAdmin())) {
            $this->forbidden(__('Only the person who attached a file, or an administrator, can remove it.'));
        }

        (new AttachmentService())->remove($attachment, Auth::id());

        $this->flash(__('{name} removed.', ['name' => $attachment['original_name']]), 'warning');
        $this->redirect(match (true) {
            $attachment['page_id'] !== null => '/pages/' . $attachment['page_id'],
            $attachment['epic_id'] !== null => '/epics/' . $attachment['epic_id'] . '#attachments',
            default => '/tickets/' . $attachment['ticket_id'] . '#attachments',
        });
    }

    private function address(array $attachment): string
    {
        return rtrim((string) \CantoTrack\Core\Config::get('app.base_url'), '/') . '/attachments/' . $attachment['id'];
    }

    /**
     * The uploaded files, whichever way the form named them: `file` for one,
     * `files[]` for several. PHP spreads the second across five parallel
     * arrays; this puts each file back together.
     *
     * @return list<array<string, mixed>>
     */
    private static function files(): array
    {
        $files = [];

        foreach (['file', 'files'] as $field) {
            $given = $_FILES[$field] ?? null;

            if (!is_array($given) || !isset($given['name'])) {
                continue;
            }

            if (!is_array($given['name'])) {
                if ((int) ($given['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $files[] = $given;
                }

                continue;
            }

            foreach (array_keys($given['name']) as $index) {
                if ((int) ($given['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $files[] = [
                    'name' => $given['name'][$index],
                    'tmp_name' => $given['tmp_name'][$index] ?? '',
                    'error' => $given['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $given['size'][$index] ?? 0,
                ];
            }
        }

        return $files;
    }
}
