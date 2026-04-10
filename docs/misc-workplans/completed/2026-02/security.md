# Security Documentation

This document outlines the security measures, configurations, and best practices implemented in the OPBX project.

## Authentication & CSRF Protection

### SPA Authentication (Sanctum)
The Control Plane uses Laravel Sanctum for SPA authentication. This relies on cookie-based session authentication with CSRF protection.

#### CSRF Flow
1. **Initial Request**: The frontend application MUST first request `/sanctum/csrf-cookie` to initialize the CSRF protection.
2. **Cookie Setting**: The server responds by setting an `XSRF-TOKEN` cookie containing the encrypted CSRF token.
3. **Subsequent Requests**: For all mutating requests (POST, PUT, DELETE), the frontend must include the value of the `XSRF-TOKEN` cookie in the `X-XSRF-TOKEN` header. Axios and other libraries often handle this automatically.

#### Configuration
- **Stateful Domains**: Only domains listed in `SANCTUM_STATEFUL_DOMAINS` env var will receive stateful authentication cookies.
- **Middleware**: The `VerifyCsrfToken` middleware validates the token on incoming State requests.

### API Authentication (Bearer Tokens)
External integrations (Cloudonix Webhooks) use Bearer token authentication.
- **Voice Webhooks**: Validated via `VerifyVoiceWebhookAuth` middleware.
- **CDR Webhooks**: Validated via `VerifyCloudonixSignature` (HMAC) or Bearer token depending on configuration.

## Input Validation & Sanitation

### XML Injection Prevention
CXML (Cloudonix XML) responses are protected against XML injection. All dynamic content inserted into CXML responses is escaped using `htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8')`.

### Rate Limiting
- **Per-Organization Isolation**: Rate limits are enforced usage per organization ID to prevent "noisy neighbor" issues.
- **Resilient Backend**: Rate limiting uses Redis but gracefully degrades to memory/database if Redis becomes unavailable.

## Concurrency Control

### Distributed Locking
Critical sections, such as Voice Routing decisions, utilize Redis-based distributed locks to prevent race conditions during concurrent webhook events (e.g., rapid retry events).
- **TTL**: Locks generally have a 15-second TTL.
- **Key Pattern**: `lock:voice_routing:{call_id}`

## Configurable Security Configs

### Circuit Breaker
External API calls to Cloudonix are protected by a Circuit Breaker pattern.
Configuration: `config/circuit-breaker.php` or via `CIRCUIT_BREAKER_*` env vars.

### Admin Credentials
Default admin credentials for seeding are configurable via:
- `ADMIN_NAME`
- `ADMIN_EMAIL`
- `ADMIN_PASSWORD`

**WARNING**: Always change the default admin password immediately after initial deployment.
