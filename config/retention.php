<?php

declare(strict_types=1);

/*
| Retention windows in days, consumed by model:prune (see App\Models\Concerns\PrunesByRetention).
| Set an env var to an empty value to disable pruning for that table.
| CDRs are tiered, not pruned: hot (main DB) -> warm (cdr_archive DB) -> cold (gzip CSV on the cdr_archive disk).
*/
$days = static function (string $key, int $default): ?int {
    $value = env($key, $default);

    return $value === null || $value === '' ? null : (int) $value;
};

return [
    'session_updates_days' => $days('RETENTION_SESSION_UPDATES_DAYS', 30),
    'call_notification_logs_days' => $days('RETENTION_CALL_NOTIFICATION_LOGS_DAYS', 90),
    'email_logs_days' => $days('RETENTION_EMAIL_LOGS_DAYS', 90),
    'blocked_call_logs_days' => $days('RETENTION_BLOCKED_CALL_LOGS_DAYS', 90),
    // 6 months, deleted (not archived)
    'queue_calls_days' => $days('RETENTION_QUEUE_CALLS_DAYS', 183),
    'call_tracking_sessions_days' => $days('RETENTION_CALL_TRACKING_SESSIONS_DAYS', 183),
    'auto_dialer_call_sessions_days' => $days('RETENTION_AUTO_DIALER_CALL_SESSIONS_DAYS', 183),
    // Previous versions of a distribution list, counted from the re-upload that replaced them
    // (distribution-lists:prune-versions, which also removes legacy JSON backups this old).
    'auto_dialer_list_versions_days' => $days('RETENTION_AUTO_DIALER_LIST_VERSIONS_DAYS', 30),
    'platform_audit_logs_days' => $days('RETENTION_PLATFORM_AUDIT_LOGS_DAYS', 365),
    // Platform Status history, in HOURS (model:prune runs daily, so rows may live up to a day longer).
    'platform_health_checks_hours' => $days('PLATFORM_HEALTH_RETENTION_HOURS', 24),

    // Tiered CDR storage (cdr:tier), anchored on session_timestamp in UTC.
    'cdr_hot_months' => (int) env('CDR_HOT_MONTHS', 6),
    'cdr_warm_months' => (int) env('CDR_WARM_MONTHS', 12),
    'cdr_cold_retention_years' => (int) env('CDR_COLD_RETENTION_YEARS', 7),
    // Empty = equal to the CDR cold retention; larger values are clamped to it (recordings:expire).
    'recording_retention_months' => $days('RECORDING_RETENTION_MONTHS', 0) ?: null,
];
