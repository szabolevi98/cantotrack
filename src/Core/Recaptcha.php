<?php

namespace CantoTrack\Core;

/**
 * Google reCAPTCHA v3 in front of the sign-in and the lost-password form: no
 * puzzle to solve, a score from 0 (a script) to 1 (a person) for each
 * submission, and a submission under the threshold refused.
 *
 * On only when both keys are in config.ini — a development machine, the CI
 * and an installation that does not want Google on its sign-in page simply
 * go without. The keys are the installation's own and never committed.
 *
 * Its scripts come from Google, so the pages that use it widen their
 * Content-Security-Policy for exactly those addresses (Csp::send()).
 */
final class Recaptcha
{
    private const VERIFY = 'https://www.google.com/recaptcha/api/siteverify';

    public static function enabled(): bool
    {
        return self::siteKey() !== '' && trim((string) Config::get('recaptcha.secret_key', '')) !== '';
    }

    public static function siteKey(): string
    {
        return trim((string) Config::get('recaptcha.site_key', ''));
    }

    /**
     * The site key for a page that asks for a token, or null when the check
     * is off — and the page's policy widened to let Google's script in.
     */
    public static function forPage(): ?string
    {
        if (!self::enabled()) {
            return null;
        }

        Csp::send(true);

        return self::siteKey();
    }

    /**
     * Whether a token from the page is a person doing what the page is for.
     * Always true while the check is off.
     */
    public static function verify(string $token, string $action): bool
    {
        if (!self::enabled()) {
            return true;
        }

        if ($token === '') {
            return false;
        }

        $handle = curl_init(self::VERIFY);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => trim((string) Config::get('recaptcha.secret_key', '')),
                'response' => $token,
                'remoteip' => ClientIp::get(),
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($handle);
        curl_close($handle);

        if (!is_string($response)) {
            // Google cannot be reached. Refused rather than waved through: a
            // check that anybody can switch off by making it time out is not
            // one.
            Logger::error('reCAPTCHA could not be asked.');

            return false;
        }

        $result = json_decode($response, true);

        return is_array($result) && self::judge(
            $result,
            $action,
            (string) parse_url((string) Config::get('app.base_url', ''), PHP_URL_HOST),
            (float) Config::get('recaptcha.min_score', 0.5)
        );
    }

    /**
     * Google's answer, judged: a success, for this action, on this site, and
     * a score at or over the threshold.
     *
     * @param array<string, mixed> $result
     */
    public static function judge(array $result, string $action, string $host, float $minScore): bool
    {
        if (($result['success'] ?? false) !== true || ($result['action'] ?? '') !== $action) {
            return false;
        }

        if ($host !== '' && isset($result['hostname']) && strcasecmp((string) $result['hostname'], $host) !== 0) {
            return false;
        }

        return (float) ($result['score'] ?? 0) >= $minScore;
    }
}
