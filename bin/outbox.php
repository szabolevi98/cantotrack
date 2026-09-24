<?php

/**
 * Sends the email whose turn it is: what the pages left in the outbox, and
 * the messages due another try. Run it from cron, every minute:
 *
 *   * * * * * www-data php /var/www/cantotrack/bin/outbox.php
 *
 * Also clears sent messages older than a month out of it.
 */

use CantoTrack\Core\Config;
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

$outbox = new Outbox();
$sent = $outbox->sendDue();
$pruned = $outbox->prune();

if (in_array('-v', $argv, true)) {
    $counts = $outbox->counts();
    printf('%d sent, %d waiting, %d failed, %d old ones cleared.%s', $sent, $counts['waiting'], $counts['failed'], $pruned, PHP_EOL);
}
