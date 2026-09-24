<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Config;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Session;
use CantoTrack\Model\ApiTokenRepository;

/**
 * One's own personal access tokens: made, listed, revoked.
 *
 * A new token is shown once, on the page after it was made, and taken out of
 * the session as it is drawn — the same as a generated password.
 */
class ApiTokenController extends Controller
{
    public function index(): void
    {
        Auth::require();

        $new = Session::get('_new_token');
        Session::forget('_new_token');

        $this->render('profile/tokens.twig', [
            'tokens' => (new ApiTokenRepository())->forUser((int) Auth::id()),
            'new_token' => is_string($new) ? $new : null,
            'api_base' => rtrim((string) Config::get('app.base_url'), '/') . '/api/v1',
        ]);
    }

    public function create(): void
    {
        Auth::require();

        $name = $this->input('name');
        $expires = $this->input('expires_on');

        if ($name === '') {
            $this->flash(__('Give the token a name — what it is for, so you know which one to revoke.'), 'danger');
            $this->redirect('/profile/tokens');
        }

        if ($expires !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires);

            if ($date === false || $expires <= date('Y-m-d')) {
                $this->flash(__('A token expires on a day in the future, or never.'), 'danger');
                $this->redirect('/profile/tokens');
            }
        }

        Session::put('_new_token', (new ApiTokenRepository())->create((int) Auth::id(), $name, $expires ?: null));
        \CantoTrack\Service\AuditLog::record('token_created', 'token', null, $name, $expires ? 'expires ' . $expires : 'never expires');

        $this->flash(__('Token made. Copy it now: it is not shown again.'));
        $this->redirect('/profile/tokens');
    }

    public function revoke(int $id): void
    {
        Auth::require();

        if (!(new ApiTokenRepository())->revoke($id, (int) Auth::id())) {
            $this->notFound(__('There is no such token of yours.'));
        }

        \CantoTrack\Service\AuditLog::record('token_revoked', 'token', $id, '#' . $id);
        $this->flash(__('Token revoked. Anything still using it is refused from now on.'), 'warning');
        $this->redirect('/profile/tokens');
    }
}
