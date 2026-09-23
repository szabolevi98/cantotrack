<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\I18n;
use CantoTrack\Core\Session;
use CantoTrack\Model\UserRepository;

/**
 * The language switch: the top bar's menu, and the two words under the
 * sign-in form.
 *
 * Signed in, the choice is saved on the profile, so it follows the person to
 * every device; before that it lives in the session — somebody who switches
 * the sign-in page to Hungarian gets a Hungarian dashboard after it, unless
 * their profile says otherwise.
 */
class LocaleController extends Controller
{
    public const SESSION_KEY = '_locale';

    public function change(): void
    {
        $locale = $this->input('locale');

        if (!array_key_exists($locale, I18n::LOCALES)) {
            $this->back('/');
        }

        Session::put(self::SESSION_KEY, $locale);

        if (Auth::check()) {
            (new UserRepository())->setLocale((int) Auth::id(), $locale);
        }

        $this->back(Auth::check() ? '/' : '/login');
    }
}
