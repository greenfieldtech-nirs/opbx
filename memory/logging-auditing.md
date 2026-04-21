# Logging & Auditing

## Overview
Centralized audit logging for administrative, security, and sensitive operations. PII-safe sanitization prevents credential leakage. Two audit systems: application-level (AuditLogger) and platform-level (PlatformAuditService).

## Source Files
| File | Purpose |
|------|---------|
| `app/Services/Logging/AuditLogger.php` | Application-level audit logging (416 lines) |
| `app/Services/Logging/LogSanitizer.php` | PII/credential sanitization (224 lines) |
| `app/Services/PlatformAuditService.php` | Platform management audit (53 lines) |
| `app/Models/PlatformAuditLog.php` | DB-stored platform audit log |

## AuditLogger (Static Methods)

### Core Method
`AuditLogger::log(event, data, level, request, user)` - generates correlation ID, builds context, sanitizes data, routes to appropriate Log level.

### Event Categories (37 event constants)
| Category | Events |
|----------|--------|
| User | `user.login`, `user.logout`, `user.created`, `user.updated`, `user.deleted`, `user.password_changed` |
| Extension | `extension.created`, `extension.updated`, `extension.deleted` |
| DID | `did.created`, `did.updated`, `did.deleted` |
| Ring Group | `ring_group.created`, `ring_group.updated`, `ring_group.deleted` |
| IVR | `ivr.created`, `ivr.updated`, `ivr.deleted` |
| Business Hours | `business_hours.updated` |
| Settings | `settings.updated`, `cloudonix_config.updated` |
| Security | `security.violation`, `rate_limit.exceeded`, `webhook.failed` |
| Whitelist | `outbound_whitelist.updated` |
| Auto Dialer | list/destination events |

### Convenience Methods
`logUserLogin()`, `logUserCreated()`, `logExtensionCreated()`, `logDIDCreated()`, `logRingGroupCreated()`, `logIVRCreated()`, `logSettingsUpdated()`, `logBusinessHoursUpdated()`, `logSecurityViolation()`, `logRateLimitExceeded()`, `logWebhookFailed()`, etc.

### Log Entry Structure
Every entry includes: `audit: true` flag, event type, correlation ID (UUID), ISO 8601 timestamp, level, user context (id, email, org_id, role), request context (method, URL, IP, user agent), sanitized data.

### Log Levels
- INFO: Standard CRUD operations
- WARNING: Destructive operations (delete), role changes, Cloudonix config changes
- ERROR: Security violations, webhook failures

## LogSanitizer

### Sensitive Keys (32 patterns)
password, token, secret, api_key, authorization, cookie, sip_password, cloudonix, database, session, csrf, payment, credit_card, cvv, ssn, and more.

### Sanitization Methods
| Method | Purpose |
|--------|---------|
| `sanitizeArray(data)` | Recursively replaces sensitive keys with `[REDACTED]` |
| `sanitizeHeaders(headers)` | Masks authorization, cookie, set-cookie headers |
| `sanitizeString(text)` | Regex: masks Bearer tokens, `key=value` patterns, passwords in URLs |
| `sanitizeWebhookPayload(data)` | Deep sanitization for webhook data |
| `requestContext(request)` | Extracts safe request info with sanitized input |

### Privacy
`hashDomain(email)` - SHA-256 hashes email domain for logging without exposing PII.

## PlatformAuditService (DB-stored)
Records platform management mutations to `platform_audit_logs` table:
- who: `platform_manager_user_id`
- what: `action`, `entity_type`, `entity_id`
- where: `target_organization_id`
- context: `before_state` (JSON), `after_state` (JSON), `reason`, `ip_address`, `user_agent`

## Related Modules
- [Platform Management](platform-management.md) - Uses PlatformAuditService
- [Security](security.md) - Security violations are audit-logged
