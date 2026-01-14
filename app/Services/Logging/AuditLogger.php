<?php

declare(strict_types=1);

namespace App\Services\Logging;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Audit Logging Service
 *
 * Provides structured audit logging for administrative actions, security events,
 * and sensitive operations. All logs include correlation IDs, user context,
 * and sanitized data to ensure compliance and traceability.
 */
class AuditLogger
{
    /**
     * Log levels for audit events
     */
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_CRITICAL = 'critical';

    /**
     * Common audit event types
     */
    public const EVENT_USER_LOGIN = 'user.login';
    public const EVENT_USER_LOGOUT = 'user.logout';
    public const EVENT_USER_CREATED = 'user.created';
    public const EVENT_USER_UPDATED = 'user.updated';
    public const EVENT_USER_DELETED = 'user.deleted';
    public const EVENT_USER_PASSWORD_CHANGED = 'user.password_changed';

    public const EVENT_EXTENSION_CREATED = 'extension.created';
    public const EVENT_EXTENSION_UPDATED = 'extension.updated';
    public const EVENT_EXTENSION_DELETED = 'extension.deleted';

    public const EVENT_DID_CREATED = 'did.created';
    public const EVENT_DID_UPDATED = 'did.updated';
    public const EVENT_DID_DELETED = 'did.deleted';

    public const EVENT_RING_GROUP_CREATED = 'ring_group.created';
    public const EVENT_RING_GROUP_UPDATED = 'ring_group.updated';
    public const EVENT_RING_GROUP_DELETED = 'ring_group.deleted';

    public const EVENT_IVR_CREATED = 'ivr.created';
    public const EVENT_IVR_UPDATED = 'ivr.updated';
    public const EVENT_IVR_DELETED = 'ivr.deleted';

    public const EVENT_BUSINESS_HOURS_UPDATED = 'business_hours.updated';
    public const EVENT_OUTBOUND_WHITELIST_UPDATED = 'outbound_whitelist.updated';

    public const EVENT_SETTINGS_UPDATED = 'settings.updated';
    public const EVENT_CLOUDONIX_CONFIG_UPDATED = 'cloudonix_config.updated';

    public const EVENT_SECURITY_VIOLATION = 'security.violation';
    public const EVENT_RATE_LIMIT_EXCEEDED = 'rate_limit.exceeded';
    public const EVENT_WEBHOOK_FAILED = 'webhook.failed';

    /**
     * Log an audit event
     *
     * @param string $event Event type constant
     * @param array<string, mixed> $data Event-specific data
     * @param string $level Log level (info, warning, error, critical)
     * @param Request|null $request HTTP request context
     * @param mixed $user User performing the action (if available)
     */
    public static function log(
        string $event,
        array $data = [],
        string $level = self::LEVEL_INFO,
        ?Request $request = null,
        $user = null
    ): void {
        // Generate correlation ID if not provided
        $correlationId = $data['correlation_id'] ?? Str::uuid()->toString();

        // Build audit context
        $context = [
            'audit' => true,
            'event' => $event,
            'correlation_id' => $correlationId,
            'timestamp' => now()->toISOString(),
            'level' => $level,
        ];

        // Add user context
        if ($user) {
            $context['user'] = [
                'id' => $user->id ?? null,
                'email' => $user->email ?? null,
                'organization_id' => $user->organization_id ?? null,
                'role' => $user->role?->value ?? null,
            ];
        } elseif (auth()->check()) {
            $authenticatedUser = auth()->user();
            $context['user'] = [
                'id' => $authenticatedUser->id,
                'email' => $authenticatedUser->email,
                'organization_id' => $authenticatedUser->organization_id,
                'role' => $authenticatedUser->role->value,
            ];
        }

        // Add request context if available
        if ($request) {
            $context['request'] = [
                'id' => $request->attributes->get('request_id', 'unknown'),
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ];
        }

        // Sanitize and merge event data
        $context['data'] = LogSanitizer::sanitizeArray($data);

        // Remove correlation_id from data if it was passed separately
        unset($context['data']['correlation_id']);

        // Log based on level
        $message = "Audit: {$event}";
        $fullContext = $context;

        switch ($level) {
            case self::LEVEL_CRITICAL:
                Log::critical($message, $fullContext);
                break;
            case self::LEVEL_ERROR:
                Log::error($message, $fullContext);
                break;
            case self::LEVEL_WARNING:
                Log::warning($message, $fullContext);
                break;
            default:
                Log::info($message, $fullContext);
                break;
        }
    }

