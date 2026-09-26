<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\ClientIp;
use CantoTrack\Core\Controller;
use CantoTrack\Core\HttpError;
use CantoTrack\Core\LoginThrottle;
use CantoTrack\Model\ApiTokenRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\AuditLog;
use CantoTrack\Service\Presenter;
use CantoTrack\Service\TwoFactor;

/**
 * Signing in to the API from an app, with the email address and password
 * instead of a token made by hand.
 *
 * It answers the same questions the login form does, in the same order and
 * under the same limit: the throttle first, then the password, then — with
 * two-step sign-in on — the code. What it gives back is a personal access
 * token made for the device, which ends with the person's sessions (see the
 * 0050 migration). There is no reCAPTCHA here, as there is none an app could
 * answer; the throttle is what stands in front of the password.
 */
class ApiAuthController extends Controller
{
    /**
     * {"email": "…", "password": "…", "code": "123 456", "device": "Pixel 8"}
     *
     * Without "code", a person with two-step sign-in on is answered 403 with
     * details.two_factor_required, so that the app can ask for it and send
     * the whole request again. That is not a failure: the password was right.
     * A wrong code is, and counts like a wrong password.
     */
    public function login(): never
    {
        $input = $this->body();
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $code = trim((string) ($input['code'] ?? ''));
        $ip = ClientIp::get();
        $throttle = new LoginThrottle();

        if ($throttle->isBlocked($email, $ip)) {
            header('Retry-After: ' . LoginThrottle::WINDOW_MINUTES * 60);
            throw new HttpError(429, __('Too many failed attempts. Wait {minutes} minutes and try again.', ['minutes' => LoginThrottle::WINDOW_MINUTES]));
        }

        $user = Auth::verifyCredentials($email, $password);

        if ($user === null) {
            $throttle->recordFailure($email, $ip);
            AuditLog::record('signin_failed', 'user', null, mb_substr($email, 0, 160));
            throw new HttpError(401, __('That email address and password do not match an account.'));
        }

        if (TwoFactor::isOn($user)) {
            if ($code === '') {
                throw new HttpError(403, __('Two-step sign-in is on: send the code from your app, or a recovery code.'), ['two_factor_required' => true]);
            }

            if (!(new TwoFactor())->check($user, $code)) {
                $throttle->recordFailure($email, $ip);
                throw new HttpError(401, __('That code is not right. Codes change every 30 seconds — try the one showing now.'), ['two_factor_required' => true]);
            }
        }

        $throttle->clear($email);

        $device = trim(preg_replace('/\s+/u', ' ', (string) ($input['device'] ?? '')) ?? '');
        $name = __('App') . ($device === '' ? '' : ' — ' . mb_substr($device, 0, 60));
        $token = (new ApiTokenRepository())->create((int) $user['id'], $name, null, true);

        (new UserRepository())->touchLastLogin((int) $user['id']);
        AuditLog::record('signin', 'user', (int) $user['id'], (string) $user['email'], $name, $user);

        $this->json(['data' => [
            'token' => $token,
            'user' => Presenter::person($user, true),
        ]], 201);
    }

    /**
     * What the request sent: a JSON object, or a form.
     *
     * @return array<string, mixed>
     */
    private function body(): array
    {
        if (!str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'json')) {
            return $_POST;
        }

        $data = json_decode((string) file_get_contents('php://input'), true);

        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new HttpError(400, __('The body has to be a JSON object.'));
        }

        return $data;
    }
}
