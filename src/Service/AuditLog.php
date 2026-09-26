<?php

namespace CantoTrack\Service;

use CantoTrack\Core\Auth;
use CantoTrack\Core\ClientIp;
use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\Logger;

/**
 * The record of who did what to the installation — see the 0040 migration.
 *
 * One line per thing done, in the name of whoever is signed in (or of the
 * person given, for a sign-in, when nobody is yet). A record that cannot be
 * written is logged and let go: it must never stop what it records.
 */
final class AuditLog
{
    /** What can be recorded, grouped the way the log's filter offers them. */
    public const GROUPS = [
        'access' => ['signin', 'signin_failed', 'signout', 'signed_out_elsewhere', 'password_changed', 'password_reset', 'two_factor_on', 'two_factor_off', 'token_created', 'token_revoked'],
        'people' => ['person_created', 'person_updated', 'person_password_reset', 'service_created', 'service_updated', 'service_token_created', 'service_token_revoked'],
        'projects' => ['project_created', 'project_updated', 'project_deleted', 'member_added', 'member_removed', 'member_role', 'column_created', 'column_updated', 'column_deleted', 'moves_saved', 'field_created', 'field_updated', 'field_deleted'],
        'work' => ['ticket_deleted', 'page_deleted'],
        'settings' => ['settings_lock', 'settings_currency', 'holiday_added', 'holiday_removed', 'rule_created', 'rule_updated', 'rule_toggled', 'rule_deleted', 'webhook_created', 'webhook_updated', 'webhook_deleted'],
    ];

    /**
     * @param array<string, mixed>|null $as the person it is done by, when nobody is signed in yet
     */
    public static function record(string $action, ?string $subjectType = null, ?int $subjectId = null, string $label = '', string $details = '', ?array $as = null): void
    {
        try {
            $user = $as ?? Auth::user();

            DatabaseConnection::get()->prepare(
                'INSERT INTO audit_log (user_id, user_name, action, subject_type, subject_id, subject_label, details, ip)
                 VALUES (:user, :name, :action, :type, :subject, :label, :details, :ip)'
            )->execute([
                'user' => $user === null ? null : (int) $user['id'],
                'name' => $user === null ? null : mb_substr((string) $user['name'], 0, 160),
                'action' => $action,
                'type' => $subjectType,
                'subject' => $subjectId,
                'label' => $label === '' ? null : mb_substr($label, 0, 255),
                'details' => $details === '' ? null : mb_substr($details, 0, 1000),
                'ip' => PHP_SAPI === 'cli' ? null : ClientIp::get(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('An audit record could not be written: ' . $e->getMessage(), ['action' => $action]);
        }
    }

    /**
     * What changed between two versions of something, in a few words:
     * "role: member → admin, active: yes → no".
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function changes(array $before, array $after): string
    {
        $parts = [];

        foreach ($after as $key => $value) {
            $old = $before[$key] ?? null;
            $shown = static fn(mixed $v): string => is_bool($v) ? ($v ? 'yes' : 'no') : ($v === null || $v === '' ? '—' : (string) $v);

            if ($shown($old) !== $shown($value)) {
                $parts[] = $key . ': ' . $shown($old) . ' → ' . $shown($value);
            }
        }

        return implode(', ', $parts);
    }
}
