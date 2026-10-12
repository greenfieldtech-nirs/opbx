<?php

declare(strict_types=1);

/*
| Retention windows in days, consumed by model:prune (see App\Models\Concerns\PrunesByRetention).
| Set an env var to an empty value to disable pruning for that table.
| CDR retention is deliberately absent: it is handled by the tiered CDR storage task.
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
    'platform_audit_logs_days' => $days('RETENTION_PLATFORM_AUDIT_LOGS_DAYS', 365),
];
