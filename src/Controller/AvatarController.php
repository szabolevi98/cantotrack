<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\Avatars;

/** One's own picture, and the pictures being shown to whoever is signed in. */
class AvatarController extends Controller
{
    public function upload(): void
    {
        Auth::require();

        $file = $_FILES['avatar'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $this->flash(__('Choose a picture first.'), 'danger');
            $this->redirect('/profile');
        }

        try {
            (new Avatars())->store((int) Auth::id(), (string) $file['tmp_name']);
            $this->flash(__('Your picture is changed.'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/profile');
    }

    public function remove(): void
    {
        Auth::require();

        (new Avatars())->remove((int) Auth::id());

        $this->flash(__('Your picture is gone; your initials are back.'), 'warning');
        $this->redirect('/profile');
    }

    /**
     * A picture, for anybody signed in. The name is checked against the one
     * on the account, so only the current picture is served and nothing but a
     * picture ever is.
     */
    public function show(int $userId, string $file): void
    {
        Auth::require();

        $person = (new UserRepository())->find($userId);

        if ($person === null || ($person['avatar'] ?? null) !== $file || basename($file) !== $file) {
            $this->notFound(__('There is no such picture.'));
        }

        $path = Avatars::directory() . '/' . $file;

        if (!is_file($path)) {
            $this->notFound(__('There is no such picture.'));
        }

        header('Content-Type: ' . (str_ends_with($file, '.webp') ? 'image/webp' : 'image/png'));
        header('Content-Length: ' . filesize($path));
        // The name changes with the picture, so it can be kept for good.
        header('Cache-Control: private, max-age=31536000, immutable');
        readfile($path);
        exit;
    }
}
