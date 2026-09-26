<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Config;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Session;
use CantoTrack\Model\ApiTokenRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\AuditLog;

/**
 * Service accounts: accounts for programs rather than people — a webshop, a
 * CI server, a bookkeeping export — and the tokens they use the API with.
 * See the 0052 migration.
 *
 * Administrators only. A service account is a member (it may change the
 * work) or a guest (it reads and comments, and sees only the projects it is
 * added to); never an administrator, because a token that can do anything
 * is a token that will one day be found in a repository. Its tokens are
 * made here, shown once, and revoked here; it has no password to sign in
 * with, and its name is what the tickets it makes and the comments it
 * writes carry.
 */
class ServiceAccountController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $this->render('settings/service_accounts.twig', [
            'accounts' => (new UserRepository())->services(),
        ]);
    }

    public function create(): void
    {
        Auth::requireAdmin();

        $name = $this->input('name');

        if ($name === '' || mb_strlen($name) > 120) {
            $this->flash(__('A service account needs a name — what uses it, like “GitLab CI”.'), 'danger');
            $this->redirect('/settings/service-accounts');
        }

        $id = (new UserRepository())->createService($name, $this->input('role'));
        AuditLog::record('service_created', 'user', $id, $name, $this->input('role') === 'guest' ? 'guest' : 'member');

        $this->flash(__('{name} is made. Make it a token below.', ['name' => $name]));
        $this->redirect('/settings/service-accounts/' . $id);
    }

    public function show(int $id): void
    {
        Auth::requireAdmin();

        $account = $this->accountOr404($id);
        $new = Session::get('_new_token');
        Session::forget('_new_token');

        $this->render('settings/service_account.twig', [
            'account' => $account,
            'tokens' => (new ApiTokenRepository())->forUser($id),
            'projects' => $this->projectsOf($id),
            'new_token' => is_string($new) ? $new : null,
            'api_base' => rtrim((string) Config::get('app.base_url'), '/') . '/api/v1',
        ]);
    }

    public function update(int $id): void
    {
        Auth::requireAdmin();

        $account = $this->accountOr404($id);
        $name = $this->input('name');
        $role = $this->input('role') === 'guest' ? 'guest' : 'member';
        $active = isset($_POST['is_active']);

        if ($name === '' || mb_strlen($name) > 120) {
            $this->flash(__('A service account needs a name — what uses it, like “GitLab CI”.'), 'danger');
            $this->redirect('/settings/service-accounts/' . $id);
        }

        (new UserRepository())->update($id, $name, (string) $account['email'], $role, $active);
        AuditLog::record('service_updated', 'user', $id, $name, AuditLog::changes(
            ['name' => $account['name'], 'role' => $account['role'], 'active' => (int) $account['is_active'] === 1 ? 'yes' : 'no'],
            ['name' => $name, 'role' => $role, 'active' => $active ? 'yes' : 'no']
        ));

        $this->flash($active ? __('Saved.') : __('Saved. It is switched off: its tokens are refused until it is switched on again.'));
        $this->redirect('/settings/service-accounts/' . $id);
    }

    public function createToken(int $id): void
    {
        Auth::requireAdmin();

        $account = $this->accountOr404($id);
        $name = $this->input('name');
        $expires = $this->input('expires_on');

        if ($name === '') {
            $this->flash(__('Give the token a name — what it is for, so you know which one to revoke.'), 'danger');
            $this->redirect('/settings/service-accounts/' . $id);
        }

        if ($expires !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires);

            if ($date === false || $expires <= date('Y-m-d')) {
                $this->flash(__('A token expires on a day in the future, or never.'), 'danger');
                $this->redirect('/settings/service-accounts/' . $id);
            }
        }

        Session::put('_new_token', (new ApiTokenRepository())->create($id, $name, $expires ?: null));
        AuditLog::record('service_token_created', 'user', $id, $account['name'] . ' — ' . $name, $expires ? 'expires ' . $expires : 'never expires');

        $this->flash(__('Token made. Copy it now: it is not shown again.'));
        $this->redirect('/settings/service-accounts/' . $id);
    }

    public function revokeToken(int $id, int $tokenId): void
    {
        Auth::requireAdmin();

        $account = $this->accountOr404($id);

        if (!(new ApiTokenRepository())->revoke($tokenId, $id)) {
            $this->notFound(__('There is no such token.'));
        }

        AuditLog::record('service_token_revoked', 'user', $id, $account['name'] . ' — #' . $tokenId);
        $this->flash(__('Token revoked. Anything still using it is refused from now on.'), 'warning');
        $this->redirect('/settings/service-accounts/' . $id);
    }

    private function accountOr404(int $id): array
    {
        $account = (new UserRepository())->find($id);

        if ($account === null || (int) $account['is_service'] !== 1) {
            $this->notFound(__('There is no such service account.'));
        }

        return $account;
    }

    /** The projects it was added to, by code — what a guest account can see, and the private ones a member can. */
    private function projectsOf(int $id): array
    {
        $statement = \CantoTrack\Core\DatabaseConnection::get()->prepare(
            'SELECT p.id, p.code, p.name, p.visibility FROM project_members m JOIN projects p ON p.id = m.project_id
             WHERE m.user_id = :user ORDER BY p.code'
        );
        $statement->execute(['user' => $id]);

        return $statement->fetchAll();
    }
}
