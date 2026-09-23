<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\ClientIp;
use CantoTrack\Core\Controller;
use CantoTrack\Core\LoginThrottle;
use CantoTrack\Core\Recaptcha;
use CantoTrack\Core\Session;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\TwoFactor;

/**
 * Signing in and out.
 *
 * The form says the same thing whether the address is unknown or the password
 * is wrong. Telling the two apart is a small kindness to whoever mistyped, and a
 * list of valid addresses to everybody else.
 */
class LoginController extends Controller
{
    private const PENDING = '_two_factor';

    public function show(): void
    {
        if (Auth::check()) {
            $this->goHome();
        }

        // Both keys, always. With strict variables on, a template that reads
        // something the controller did not pass is an error rather than an
        // empty string — which is the point of the setting, and means the
        // controller has to say "no error" out loud.
        $this->render('auth/login.twig', ['recaptcha_key' => Recaptcha::forPage()] + ['email' => '', 'error' => null]);
    }

    public function submit(): void
    {
        $email = $this->input('email');
        $password = (string) ($_POST['password'] ?? '');
        $ip = ClientIp::get();
        $throttle = new LoginThrottle();

        // Checked before the password, so that an address under attack costs
        // the attacker nothing more than this sentence — not a hash per guess.
        if ($throttle->isBlocked($email, $ip)) {
            $this->render('auth/login.twig', ['recaptcha_key' => Recaptcha::forPage()] + [
                'email' => $email,
                'error' => __('Too many failed attempts. Wait {minutes} minutes and try again.', [
                    'minutes' => LoginThrottle::WINDOW_MINUTES,
                ]),
            ], 429);

            return;
        }

        // Before the password: a script that fails the check learns nothing
        // about the account, and costs no hash.
        if (!Recaptcha::verify($this->input('recaptcha_token'), 'login')) {
            $this->render('auth/login.twig', [
                'email' => $email,
                'error' => __('The check that you are a person and not a script did not go through. Try again.'),
                'recaptcha_key' => Recaptcha::forPage(),
            ], 403);

            return;
        }

        $user = Auth::verifyCredentials($email, $password);

        if ($user === null) {
            $throttle->recordFailure($email, $ip);

            // 401 rather than 200, so that the failure is visible to anything
            // reading the response rather than only to a person looking at it.
            $this->render('auth/login.twig', ['recaptcha_key' => Recaptcha::forPage()] + [
                'email' => $email,
                'error' => __('That email address and password do not match an account.'),
            ], 401);

            return;
        }

        // With two-step sign-in on, the password only gets as far as the
        // second question. The failures count stays until that is answered
        // too: a guessed password is not a free run at the codes.
        if (TwoFactor::isOn($user)) {
            Session::regenerate();
            Session::put(self::PENDING, ['user' => (int) $user['id'], 'email' => $email, 'at' => time()]);
            $this->redirect('/login/code');
        }

        $throttle->clear($email);
        Auth::signIn($user);

        $this->goHome();
    }

    /** The second question: a code from the app, or a recovery code. */
    public function showCode(): void
    {
        if ($this->pending() === null) {
            $this->redirect('/login');
        }

        $this->render('auth/code.twig', ['error' => null]);
    }

    public function submitCode(): void
    {
        $pending = $this->pending();

        if ($pending === null) {
            $this->flash(__('That took too long. Sign in again.'), 'warning');
            $this->redirect('/login');
        }

        $ip = ClientIp::get();
        $throttle = new LoginThrottle();
        $email = $pending['email'];

        if ($throttle->isBlocked($email, $ip)) {
            Session::forget(self::PENDING);
            $this->render('auth/login.twig', ['recaptcha_key' => Recaptcha::forPage()] + [
                'email' => $email,
                'error' => __('Too many failed attempts. Wait {minutes} minutes and try again.', ['minutes' => LoginThrottle::WINDOW_MINUTES]),
            ], 429);

            return;
        }

        $user = (new UserRepository())->findActive($pending['user']);

        if ($user === null || !(new TwoFactor())->check($user, $this->input('code'))) {
            $throttle->recordFailure($email, $ip);
            $this->render('auth/code.twig', ['error' => __('That code is not right. Codes change every 30 seconds — try the one showing now.')], 401);

            return;
        }

        Session::forget(self::PENDING);
        $throttle->clear($email);
        Auth::signIn($user);

        $this->goHome();
    }

    /**
     * Who got the password right a moment ago, if it was a moment ago. Five
     * minutes, then it is the password again.
     *
     * @return array{user: int, email: string, at: int}|null
     */
    private function pending(): ?array
    {
        $pending = Session::get(self::PENDING);

        if (!is_array($pending) || !isset($pending['user'], $pending['email'], $pending['at']) || time() - (int) $pending['at'] > 300) {
            return null;
        }

        return ['user' => (int) $pending['user'], 'email' => (string) $pending['email'], 'at' => (int) $pending['at']];
    }

    public function logout(): void
    {
        Auth::logout();

        $this->redirect('/login');
    }

    /**
     * Where a successful login lands: back where the person was going, or the
     * dashboard. The stored path is checked to be a path — a full URL there
     * would turn the login into an open redirect.
     */
    private function goHome(): never
    {
        $intended = Session::get('_intended');
        Session::forget('_intended');

        $this->redirect(is_string($intended) && self::isLocalPath($intended) ? $intended : '/');
    }
}
