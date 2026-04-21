# Call Notifications

## ⚠️⚠️⚠️ CRITICAL MODULE - DO NOT MODIFY ⚠️⚠️⚠️

**THIS MODULE IS COMPLEX AND FINALLY WORKING CORRECTLY.**

**DO NOT MODIFY THIS MODULE UNLESS:**
1. You are explicitly instructed by the user to do so
2. You have thoroughly tested all changes in a staging environment
3. You understand the interaction between OrganizationScope, webhook handlers, and the notification flow

**HISTORY OF ISSUES:**
- 2026-04-11: Complete module rewrite due to OrganizationScope blocking webhook queries
- Multiple security vulnerabilities fixed (SSRF, auth bypass)
- Race conditions in status filtering resolved
- CDR webhook notification triggering fixed

**IF YOU TOUCH THIS WITHOUT EXPLICIT USER INSTRUCTION, YOU RISK BREAKING:**
- Webhook authentication for all organizations
- Call notification delivery
- CDR processing
- Session update handling

---

## Overview

External webhook notifications for call events. Dispatches HTTP POST payloads to a configured URL when calls are initiated, answered, ended, etc. Per-organization settings with rate limiting and retry.

## Source Files

| File | Purpose | Lines | Status |
|------|---------|-------|--------|
| `app/Http/Controllers/Api/CallNotificationsSettingsController.php` | Settings CRUD + test + logs | 323 | ✅ STABLE |
| `app/Services/CallNotifications/WebhookDispatcher.php` | HTTP dispatch with retry | ~300 | ✅ STABLE |
| `app/Services/CallNotifications/NotificationPayloadBuilder.php` | Payload construction | 256 | ✅ STABLE |
| `app/Models/CallNotificationsSettings.php` | Singleton settings per org | 147 | ✅ STABLE |
| `app/Models/CallNotificationLog.php` | Delivery audit log | 151 | ✅ STABLE |
| `app/Services/Security/SsrfUrlValidator.php` | SSRF protection | ~120 | ✅ STABLE |
| `app/Rules/ValidWebhookUrl.php` | Laravel validation rule | ~30 | ✅ STABLE |
| `app/Http/Middleware/VerifyCloudonixSignature.php` | Webhook auth | ~200 | ✅ STABLE |
| `frontend/src/pages/CallNotificationsSettings.tsx` | Settings page | ~400 | ✅ STABLE |

## Database Tables

### `call_notifications_settings` (singleton per org)

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `organization_id` | int | - | FK to organizations |
| `webhook_url` | string | null | Must pass SSRF validation |
| `auth_method` | string | 'none' | none/bearer_token/basic_auth |
| `auth_secret` | string | null | Encrypted |
| `auth_username` | string | null | For basic auth |
| `retry_attempts` | int | 3 | Max retry count |
| `retry_backoff_seconds` | int | 60 | Base backoff |
| `request_timeout_seconds` | int | 30 | HTTP timeout |
| `enabled_events` | JSON | [see below] | Array of event types |
| `rate_limit_per_minute` | int | 500 | Redis-based |
| `is_active` | boolean | true | Master switch |

**Default enabled_events:**
```json
["new","ringing","connected","answered","busy","cancel","failed","congestion"]
```

### `call_notification_logs`

| Column | Type | Notes |
|--------|------|-------|
| `organization_id` | int | Scoped by OrganizationScope |
| `call_session_token` | string | Session identifier |
| `event_id` | string | Unique per event |
| `event_type` | string | call.status_update |
| `status` | string | Call status |
| `webhook_url` | string | Destination URL |
| `request_payload` | JSON | Full payload sent |
| `request_headers` | JSON | Headers sent (sanitized) |
| `response_body` | text | Response from endpoint |
| `response_headers` | JSON | Response headers |
| `response_status_code` | int | HTTP status |
| `response_time_ms` | int | Round-trip time |
| `is_success` | boolean | Delivery success |
| `attempt_number` | int | Retry count |
| `error_message` | string | Failure reason |
| `debug_info` | JSON | Internal debug data |
| `type` | string | `call_notification` (default) or `cxml_proxy` |

## Architecture Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│ CLOUDONIX WEBHOOKS                                                      │
│  ├─ session-update (high frequency)                                     │
│  ├─ cdr (end of call)                                                   │
│  └─ call-status, call-initiated                                         │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│ VerifyCloudonixSignature Middleware                                     │
│  1. Extract domain from payload                                         │
│  2. Match domain → CloudonixSettings → organization_id                  │
│  3. Authenticate:                                                       │
│     - If domain_requests_api_key set → Bearer token REQUIRED            │
│     - If no key set → Bearer token OPTIONAL                             │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│ CloudonixWebhookController                                              │
│  ├─ sessionUpdate() → triggerCallNotification()                         │
│  └─ cdr() → createSessionUpdateFromCDR() → triggerCallNotification()    │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│ triggerCallNotification()                                               │
│  ⚠️ USES OrganizationScope::bypass() FOR ALL QUERIES ⚠️                │
│  1. Load CallNotificationsSettings via bypass()                         │
│  2. Check isConfigured() → webhook_url + is_active                      │
│  3. Check isEventEnabled(normalized_status)                             │
│  4. Get previous status via bypass()                                    │
│  5. Build payload via NotificationPayloadBuilder                        │
│  6. Dispatch via WebhookDispatcher                                      │
└─────────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────────┐
│ WebhookDispatcher::dispatch()                                           │
│  ⚠️ USES OrganizationScope::bypass() FOR LOG CREATION ⚠️               │
│  1. SSRF validation on webhook_url                                      │
│  2. Rate limit check (Redis)                                            │
│  3. Create CallNotificationLog via bypass()                             │
│  4. HTTP POST with retries                                              │
│  5. Update log entry with result                                        │
└─────────────────────────────────────────────────────────────────────────┘
```

## Critical Implementation Details

### 1. OrganizationScope Bypass (REQUIRED)

Since `CallNotificationsSettings` and `CallNotificationLog` use `#[ScopedBy([OrganizationScope::class])]`, ALL queries in webhook context MUST use bypass:

