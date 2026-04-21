# Security

## Overview
Multi-layered security: HTTP security headers (CSP, HSTS), per-organization rate limiting, sensitive operation throttling, reCAPTCHA, email domain validation, and webhook signature verification.

## Source Files
| File | Purpose |
|------|---------|
| `app/Http/Middleware/SecurityHeaders.php` | CSP, HSTS, X-Frame-Options, Permissions-Policy |
| `app/Http/Middleware/RateLimitPerOrganization.php` | Per-org rate limiting |
| `app/Http/Middleware/RateLimitSensitiveOperations.php` | Per-user+IP throttling |
| `app/Rules/Recaptcha.php` | Google reCAPTCHA v3 validation |
| `app/Rules/ValidEmailDomain.php` | Email domain validation via UserCheck.com |
| `config/security.php` | CSP/HSTS configuration |
| `config/rate-limiting.php` | Per-org rate limits |
| `config/rate_limiting.php` | General rate limits |

## Security Headers (SecurityHeaders.php)
Applied to all responses:
- **CSP**: nonce-based `script-src`, `object-src 'none'`, `frame-ancestors 'none'`, `base-uri 'self'`
- **HSTS**: Production only, configurable max-age (default 1 year), includeSubDomains, preload
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- **Permissions-Policy**: Disables geolocation, microphone, camera, payment, USB, sensors
- Production adds `upgrade-insecure-requests`

## Rate Limiting

### Per-Organization (RateLimitPerOrganization.php)
Applied as `rate_limit_org:{type}` middleware alias.

| Type | Default Limit |
|------|--------------|
| `voice_routing` | 100/min |
| `webhook` | 200/min |
| `api` | 60/min |
| `default` | 60/min |

- Extracts org ID from `_organization_id` input, `request->user()->organization_id`, or `X-Organization-ID` header
- Atomic counting via `Cache::add()` + `Cache::increment()` (race-safe)
- Returns 429 with `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`
- Logs warning at 80% utilization

### Sensitive Operations (RateLimitSensitiveOperations.php)
Applied as `sensitive-operations` middleware.
- Key: `sensitive-operation:{userId}:{ip}` (per-user per-IP)
- Admins: 60/min (configurable), Regular users: 10/min
- Used on: password changes, settings updates

### Route-Level Throttling
| Limiter | Routes | Limit |
|---------|--------|-------|
| `throttle:auth` | Login | 5/min per IP |
| `throttle:registration` | Register | Custom |
| `throttle:sensitive` | Password endpoints | Custom |
| `throttle:dialer-worker` | Worker API | Custom |

## reCAPTCHA (Rules/Recaptcha.php)
- Google reCAPTCHA v3 (invisible)
- Can be disabled via `services.recaptcha.enabled` config
- Score threshold: configurable (default 0.5)
- 10s timeout to Google API
- Applied to: registration

## Email Domain Validation (Rules/ValidEmailDomain.php)
- Uses `EmailValidatorInterface` (UserCheck.com integration)
- Blocks: disposable, spam, blocklisted, role-based, relay, public domain emails
- Provides typo suggestions
- Logs failures with hashed domain (privacy-safe)
- Applied to: registration email

## Middleware Registration (bootstrap/app.php)
| Alias | Class |
|-------|-------|
| `tenant.scope` | EnsureTenantScope |
| `webhook.signature` | VerifyCloudonixSignature |
| `webhook.idempotency` | EnsureWebhookIdempotency |
| `voice.webhook.auth` | VerifyVoiceWebhookAuth |
| `rate_limit_org` | RateLimitPerOrganization |
| `sensitive-operations` | RateLimitSensitiveOperations |
| `platform.manager` | EnsurePlatformManager |
| `dialer.worker.auth` | DialerWorkerAuth |

## Related Modules
- [Authentication](authentication-authorization.md) - Sanctum token security
- [Webhook Processing](webhook-processing.md) - Signature verification details
- [Multi-Tenancy](multi-tenancy.md) - Tenant scope enforcement
