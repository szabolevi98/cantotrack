<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\ClientIp;
use CantoTrack\Core\Controller;
use CantoTrack\Core\LoginThrottle;
use CantoTrack\Core\Session;

/**
 * Signing in and out.
 *
 * The form says the same thing whether the address is unknown or the password
 * is wrong. Telling the two apart is a small kindness to whoever mistyped, and a
 * list of valid addresses to everybody else.
 */
class LoginController extends Controller
{
    public function show(): void
    {
        if (Auth::check()) {
            $this->goHome();
        }

        // Both keys, always. With strict variables on, a template that reads
        // something the controller did not pass is an error rather than an
        // empty string — which is the point of the setting, and means the
        // controller has to say "no error" out loud.
        $this->render('auth/login.twig', ['email' => '', 'error' => null]);
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
            $this->render('auth/login.twig', [
                'email' => $email,
                'error' => __('Too many failed attempts. Wait {minutes} minutes and try again.', [
                    'minutes' => LoginThrottle::WINDOW_MINUTES,
                ]),
            ], 429);

            return;
        }

        $user = Auth::verifyCredentials($email, $password);

        if ($user === null) {
            $throttle->recordFailure($email, $ip);

            // 401 rather than 200, so that the failure is visible to anything
            // reading the response rather than only to a person looking at it.
            $this->render('auth/login.twig', [
                'email' => $email,
                'error' => __('That email address and password do not match an account.'),
            ], 401);

            return;
        }

        $throttle->clear($email);
        Auth::signIn($user);

        $this->goHome();
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
