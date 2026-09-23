<?php

namespace CantoTrack\Core;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sending email: notifications, and the link for a lost password.
 *
 * Configured by a DSN, the way the mail library understands it:
 *
 *   [mail]
 *   dsn = "smtp://user:password@smtp.example.com:587"
 *   from = "cantotrack@example.com"
 *
 * Two values of this application's own: "file" writes every message to
 * var/mail as an .eml file instead of sending it — for development, where
 * nothing should reach a real inbox — and an empty DSN means there is no mail
 * at all. Then the application says so where it matters: a lost password is
 * something to ask an administrator about, rather than a link that never
 * arrives.
 */
class Mailer
{
    /** @var list<Email> What was sent this request, for the tests. */
    public static array $sent = [];

    public static function isConfigured(): bool
    {
        try {
            return trim((string) Config::get('mail.dsn', '')) !== '';
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Sends one message, in plain text. Returns whether it went: a mail
     * server that is down must not take the page that triggered the message
     * with it, so a failure is logged and answered with false.
     */
    public static function send(string $toAddress, string $toName, string $subject, string $text): bool
    {
        if (!self::isConfigured()) {
            return false;
        }

        $from = (string) Config::get('mail.from', 'cantotrack@localhost');
        $email = (new Email())
            ->from(new Address($from, (string) Config::get('app.name', 'CantoTrack')))
            ->to(new Address($toAddress, $toName))
            ->subject($subject)
            ->text($text);

        self::$sent[] = $email;
        $dsn = trim((string) Config::get('mail.dsn'));

        try {
            if ($dsn === 'file') {
                $folder = dirname(__DIR__, 2) . '/var/mail';
                @mkdir($folder, 0775, true);
                file_put_contents($folder . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml', $email->toString());

                return true;
            }

            (new SymfonyMailer(Transport::fromDsn($dsn)))->send($email);

            return true;
        } catch (\Throwable $e) {
            Logger::error('Mail could not be sent: ' . $e->getMessage(), ['to' => $toAddress, 'subject' => $subject]);

            return false;
        }
    }
}
