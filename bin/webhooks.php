<?php

/**
 * Sends the webhook messages whose turn it is: the ones a web request left
 * for later, and the failed ones due another try. Run it from cron, every
 * minute:
 *
 *   * * * * * www-data php /var/www/cantotrack/bin/webhooks.php
 *
 * Also clears delivered messages older than a month out of the log.
 */

use CantoTrack\Core\Config;
use CantoTrack\Model\WebhookRepository;
use CantoTrack\Service\Webhooks;

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

$sent = (new Webhooks())->sendDue();
$pruned = (new WebhookRepository())->prune();

if (in_array('-v', $argv, true)) {
    printf('%d delivered, %d old messages cleared.%s', $sent, $pruned, PHP_EOL);
}
