<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Session;
use CantoTrack\Core\View;
use CantoTrack\Model\UserRepository;

/**
 * The people: who can sign in, what they may do, and who is still with us.
 *
 * Administrators only. Passwords are generated here rather than typed, and
 * shown once — a password an administrator chooses for somebody else tends to
 * be the same one they chose for everybody else, and one typed into a form is
 * one that has been through a browser's autofill and a proxy's log.
 *
 * Nobody is deleted. Accounts are deactivated, because tickets and worklogs
 * point at them and the history has to keep making sense.
 */
class PeopleController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        // Taken out of the session as it is drawn, so a reload does not put the
        // password back on screen and a second person at the same desk does not
        // read it later.
        $password = Session::get('_new_password');
        Session::forget('_new_password');

        View::render('people/index.twig', [
            'people' => (new UserRepository())->withActivity(),
            'new_password' => $password,
        ]);
    }

    public function createForm(): void
    {
        Auth::requireAdmin();

        View::render('people/form.twig', ['person' => null, 'error' => null, 'password' => null]);
    }

    public function create(): void
    {
        Auth::requireAdmin();

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $role = ($_POST['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $users = new UserRepository();

        $error = match (true) {
            $name === '' => 'A person needs a name.',
            filter_var($email, FILTER_VALIDATE_EMAIL) === false => 'That does not look like an email address.',
            $users->emailTaken($email) => 'Somebody already signs in with that address.',
            default => null,
        };

        if ($error !== null) {
            View::render('people/form.twig', [
                'person' => ['name' => $name, 'email' => $email, 'role' => $role, 'is_active' => 1],
                'error' => $error,
                'password' => null,
            ]);

            return;
        }

        $password = UserRepository::newPassword();
        $users->create($name, $email, $password, $role);

        // Shown on the next page and never again: it is not stored in readable
        // form anywhere, which is the point.
        Session::put('_new_password', ['email' => $email, 'password' => $password]);
        Session::flash($name . ' can sign in now.');

        $this->redirect('/people');
    }

    public function editForm(int $id): void
    {
        Auth::requireAdmin();

        View::render('people/form.twig', [
            'person' => $this->personOr404($id),
            'error' => null,
            'password' => null,
        ]);
    }

    public function update(int $id): void
    {
        Auth::requireAdmin();

        $person = $this->personOr404($id);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $role = ($_POST['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        $isActive = isset($_POST['is_active']);
        $users = new UserRepository();

        // The two ways an administrator can lock themselves out of their own
        // installation, both refused rather than explained afterwards.
        $itsMe = (int) $person['id'] === (int) Auth::id();

        $error = match (true) {
            $name === '' => 'A person needs a name.',
            filter_var($email, FILTER_VALIDATE_EMAIL) === false => 'That does not look like an email address.',
            $users->emailTaken($email, $id) => 'Somebody already signs in with that address.',
            $itsMe && $role !== 'admin' => 'You cannot take the administrator role off yourself.',
            $itsMe && !$isActive => 'You cannot deactivate your own account.',
            default => null,
        };

        if ($error !== null) {
            View::render('people/form.twig', [
                'person' => ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role, 'is_active' => $isActive],
                'error' => $error,
                'password' => null,
            ]);

            return;
        }

        $users->update($id, $name, $email, $role, $isActive);

        Session::flash('Saved.');
        $this->redirect('/people');
    }

    /** Hands out a new password for somebody who has lost theirs. */
    public function resetPassword(int $id): void
    {
        Auth::requireAdmin();

        $person = $this->personOr404($id);
        $password = UserRepository::newPassword();

        (new UserRepository())->setPassword($id, $password);

        Session::put('_new_password', ['email' => $person['email'], 'password' => $password]);
        Session::flash('A new password for ' . $person['name'] . ' is below.');

        $this->redirect('/people');
    }

    private function personOr404(int $id): array
    {
        $person = (new UserRepository())->find($id);

        if ($person === null) {
            $this->notFound(__('There is no such person.'));
        }

        return $person;
    }
}
