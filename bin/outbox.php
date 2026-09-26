<?php

/**
 * Sends the email whose turn it is: what the pages left in the outbox, and
 * the messages due another try. Run it from cron, every minute:
 *
 *   * * * * * www-data php /var/www/cantotrack/bin/outbox.php
 *
 * Before that it writes the notification emails that have waited long
 * enough — a ticket's changes of the last few minutes in one message (see
 * Notifier::sendDue). Also clears sent messages older than a month out of it.
 */

use CantoTrack\Core\Config;
use CantoTrack\Service\Notifier;
use CantoTrack\Service\Outbox;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$configPath = dirname(__DIR__) . '/config/config.ini';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--config=')) {
        $configPath = substr($argument, strlen('--config='));
    }
}

Config::load($configPath);
date_default_timezone_set((string) Config::get('app.timezone', 'Europe/Budapest'));

// The notification emails whose few minutes of waiting are over go into
// the outbox first, so they are sent in this same run.
$written = (new Notifier())->sendDue();

$outbox = new Outbox();
$sent = $outbox->sendDue();
$pruned = $outbox->prune();

if (in_array('-v', $argv, true)) {
    $counts = $outbox->counts();
    printf('%d notification emails written, %d sent, %d waiting, %d failed, %d old ones cleared.%s', $written, $sent, $counts['waiting'], $counts['failed'], $pruned, PHP_EOL);
}