    /**
     * Log user authentication events
     */
    public static function logUserLogin(Request $request, $user): void
    {
        self::log(self::EVENT_USER_LOGIN, [
            'login_method' => $request->has('email') ? 'credentials' : 'token',
        ], self::LEVEL_INFO, $request, $user);
    }

    public static function logUserLogout(Request $request, $user = null): void
    {
        self::log(self::EVENT_USER_LOGOUT, [], self::LEVEL_INFO, $request, $user);
    }

    /**
     * Log user management events
     */
    public static function logUserCreated(Request $request, $createdUser): void
    {
        self::log(self::EVENT_USER_CREATED, [
            'target_user_id' => $createdUser->id,
            'target_user_email' => $createdUser->email,
            'target_user_role' => $createdUser->role->value,
        ], self::LEVEL_INFO, $request);
    }

    public static function logUserUpdated(Request $request, $updatedUser, array $changes = []): void
    {
        self::log(self::EVENT_USER_UPDATED, [
            'target_user_id' => $updatedUser->id,
            'target_user_email' => $updatedUser->email,
            'changes' => $changes,
        ], self::LEVEL_INFO, $request);
    }

    public static function logUserDeleted(Request $request, int $deletedUserId, string $deletedUserEmail): void
    {
        self::log(self::EVENT_USER_DELETED, [
            'target_user_id' => $deletedUserId,
            'target_user_email' => $deletedUserEmail,
        ], self::LEVEL_WARNING, $request);
    }

    public static function logPasswordChanged(Request $request, $user): void
    {
        self::log(self::EVENT_USER_PASSWORD_CHANGED, [
            'target_user_id' => $user->id,
            'target_user_email' => $user->email,
        ], self::LEVEL_INFO, $request);
    }

    /**
     * Log extension management events
     */
    public static function logExtensionCreated(Request $request, $extension): void
    {
        self::log(self::EVENT_EXTENSION_CREATED, [
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
            'extension_type' => $extension->type->value,
        ], self::LEVEL_INFO, $request);
    }

    public static function logExtensionUpdated(Request $request, $extension, array $changes = []): void
    {
        self::log(self::EVENT_EXTENSION_UPDATED, [
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
            'changes' => $changes,
        ], self::LEVEL_INFO, $request);
    }

    public static function logExtensionDeleted(Request $request, int $extensionId, string $extensionNumber): void
    {
        self::log(self::EVENT_EXTENSION_DELETED, [
            'extension_id' => $extensionId,
            'extension_number' => $extensionNumber,
        ], self::LEVEL_WARNING, $request);
    }

    /**
     * Log DID management events
     */
    public static function logDIDCreated(Request $request, $did): void
    {
        self::log(self::EVENT_DID_CREATED, [
            'did_id' => $did->id,
            'phone_number' => $did->phone_number,
            'routing_type' => $did->routing_type,
        ], self::LEVEL_INFO, $request);
    }

    public static function logDIDUpdated(Request $request, $did, array $changes = []): void
    {
        self::log(self::EVENT_DID_UPDATED, [
            'did_id' => $did->id,
            'phone_number' => $did->phone_number,
            'changes' => $changes,
        ], self::LEVEL_INFO, $request);
    }

    public static function logDIDDeleted(Request $request, int $didId, string $phoneNumber): void
    {
        self::log(self::EVENT_DID_DELETED, [
            'did_id' => $didId,
            'phone_number' => $phoneNumber,
        ], self::LEVEL_WARNING, $request);
    }

