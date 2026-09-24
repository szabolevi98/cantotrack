<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Config;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Mailer;
use CantoTrack\Service\Outbox;

/**
 * The administrators' view of the email: what is waiting to go, what the
 * mail server would not take and why, and what went — with a message sent
 * again by hand, the queue sent now, and a test message to oneself to see
 * that the configuration works at all.
 */
class OutboxController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $outbox = new Outbox();
        $config = (array) (Config::get('mail') ?? []);

        $this->render('settings/outbox.twig', [
            'counts' => $outbox->counts(),
            'failed' => $outbox->messages('failed'),
            'queued' => $outbox->messages('waiting'),
            'sent' => $outbox->messages('sent', 30),
            'transport' => Mailer::isConfigured() ? Mailer::transport() : 'none',
            'from' => (string) ($config['from'] ?? $config['from_address'] ?? ''),
        ]);
    }

    /** What is due, sent now rather than within the minute. */
    public function sendNow(): void
    {
        Auth::requireAdmin();

        $sent = (new Outbox())->sendDue();

        $this->flash(__n('{count} message sent.', '{count} messages sent.', $sent));
        $this->redirect('/settings/email');
    }

    public function retry(int $id): void
    {
        Auth::requireAdmin();

        $outbox = new Outbox();

        if (!$outbox->retry($id)) {
            $this->notFound(__('There is no such message waiting.'));
        }

        $outbox->sendDue(10);

        $this->flash(__('Tried again.'));
        $this->redirect('/settings/email');
    }

    /** A message to oneself, sent at once, to see that email works. */
    public function test(): void
    {
        Auth::requireAdmin();

        $me = (array) Auth::user();

        if (!Mailer::isConfigured()) {
            $this->flash(__('Email is turned off in config.ini, so there is nothing to test.'), 'danger');
            $this->redirect('/settings/email');
        }

        $sent = Mailer::sendNow(
            (string) $me['email'],
            (string) $me['name'],
            __('A test message from {app}', ['app' => Config::get('app.name', 'CantoTrack')]),
            __("If you are reading this, {app} can send email.\n\nIt was sent from the Email page, by you.", ['app' => Config::get('app.name', 'CantoTrack')]),
            'test'
        );

        if ($sent) {
            $this->flash(__('Sent to {email}. If it does not arrive, look in the spam folder first.', ['email' => $me['email']]));
        } elseif (!Mailer::reachable((string) $me['email'])) {
            $this->flash(__('{email} is an address no mail reaches, so nothing was sent.', ['email' => $me['email']]), 'warning');
        } else {
            $this->flash(__('It did not go; the reason is below, and it will be tried again.'), 'danger');
        }

        $this->redirect('/settings/email');
    }
}
