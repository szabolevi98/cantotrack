<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Controller;
use CantoTrack\Core\HttpError;
use CantoTrack\Model\SettingRepository;
use CantoTrack\Service\GitHubPush;

/**
 * Where other services send their news. No session and no CSRF token: each
 * proves who it is with a signature over what it sent, checked here.
 */
class IntegrationController extends Controller
{
    public const GITHUB_SECRET = 'github_secret';

    /**
     * GitHub's webhook, set to "application/json" and the push event, with
     * the secret from the Webhooks page.
     */
    public function github(): void
    {
        $secret = (new SettingRepository())->get(self::GITHUB_SECRET);

        if ($secret === null || $secret === '') {
            throw HttpError::notFound(__('The GitHub integration is not set up.'));
        }

        $body = (string) file_get_contents('php://input');
        $given = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');

        if (!hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $given)) {
            throw new HttpError(401, __('The signature does not match the secret.'));
        }

        $event = (string) ($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');

        if ($event === 'ping') {
            $this->json(['ok' => true]);
        }

        if ($event !== 'push') {
            $this->json(['ignored' => $event], 202);
        }

        // "application/x-www-form-urlencoded" sends the JSON as a field.
        if (str_starts_with($body, 'payload=')) {
            parse_str($body, $form);
            $body = is_string($form['payload'] ?? null) ? $form['payload'] : '';
        }

        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            throw new HttpError(400, __('The body has to be a JSON object.'));
        }

        $this->json((new GitHubPush())->handle($payload));
    }
}
