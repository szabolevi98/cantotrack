<?php

/**
 * The morning digests: each person who asked for one gets what their query
 * finds, what of theirs is due this week, and their unread notifications.
 * Working days only, once a day. Meant for cron:
 *
 *   30 7 * * 1-5 www-data php /path/to/bin/digest.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

CantoTrack\Core\Config::load(dirname(__DIR__) . '/config/config.ini');
date_default_timezone_set((string) CantoTrack\Core\Config::get('app.timezone', 'Europe/Budapest'));

$sent = (new CantoTrack\Service\Digest())->sendDue(new DateTimeImmutable('today'));

// And, once a morning, the old notifications cleared: read ones after half a
// year, any after a year.
$cleared = (new CantoTrack\Model\NotificationRepository())->prune();

// The API's Idempotency-Keys are kept for a day; a key is forgotten as it is
// used again anyway, and this takes the rest.
(new CantoTrack\Model\IdempotencyRepository())->prune();

printf('%d digest%s sent, %d old notifications cleared.%s', $sent, $sent === 1 ? '' : 's', $cleared, PHP_EOL);
