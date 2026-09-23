<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\I18n;
use CantoTrack\Core\Password;
use CantoTrack\Model\UserRepository;

/**
 * Your own account: what you are called, how the interface looks to you, and
 * your password.
 *
 * Until this page existed, nobody could change their own password. The only
 * one an account ever had was the one an administrator generated and read out
 * — which the administrator therefore also knew.
 */
class ProfileController extends Controller
{
    public function show(): void
    {
        Auth::require();

        $this->render('profile/index.twig', [
            'me' => Auth::user(),
            'locales' => I18n::LOCALES,
            'error' => null,
            'password_error' => null,
        ]);
    }

    public function update(): void
    {
        Auth::require();

        $me = (array) Auth::user();
        $name = $this->input('name');
        $locale = $this->input('locale');
        $theme = $this->input('theme', 'system');

        if ($name === '') {
            $this->render('profile/index.twig', [
                'me' => ['name' => $name, 'short_name' => $this->input('short_name')] + $me,
                'locales' => I18n::LOCALES,
                'error' => __('A person needs a name.'),
                'password_error' => null,
            ], 422);

            return;
        }

        (new UserRepository())->updateProfile(
            (int) $me['id'],
            $name,
            $this->input('short_name'),
            array_key_exists($locale, I18n::LOCALES) ? $locale : null,
            $theme,
            isset($_POST['notify_email'])
        );

        // The flash is written in the language just chosen, not the one the
        // page was in when the form was sent.
        if (array_key_exists($locale, I18n::LOCALES)) {
            I18n::setLocale($locale);
        }

        $this->flash(__('Your profile is saved.'));
        $this->redirect('/profile');
    }

    /**
     * The sidebar's switch: dark if the page was light, light if it was dark.
     *
     * The page says which it was, because with "the system's" chosen only the
     * browser knows; without a script it is the stored choice, and "the
     * system's" counts as light.
     */
    public function toggleTheme(): void
    {
        Auth::require();

        $shown = $this->input('shown');
        $shown = in_array($shown, ['light', 'dark'], true) ? $shown : ((Auth::user()['theme'] ?? '') === 'dark' ? 'dark' : 'light');

        (new UserRepository())->setTheme((int) Auth::id(), $shown === 'dark' ? 'light' : 'dark');

        $this->back('/');
    }

    public function changePassword(): void
    {
        Auth::require();

        $me = (array) Auth::user();
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $again = (string) ($_POST['new_password_again'] ?? '');

        // The current password is asked for even though the session is signed
        // in: a laptop left open is a signed-in session too, and changing the
        // password is how somebody at it would keep the account.
        $error = match (true) {
            Auth::verifyCredentials((string) $me['email'], $current) === null => __('That is not your current password.'),
            $new !== $again => __('The two new passwords are not the same.'),
            $new === $current => __('The new password is the one you already have.'),
            default => Password::problem($new, (string) $me['email']),
        };

        if ($error !== null) {
            $this->render('profile/index.twig', [
                'me' => $me,
                'locales' => I18n::LOCALES,
                'error' => null,
                'password_error' => $error,
            ], 422);

            return;
        }

        (new UserRepository())->setPassword((int) $me['id'], $new);

        $this->flash(__('Your password is changed.'));
        $this->redirect('/profile');
    }
}
