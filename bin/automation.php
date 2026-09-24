<?php

/**
 * The morning's work: the tickets that repeat are made for today, and then
 * the daily automation rules run — every ticket that matches one gets what
 * it says, once a day, today's new ones included. Meant for cron, early in
 * the morning:
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

$made = (new CantoTrack\Service\Templates())->runDue(new DateTimeImmutable('today'));
$done = (new CantoTrack\Service\Automation())->daily();

printf('%d repeating ticket%s made, %d ticket%s changed by the daily rules.%s', count($made), count($made) === 1 ? '' : 's', $done, $done === 1 ? '' : 's', PHP_EOL);