```php
// ❌ WRONG - Will return null in webhook context
$settings = CallNotificationsSettings::forOrganization($orgId)->first();

// ✅ CORRECT - Bypasses tenant scope
$settings = \App\Scopes\OrganizationScope::bypass(function () use ($orgId) {
    return CallNotificationsSettings::forOrganization($orgId)->first();
});
```

**Files that MUST use bypass:**
- `CloudonixWebhookController::triggerCallNotification()` - Settings lookup
- `CloudonixWebhookController::triggerCallNotification()` - Previous status query
- `WebhookDispatcher::dispatch()` - Log creation
- `WebhookDispatcher::getRateLimitStatus()` - Settings lookup

### 2. Status Filtering

Session updates are filtered before processing. Only these statuses trigger notifications:

| Raw Status | Normalized | Event |
|------------|------------|-------|
| new, initiated, created | new | new |
| ringing, ring, progress | ringing | ringing |
| connected, connect | connected | connected |
| answer, answered, active | answered | answered |
| busy | busy | busy |
| cancel, cancelled, canceled | cancel | cancel |
| failed, fail, error | failed | failed |
| congestion, congested | congestion | congestion |

### 3. Webhook Authentication

**Unified model for session-update AND cdr:**

1. Domain MUST be present in payload
2. Domain MUST match an organization's CloudonixSettings
3. If `domain_requests_api_key` is set:
   - Bearer token MUST be present
   - Bearer token MUST match the key
4. If `domain_requests_api_key` is NOT set:
   - Bearer token is OPTIONAL
   - Request processed without validation

**Error Responses:**
- Missing domain → 400 Bad Request
- Unknown domain → 401 Unauthorized (not 404 - prevents enumeration)
- Auth failure → 401 Unauthorized

### 4. CXML Proxy Event Logging
When `CxmlProxyService` forwards a voice webhook to a CXML Endpoint provider, it creates a `CallNotificationLog` entry with:
- `type = 'cxml_proxy'`
- `event_id` derived from the proxied call session
- `request_payload` contains the forwarded request body
- `response_body` contains the returned CXML (or error)
- These logs are exposed via `GET /v1/cdr/{cdr}/cxml-events` for Call Log detail view

### 5. SSRF Protection

All webhook URLs validated via `SsrfUrlValidator`:
- Blocks RFC1918 private IPs (10/8, 172.16/12, 192.168/16)
- Blocks loopback (127/8)
- Blocks link-local (169.254/16)
- Blocks IPv6 unique local (fc00::/7)
- Blocks Docker service names (localhost, mysql, redis, etc.)
- Only allows http/https schemes

## API Routes

| Method | URI | Middleware | Notes |
|--------|-----|------------|-------|
| GET | `/v1/call-notifications/settings` | auth | Get settings |
| POST | `/v1/call-notifications/settings` | auth | Create (409 if exists) |
| PUT | `/v1/call-notifications/settings` | auth | Update |
| DELETE | `/v1/call-notifications/settings` | auth | Delete |
| POST | `/v1/call-notifications/test` | auth | Send test webhook |
| GET | `/v1/call-notifications/logs` | auth | Delivery logs |
| GET | `/v1/call-notifications/logs/{token}` | auth | Session-specific |
| GET | `/v1/call-notifications/rate-limit` | auth | Rate limit status |

## Related Modules

- [Webhook Processing](webhook-processing.md) - General webhook handling
- [Live Calls](live-calls.md) - Session updates data source
- [CDR](call-detail-records.md) - Call detail records

## Documentation

- `docs/WEBHOOK-AUTHENTICATION.md` - Detailed auth model
- `docs/review-workplan/code-review-2026-04-11/FINDINGS.md` - Code review results
- `docs/review-workplan/security-review-2026-04-11/FINDINGS.md` - Security review results

## Change History

### 2026-04-11 - MAJOR REWRITE
- Fixed OrganizationScope blocking (CRITICAL)
- Added scopeForOrganization() methods back (CRITICAL)
- Fixed CDR not triggering notifications (CRITICAL)
- Expanded status filtering (HIGH)
- Unified webhook authentication (HIGH)
- Added SSRF protection (HIGH)
- Deleted deprecated CallLogController

---

## ⚠️ FINAL WARNING

This module was rewritten on 2026-04-11 after multiple critical bugs were found.
It is now working correctly. ANY modification risks:

1. Breaking webhook authentication for ALL organizations
2. Causing notification delivery failures
3. Introducing security vulnerabilities
4. Breaking CDR processing

**DO NOT MODIFY UNLESS EXPLICITLY INSTRUCTED BY THE USER.**
