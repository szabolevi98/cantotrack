<?php

/**
 * The daily automation rules: every ticket that matches one gets what it
 * says, once a day. Meant for cron, early in the morning:
 *
 *   15 7 * * * www-data php /path/to/bin/automation.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

CantoTrack\Core\Config::load(dirname(__DIR__) . '/config/config.ini');
date_default_timezone_set((string) CantoTrack\Core\Config::get('app.timezone', 'Europe/Budapest'));

// The notifications and the webhooks hear what the rules do, as they would
// from a person.
CantoTrack\Service\Notifier::register();
CantoTrack\Service\Webhooks::register();

$done = (new CantoTrack\Service\Automation())->daily();

printf('%d ticket%s changed by the daily rules.%s', $done, $done === 1 ? '' : 's', PHP_EOL);