    /**
     * Log ring group management events
     */
    public static function logRingGroupCreated(Request $request, $ringGroup): void
    {
        self::log(self::EVENT_RING_GROUP_CREATED, [
            'ring_group_id' => $ringGroup->id,
            'ring_group_name' => $ringGroup->name,
            'strategy' => $ringGroup->strategy,
        ], self::LEVEL_INFO, $request);
    }

    public static function logRingGroupUpdated(Request $request, $ringGroup, array $changes = []): void
    {
        self::log(self::EVENT_RING_GROUP_UPDATED, [
            'ring_group_id' => $ringGroup->id,
            'ring_group_name' => $ringGroup->name,
            'changes' => $changes,
        ], self::LEVEL_INFO, $request);
    }

    public static function logRingGroupDeleted(Request $request, int $ringGroupId, string $ringGroupName): void
    {
        self::log(self::EVENT_RING_GROUP_DELETED, [
            'ring_group_id' => $ringGroupId,
            'ring_group_name' => $ringGroupName,
        ], self::LEVEL_WARNING, $request);
    }

    /**
     * Log IVR management events
     */
    public static function logIVRCreated(Request $request, $ivr): void
    {
        self::log(self::EVENT_IVR_CREATED, [
            'ivr_id' => $ivr->id,
            'ivr_name' => $ivr->name,
        ], self::LEVEL_INFO, $request);
    }

    public static function logIVRUpdated(Request $request, $ivr, array $changes = []): void
    {
        self::log(self::EVENT_IVR_UPDATED, [
            'ivr_id' => $ivr->id,
            'ivr_name' => $ivr->name,
            'changes' => $changes,
        ], self::LEVEL_INFO, $request);
    }

    public static function logIVRDeleted(Request $request, int $ivrId, string $ivrName): void
    {
        self::log(self::EVENT_IVR_DELETED, [
            'ivr_id' => $ivrId,
            'ivr_name' => $ivrName,
        ], self::LEVEL_WARNING, $request);
    }

    /**
     * Log configuration and settings events
     */
    public static function logSettingsUpdated(Request $request, array $changes = []): void
    {
        self::log(self::EVENT_SETTINGS_UPDATED, [
            'changes' => $changes,
        ], self::LEVEL_INFO, $request);
    }

    public static function logBusinessHoursUpdated(Request $request, $schedule, array $changes = []): void
    {
        self::log(self::EVENT_BUSINESS_HOURS_UPDATED, [
            'schedule_id' => $schedule->id,
            'schedule_name' => $schedule->name,
            'changes' => $changes,
        ], self::LEVEL_INFO, $request);
    }

    public static function logOutboundWhitelistUpdated(Request $request, array $changes = []): void
    {
        self::log(self::EVENT_OUTBOUND_WHITELIST_UPDATED, [
            'changes' => $changes,
        ], self::LEVEL_INFO, $request);
    }

    public static function logCloudonixConfigUpdated(Request $request, array $changes = []): void
    {
        self::log(self::EVENT_CLOUDONIX_CONFIG_UPDATED, [
            'changes' => $changes,
        ], self::LEVEL_WARNING, $request);
    }

    /**
     * Log security events
     */
    public static function logSecurityViolation(Request $request, string $violation, array $details = []): void
    {
        self::log(self::EVENT_SECURITY_VIOLATION, [
            'violation' => $violation,
            'details' => $details,
        ], self::LEVEL_WARNING, $request);
    }

    public static function logRateLimitExceeded(Request $request, string $limiter, int $attempts): void
    {
        self::log(self::EVENT_RATE_LIMIT_EXCEEDED, [
            'limiter' => $limiter,
            'attempts' => $attempts,
        ], self::LEVEL_WARNING, $request);
    }

    public static function logWebhookFailed(Request $request, string $webhookType, string $error, array $details = []): void
    {
        self::log(self::EVENT_WEBHOOK_FAILED, [
            'webhook_type' => $webhookType,
            'error' => $error,
            'details' => $details,
        ], self::LEVEL_ERROR, $request);
    }
}