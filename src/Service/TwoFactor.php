<?php

namespace CantoTrack\Service;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use CantoTrack\Core\Config;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Totp;
use PDO;

/**
 * The second step at sign-in: a code from an authenticator app, or one of
 * ten recovery codes for the day the phone is not there.
 *
 * Turned on by the person themselves, on their profile, and only once they
 * have typed in a first code — a secret that was scanned wrong would
 * otherwise lock them out on their next sign-in.
 */
class TwoFactor
{
    public const RECOVERY_CODES = 10;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
    }

    public static function isOn(array $user): bool
    {
        return !empty($user['totp_secret']) && !empty($user['totp_enabled_at']);
    }

    /** The QR code for a new secret, as SVG markup, to be scanned into the app. */
    public static function qr(array $user, string $secret): string
    {
        $uri = Totp::uri((string) Config::get('app.name', 'CantoTrack'), (string) $user['email'], $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd()));

        // Without the XML declaration, so it can sit inside the page.
        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $writer->writeString($uri));
    }

    /**
     * Turns it on with a secret the person has proven they have, and returns
     * their recovery codes — shown once.
     *
     * @return list<string>|null null when the code was not right
     */
    public function enable(int $userId, string $secret, string $code): ?array
    {
        $step = Totp::verify($secret, $code);

        if ($step === null) {
            return null;
        }

        $this->db->prepare(
            'UPDATE users SET totp_secret = :secret, totp_enabled_at = NOW(), totp_last_step = :step WHERE id = :id'
        )->execute(['secret' => $secret, 'step' => $step, 'id' => $userId]);

        return $this->newRecoveryCodes($userId);
    }

    public function disable(int $userId): void
    {
        $this->db->prepare('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = :id')
            ->execute(['id' => $userId]);
        $this->db->prepare('DELETE FROM recovery_codes WHERE user_id = :id')->execute(['id' => $userId]);
    }

    /**
     * Whether a code from the app, or a recovery code, is right for this
     * person — and uses it up, so neither works twice.
     */
    public function check(array $user, string $given): bool
    {
        $given = trim($given);

        if (preg_match('/^\d{3}\s?\d{3}$/', $given) === 1) {
            $step = Totp::verify((string) $user['totp_secret'], $given, isset($user['totp_last_step']) ? (int) $user['totp_last_step'] : null);

            if ($step === null) {
                return false;
            }

            // Only moves forward: two sign-ins racing with the same code
            // cannot both win.
            $statement = $this->db->prepare(
                'UPDATE users SET totp_last_step = :step WHERE id = :id AND (totp_last_step IS NULL OR totp_last_step < :step2)'
            );
            $statement->execute(['step' => $step, 'step2' => $step, 'id' => $user['id']]);

            return $statement->rowCount() === 1;
        }

        return $this->useRecoveryCode((int) $user['id'], $given);
    }

    /** @return list<string> */
    public function newRecoveryCodes(int $userId): array
    {
        $this->db->prepare('DELETE FROM recovery_codes WHERE user_id = :id')->execute(['id' => $userId]);
        $insert = $this->db->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (:user, :hash)');
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            // Ten characters from an alphabet without look-alikes (no 0/o, 1/l).
            $code = '';
            for ($c = 0; $c < 10; $c++) {
                $code .= 'abcdefghjkmnpqrstuvwxyz23456789'[random_int(0, 30)];
            }

            $codes[] = substr($code, 0, 5) . '-' . substr($code, 5);
            $insert->execute(['user' => $userId, 'hash' => password_hash($code, PASSWORD_DEFAULT)]);
        }

        return $codes;
    }

    public function recoveryCodesLeft(int $userId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id = :id AND used_at IS NULL');
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    private function useRecoveryCode(int $userId, string $given): bool
    {
        $given = strtolower(str_replace(['-', ' '], '', $given));

        if (strlen($given) !== 10) {
            return false;
        }

        $statement = $this->db->prepare('SELECT id, code_hash FROM recovery_codes WHERE user_id = :id AND used_at IS NULL');
        $statement->execute(['id' => $userId]);

        foreach ($statement->fetchAll() as $row) {
            if (password_verify($given, (string) $row['code_hash'])) {
                $used = $this->db->prepare('UPDATE recovery_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
                $used->execute(['id' => $row['id']]);

                return $used->rowCount() === 1;
            }
        }

        return false;
    }
}
