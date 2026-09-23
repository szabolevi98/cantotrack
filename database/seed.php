<?php

/**
 * Puts the first administrator in an empty installation, so that there is
 * somebody to sign in as.
 *
 * It refuses to touch a database that already has people in it: this is a
 * first-run step, not a way to reset a password.
 *
 *   php database/seed.php
 *   php database/seed.php --email=me@example.com --name="Szabó Levente"
 *
 * The password is generated and printed once. A seeded default password is the
 * kind of thing that survives to a live server, and "admin/admin" on something
 * reachable from the internet is not a risk anyone takes deliberately — it is
 * one everybody forgets about.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use CantoTrack\Core\Config;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Model\UserRepository;

$root = dirname(__DIR__);
$configPath = $root . '/config/config.ini';
$email = 'admin@cantotrack.local';
$name = 'Administrator';

foreach ($argv ?? [] as $argument) {
    if (str_starts_with($argument, '--config=')) {
        $given = substr($argument, strlen('--config='));
        $configPath = str_starts_with($given, '/') || preg_match('/^[A-Za-z]:/', $given) === 1
            ? $given
            : $root . '/' . $given;
    }

    if (str_starts_with($argument, '--email=')) {
        $email = substr($argument, strlen('--email='));
    }

    if (str_starts_with($argument, '--name=')) {
        $name = substr($argument, strlen('--name='));
    }
}

Config::load($configPath);

$database = DatabaseConnection::get();
$count = $database->prepare('SELECT COUNT(*) FROM users');
$count->execute();
$existing = (int) $count->fetchColumn();

if ($existing > 0) {
    printf('There are already %d user%s; nothing to seed.%s', $existing, $existing === 1 ? '' : 's', PHP_EOL);
    exit(0);
}

// Readable rather than maximal: this is typed once, by hand, from a terminal
// into a browser. Twelve characters from an unambiguous alphabet is far beyond
// what a login with a rate limit in front of it can be attacked through.
$alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$password = '';
for ($i = 0; $i < 12; $i++) {
    $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}

$id = (new UserRepository())->create($name, $email, $password, 'admin');

printf('%sCreated administrator #%d%s', PHP_EOL, $id, PHP_EOL);
printf('  email:    %s%s', $email, PHP_EOL);
printf('  password: %s%s', $password, PHP_EOL);
printf('%sThis password is not stored anywhere in readable form — write it down now.%s%s', PHP_EOL, PHP_EOL, PHP_EOL);
