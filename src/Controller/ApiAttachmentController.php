<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\HttpError;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\AttachmentRepository;
use CantoTrack\Service\AttachmentService;
use CantoTrack\Service\Presenter;

/**
 * Files on tickets, through the API: listed, uploaded, downloaded and
 * removed under the same rules as on the web — anybody who can see the
 * ticket may attach to it (a guest too, as with a comment), and only the
 * person who attached a file, or an administrator, may remove it.
 */
class ApiAttachmentController extends ApiEndpoint
{
    public function index(string $key): never
    {
        $ticket = $this->ticketOr404($key);

        $this->json(['data' => array_map(fn(array $a): array => $this->attachmentData($a), (new AttachmentRepository())->forTicket((int) $ticket['id']))]);
    }

    /**
     * One file or several, as multipart/form-data: "file", or "files[]".
     * Each is checked by what it is, not by its name, as on the web. The ones
     * that are refused are said in "errors"; when none got in, that is 422.
     */
    public function upload(string $key): never
    {
        $ticket = $this->ticketOr404($key);

        // A post bigger than post_max_size reaches PHP with nothing in it.
        if ($_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new HttpError(413, __('That is too big: the limit is {mb} MB.', ['mb' => intdiv(AttachmentService::maxBytes(), 1048576)]));
        }

        $files = AttachmentController::files();

        if ($files === []) {
            throw new HttpError(422, __('Send the file as multipart/form-data, in a field called "file" — or "files[]" for several.'));
        }

        $service = new AttachmentService();
        $stored = [];
        $errors = [];

        foreach ($files as $file) {
            try {
                $stored[] = $service->store((int) $ticket['id'], (int) Auth::id(), $file);
            } catch (ValidationError $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($stored === []) {
            throw new HttpError(422, implode(' ', $errors));
        }

        $attachments = new AttachmentRepository();

        $this->json([
            'data' => array_map(fn(array $a): array => $this->attachmentData((array) $attachments->find((int) $a['id'])), $stored),
            'errors' => $errors,
        ], 201);
    }

    /**
     * The file itself, as a download, with its own type and never sniffed:
     * a file somebody uploaded is never to act as a page of this application.
     */
    public function download(int $id): never
    {
        $attachment = (new AttachmentRepository())->find($id);
        $path = $attachment === null ? null : AttachmentService::pathOf($attachment);

        if ($attachment === null || $path === null || !is_file($path)) {
            $this->notFound(__('There is no such file.'));
        }

        $name = (string) $attachment['original_name'];
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'file';

        header('Content-Type: ' . $attachment['mime']);
        header('Content-Length: ' . filesize($path));
        header(sprintf('Content-Disposition: attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($name)));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'");
        header('Cache-Control: private, no-store');

        readfile($path);
        exit;
    }

    public function delete(int $id): never
    {
        $attachment = (new AttachmentRepository())->find($id);

        if ($attachment === null) {
            $this->notFound(__('There is no such file.'));
        }

        if (!AttachmentService::canRemove($attachment, (int) Auth::id(), Auth::isAdmin())) {
            $this->forbidden(__('Only the person who attached a file, or an administrator, can remove it.'));
        }

        (new AttachmentService())->remove($attachment, Auth::id());

        $this->noContent();
    }

    private function attachmentData(array $attachment): array
    {
        return [
            'id' => (int) $attachment['id'],
            'name' => $attachment['original_name'],
            'type' => $attachment['mime'],
            'size' => (int) $attachment['size'],
            'image' => in_array($attachment['mime'], AttachmentService::INLINE, true)
                ? ['width' => $attachment['width'] === null ? null : (int) $attachment['width'], 'height' => $attachment['height'] === null ? null : (int) $attachment['height']]
                : null,
            'author' => ['id' => (int) $attachment['user_id'], 'name' => $attachment['user_name'] ?? null],
            'created_at' => $attachment['created_at'],
            'url' => Presenter::url('/api/v1/attachments/' . $attachment['id']),
        ];
    }
}
