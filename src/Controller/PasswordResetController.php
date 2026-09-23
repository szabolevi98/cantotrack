<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\ClientIp;
use CantoTrack\Core\Config;
use CantoTrack\Core\Controller;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\LoginThrottle;
use CantoTrack\Core\Mailer;
use CantoTrack\Core\Password;
use CantoTrack\Model\UserRepository;

/**
 * A way back in for somebody who lost their password: a link by email, good
 * for one hour and one use.
 *
 * The form answers the same whether or not the address has an account —
 * "if it has one, a link is on its way" — for the same reason the login form
 * does not say which half was wrong. And it sits behind the same limit as the
 * login form, so it cannot be used to fill somebody's inbox.
 *
 * Without mail configured there is no link to send, and the page says to ask
 * an administrator, who can make a new password from the people page.
 */
class PasswordResetController extends Controller
{
    private const LIFETIME_MINUTES = 60;

    public function form(): void
    {
        $this->render('auth/forgot.twig', ['sent' => false, 'mail' => Mailer::isConfigured(), 'email' => '']);
    }

    public function send(): void
    {
        $email = mb_strtolower($this->input('email'));
        $throttle = new LoginThrottle();
        $ip = ClientIp::get();

        if ($throttle->isBlocked($email, $ip)) {
            $this->render('auth/forgot.twig', ['sent' => true, 'mail' => true, 'email' => $email], 429);

            return;
        }

        // Counted like a failed sign-in, so asking over and over is limited
        // exactly as guessing is.
        $throttle->recordFailure($email, $ip);

        $user = (new UserRepository())->findByEmail($email);

        if ($user !== null && (int) $user['is_active'] === 1 && Mailer::isConfigured()) {
            $token = bin2hex(random_bytes(32));
            $db = DatabaseConnection::get();

            // One link at a time: a new one replaces any that was sent before.
            $db->prepare('DELETE FROM password_resets WHERE user_id = :user')->execute(['user' => $user['id']]);
            $db->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at)
                 VALUES (:user, :hash, NOW() + INTERVAL ' . self::LIFETIME_MINUTES . ' MINUTE)'
            )->execute(['user' => $user['id'], 'hash' => hash('sha256', $token)]);

            $link = rtrim((string) Config::get('app.base_url'), '/') . '/password/reset/' . $token;

            Mailer::send(
                (string) $user['email'],
                (string) $user['name'],
                __('A new password for {app}', ['app' => Config::get('app.name', 'CantoTrack')]),
                __("Somebody — hopefully you — asked for a new password.\n\nChoose one here, within an hour:\n{link}\n\nIf it was not you, ignore this message: your password stays as it is.", ['link' => $link])
            );
        }

        $this->render('auth/forgot.twig', ['sent' => true, 'mail' => Mailer::isConfigured(), 'email' => $email]);
    }

    public function resetForm(string $token): void
    {
        $this->render('auth/reset.twig', ['token' => $token, 'valid' => $this->resetFor($token) !== null, 'error' => null]);
    }

    public function reset(string $token): void
    {
        $reset = $this->resetFor($token);

        if ($reset === null) {
            $this->render('auth/reset.twig', ['token' => $token, 'valid' => false, 'error' => null], 410);

            return;
        }

        $users = new UserRepository();
        $user = $users->find((int) $reset['user_id']);
        $new = (string) ($_POST['new_password'] ?? '');

        $error = match (true) {
            $user === null => __('That account no longer exists.'),
            $new !== (string) ($_POST['new_password_again'] ?? '') => __('The two new passwords are not the same.'),
            default => Password::problem($new, (string) $user['email']),
        };

        if ($error !== null || $user === null) {
            $this->render('auth/reset.twig', ['token' => $token, 'valid' => true, 'error' => $error], 422);

            return;
        }

        $users->setPassword((int) $user['id'], $new);
        DatabaseConnection::get()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id')
            ->execute(['id' => $reset['id']]);
        (new LoginThrottle())->clear((string) $user['email']);

        $this->flash(__('Your password is changed. Sign in with the new one.'));
        $this->redirect('/login');
    }

    /** The reset a link stands for, if it is unused and still in time. */
    private function resetFor(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $statement = DatabaseConnection::get()->prepare(
            'SELECT * FROM password_resets WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()'
        );
        $statement->execute(['hash' => hash('sha256', $token)]);

        return $statement->fetch() ?: null;
    }
}
