<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Session;
use CantoTrack\Core\Totp;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\TwoFactor;

/**
 * Turning two-step sign-in on and off, on one's own profile.
 *
 * Turning it off, and making new recovery codes, ask for the password again:
 * a laptop left signed in is not supposed to be enough to take the second
 * step away.
 */
class TwoFactorController extends Controller
{
    private const SETUP = '_totp_setup';
    private const NEW_CODES = '_new_recovery_codes';

    public function show(): void
    {
        Auth::require();

        $me = (array) Auth::user();
        $setup = Session::get(self::SETUP);
        $codes = Session::get(self::NEW_CODES);
        Session::forget(self::NEW_CODES);

        $this->render('profile/two_factor.twig', [
            'on' => TwoFactor::isOn($me),
            'setup' => is_string($setup) ? $setup : null,
            'qr' => is_string($setup) ? TwoFactor::qr($me, $setup) : null,
            'new_codes' => is_array($codes) ? $codes : null,
            'codes_left' => (new TwoFactor())->recoveryCodesLeft((int) $me['id']),
        ]);
    }

    public function start(): void
    {
        Auth::require();

        Session::put(self::SETUP, Totp::secret());
        $this->redirect('/profile/two-factor');
    }

    public function confirm(): void
    {
        Auth::require();

        $secret = Session::get(self::SETUP);

        if (!is_string($secret)) {
            $this->redirect('/profile/two-factor');
        }

        $codes = (new TwoFactor())->enable((int) Auth::id(), $secret, $this->input('code'));

        if ($codes === null) {
            $this->flash(__('That code is not right. Check that the app shows {app}, and type the code showing now.', ['app' => \CantoTrack\Core\Config::get('app.name', 'CantoTrack')]), 'danger');
            $this->redirect('/profile/two-factor');
        }

        Session::forget(self::SETUP);
        Session::put(self::NEW_CODES, $codes);
        Auth::refresh();

        \CantoTrack\Service\AuditLog::record('two_factor_on', 'user', Auth::id(), (string) (Auth::user()['email'] ?? ''));
        $this->flash(__('Two-step sign-in is on. Keep the recovery codes below somewhere safe.'));
        $this->redirect('/profile/two-factor');
    }

    public function cancel(): void
    {
        Auth::require();

        Session::forget(self::SETUP);
        $this->redirect('/profile/two-factor');
    }

    public function disable(): void
    {
        Auth::require();
        $this->passwordAgain();

        (new TwoFactor())->disable((int) Auth::id());
        Auth::refresh();

        \CantoTrack\Service\AuditLog::record('two_factor_off', 'user', Auth::id(), (string) (Auth::user()['email'] ?? ''));
        $this->flash(__('Two-step sign-in is off. The password alone signs you in again.'), 'warning');
        $this->redirect('/profile/two-factor');
    }

    public function recoveryCodes(): void
    {
        Auth::require();
        $this->passwordAgain();

        Session::put(self::NEW_CODES, (new TwoFactor())->newRecoveryCodes((int) Auth::id()));

        $this->flash(__('New recovery codes. The old ones no longer work.'));
        $this->redirect('/profile/two-factor');
    }

    /** For an administrator, when somebody has lost their phone and their codes. */
    public function reset(int $userId): void
    {
        Auth::requireAdmin();

        $person = (new UserRepository())->find($userId);

        if ($person === null) {
            $this->notFound(__('There is no such person.'));
        }

        (new TwoFactor())->disable($userId);
        \CantoTrack\Service\AuditLog::record('two_factor_off', 'user', $userId, (string) $person['email'], 'by an administrator');

        $this->flash(__('Two-step sign-in is off for {name}. They can turn it on again from their profile.', ['name' => $person['name']]), 'warning');
        $this->redirect('/people/' . $userId . '/edit');
    }

    private function passwordAgain(): void
    {
        $me = (array) Auth::user();

        if (Auth::verifyCredentials((string) $me['email'], (string) ($_POST['password'] ?? '')) === null) {
            $this->flash(__('That is not your password.'), 'danger');
            $this->redirect('/profile/two-factor');
        }
    }
}
