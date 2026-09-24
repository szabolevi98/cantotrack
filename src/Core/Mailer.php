<?php

namespace CantoTrack\Core;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sending email: notifications, digests, and the link for a lost password.
 *
 * How it goes out is chosen in config.ini:
 *
 *   [mail]
 *   transport = mail      ; PHP's own mail(), through the machine's sendmail
 *   transport = smtp      ; a mail server: host, port, username, password, encryption
 *   transport = file      ; written to var/mail as .eml files — for development,
 *                         ; where nothing should reach a real inbox
 *   transport = none      ; no email at all
 *   from = "cantotrack@example.com"
 *   from_name = "CantoTrack"
 *
 * With no email, the application says so where it matters: a lost password
 * is something to ask an administrator about, rather than a link that never
 * arrives. An older configuration with only a `dsn` (the mail library's
 * own way of writing a server) still works as it did.
 */
class Mailer
{
    /** @var list<Email> What was sent this request, for the tests. */
    public static array $sent = [];

    public const TRANSPORTS = ['mail', 'smtp', 'file', 'none'];

    /**
     * Endings of addresses no mail can reach — the names kept for examples,
     * tests and machines of one's own, and the demo's made-up companies. A
     * message to one would only come back as a bounce.
     */
    private const NOWHERE = ['test', 'example', 'invalid', 'localhost', 'local', 'demo'];

    /** Whether an address is somewhere mail can actually go. */
    public static function reachable(string $address): bool
    {
        $domain = strtolower((string) substr((string) strrchr($address, '@'), 1));
        $ending = (string) substr((string) strrchr('.' . $domain, '.'), 1);

        return $domain !== '' && !in_array($ending, self::NOWHERE, true);
    }

    public static function isConfigured(): bool
    {
        try {
            return self::transport() !== 'none';
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Which way email goes: the `transport` if one is chosen, or what an
     * older `dsn` amounts to — "file", "none", or "dsn" for a server
     * written out whole.
     *
     * @param array<string, mixed>|null $config the [mail] section, or null for the application's own
     */
    public static function transport(?array $config = null): string
    {
        $config ??= self::config();
        $chosen = strtolower(trim((string) ($config['transport'] ?? '')));

        if (in_array($chosen, self::TRANSPORTS, true)) {
            return $chosen;
        }

        $dsn = trim((string) ($config['dsn'] ?? ''));

        return match (true) {
            $dsn === '' => 'none',
            $dsn === 'file' => 'file',
            default => 'dsn',
        };
    }

    /**
     * The mail library's address for the server: from the SMTP fields, or
     * the older `dsn` as it was written.
     *
     * @param array<string, mixed> $config the [mail] section
     */
    public static function serverDsn(array $config): string
    {
        if (self::transport($config) === 'dsn') {
            return trim((string) $config['dsn']);
        }

        $encryption = strtolower(trim((string) ($config['encryption'] ?? 'tls')));
        $user = trim((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');
        $login = $user === '' ? '' : rawurlencode($user) . ($password === '' ? '' : ':' . rawurlencode($password)) . '@';
        $port = (int) ($config['port'] ?? 0) ?: ($encryption === 'ssl' ? 465 : 587);

        // "tls" is STARTTLS on the usual port, which the library does by
        // itself; "ssl" is a connection encrypted from the start; "none"
        // is neither, for a server on the same machine.
        return ($encryption === 'ssl' ? 'smtps' : 'smtp') . '://' . $login . trim((string) ($config['host'] ?? ''))
            . ':' . $port . ($encryption === 'none' ? '?auto_tls=false' : '');
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

        $config = self::config();
        $from = (string) ($config['from'] ?? $config['from_address'] ?? 'cantotrack@localhost');
        $fromName = (string) ($config['from_name'] ?? Config::get('app.name', 'CantoTrack'));
        $email = (new Email())
            ->from(new Address($from, $fromName))
            ->to(new Address($toAddress, $toName))
            ->subject($subject)
            ->text($text);

        self::$sent[] = $email;
        $transport = self::transport($config);

        // Written to a file, anything goes; sent for real, not to nowhere.
        if ($transport !== 'file' && !self::reachable($toAddress)) {
            return false;
        }

        try {
            return match ($transport) {
                'file' => self::toFile($email),
                'mail' => self::withMail($email, $from),
                default => self::toServer($email, self::serverDsn($config)),
            };
        } catch (\Throwable $e) {
            Logger::error('Mail could not be sent: ' . $e->getMessage(), ['to' => $toAddress, 'subject' => $subject]);

            return false;
        }
    }

    private static function toFile(Email $email): bool
    {
        $folder = dirname(__DIR__, 2) . '/var/mail';
        @mkdir($folder, 0775, true);

        return file_put_contents($folder . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml', $email->toString()) !== false;
    }

    private static function toServer(Email $email, string $dsn): bool
    {
        (new SymfonyMailer(Transport::fromDsn($dsn)))->send($email);

        return true;
    }

    /**
     * Through PHP's mail(): the message the library wrote, handed over as
     * mail() wants it — the recipient and the subject on their own, every
     * other header as they are — with the sender as the envelope's too, so
     * the address the mail comes from is the one its domain vouches for.
     */
    private static function withMail(Email $email, string $from): bool
    {
        $message = $email->toString();
        $split = strpos($message, "\r\n\r\n");
        $head = $split === false ? '' : substr($message, 0, $split);
        $body = $split === false ? $message : substr($message, $split + 4);

        $headers = [];
        $to = '';
        $subject = '';

        // Headers folded over several lines are one header.
        foreach (preg_split('/\r\n(?![ \t])/', $head) ?: [] as $header) {
            if (stripos($header, 'To:') === 0) {
                $to = trim(substr($header, 3));
            } elseif (stripos($header, 'Subject:') === 0) {
                $subject = trim(substr($header, 8));
            } elseif ($header !== '') {
                $headers[] = $header;
            }
        }

        $envelope = preg_match('/^[^\s@\'"]+@[^\s@\'"]+$/', $from) === 1 ? '-f' . $from : '';

        return mail($to, $subject, $body, implode("\r\n", $headers), $envelope);
    }

    /** @return array<string, mixed> the [mail] section */
    private static function config(): array
    {
        $section = Config::get('mail', []);

        return is_array($section) ? $section : [];
    }
}
