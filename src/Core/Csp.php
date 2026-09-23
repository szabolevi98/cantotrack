<?php

namespace CantoTrack\Core;

/**
 * The Content-Security-Policy every page is sent with.
 *
 * `script-src 'self'` is the one that matters most: there is not a single
 * inline script or event handler in the markup, so text that somebody manages
 * to get onto a page — a ticket title, a comment, a name — cannot run even if
 * it slipped past the escaping. Styles allow inline attributes, because the
 * progress bars are a width, and a width is not an attack.
 *
 * The sign-in page is the one exception, and only while reCAPTCHA is on: its
 * script and its frame come from Google, and the policy names exactly those
 * two addresses for that page.
 */
final class Csp
{
    public static function send(bool $recaptcha = false): void
    {
        if (headers_sent()) {
            return;
        }

        $google = $recaptcha ? ' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/' : '';

        header(
            "Content-Security-Policy: default-src 'self'; script-src 'self'" . $google . '; '
            . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; "
            . "connect-src 'self'" . $google . "; frame-src 'self'" . ($recaptcha ? ' https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/' : '') . '; '
            . "form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'"
        );
    }
}
