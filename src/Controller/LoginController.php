<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Config;
use CantoTrack\Core\Session;
use CantoTrack\Core\View;

/**
 * Signing in and out.
 *
 * The form says the same thing whether the address is unknown or the password
 * is wrong. Telling the two apart is a small kindness to whoever mistyped, and a
 * list of valid addresses to everybody else.
 */
class LoginController
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
        View::render('auth/login.twig', ['email' => '', 'error' => null]);
    }

    public function submit(): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (Auth::attempt($email, $password)) {
            $this->goHome();
        }

        // 401 rather than 200, so that the failure is visible to anything
        // reading the response rather than only to a person looking at it.
        http_response_code(401);

        View::render('auth/login.twig', [
            'email' => $email,
            'error' => 'That email address and password do not match an account.',
        ]);
    }

    public function logout(): void
    {
        Auth::logout();

        header('Location: ' . Config::get('app.base_url') . '/login');
        exit;
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

        $path = is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')
            ? $intended
            : '/';

        header('Location: ' . rtrim((string) Config::get('app.base_url'), '/') . $path);
        exit;
    }
}
