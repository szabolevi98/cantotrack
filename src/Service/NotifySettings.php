<?php

namespace CantoTrack\Service;

/**
 * What a person wants to hear about, and how — see the 0037 migration.
 *
 * Five kinds of thing, each told in the application and by email, in the
 * application only, or not at all. Somebody who never chose has everything
 * both ways, which is how it always was; somebody who had turned email off
 * before there was a choice has everything in the application only.
 */
final class NotifySettings
{
    /** The kinds, in the order the profile lists them. */
    public const KINDS = ['assigned', 'mentioned', 'status', 'commented', 'changes'];

    public const WAYS = ['email', 'app', 'off'];

    /**
     * How the person hears about each kind.
     *
     * @param array<string, mixed> $user
     * @return array<string, string>
     */
    public static function of(array $user): array
    {
        $chosen = json_decode((string) ($user['notify_prefs'] ?? ''), true);
        $chosen = is_array($chosen) ? $chosen : [];
        $emailAtAll = (int) ($user['notify_email'] ?? 1) === 1;
        $ways = [];

        foreach (self::KINDS as $kind) {
            $way = in_array($chosen[$kind] ?? null, self::WAYS, true) ? (string) $chosen[$kind] : 'email';
            $ways[$kind] = $way === 'email' && !$emailAtAll ? 'app' : $way;
        }

        return $ways;
    }

    /** Which kind a notification is: why the person is told, and what happened. */
    public static function kindOf(string $reason, string $kind): string
    {
        return match (true) {
            $reason === 'assigned' => 'assigned',
            $reason === 'mentioned' => 'mentioned',
            $kind === 'status' => 'status',
            $kind === 'commented' => 'commented',
            default => 'changes',
        };
    }

    /** @param array<string, mixed> $user */
    public static function way(array $user, string $reason, string $kind): string
    {
        return self::of($user)[self::kindOf($reason, $kind)];
    }

    /**
     * The choices from the profile's form, as they are kept.
     *
     * @param array<array-key, mixed> $posted kind => way
     */
    public static function encode(array $posted): string
    {
        $kept = [];

        foreach (self::KINDS as $kind) {
            $kept[$kind] = in_array($posted[$kind] ?? null, self::WAYS, true) ? (string) $posted[$kind] : 'email';
        }

        return (string) json_encode($kept);
    }
}
