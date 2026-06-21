# Webhook Authentication

This document describes the webhook authentication implementation in OpBX. Webhooks use **Bearer token authentication**; there are no HMAC-SHA256 webhook signatures.

## Table of Contents

- [Overview](#overview)
- [Authentication Flows Diagram](#authentication-flows-diagram)
- [Voice Routing Authentication](#voice-routing-authentication)
- [Status/CDR Webhook Authentication](#statuscdr-webhook-authentication)
- [Idempotency Mechanism](#idempotency-mechanism)
- [Auto-Dialer Webhooks](#auto-dialer-webhooks)
- [AMD Action Callback](#amd-action-callback)
- [Dialer Worker Authentication](#dialer-worker-authentication)
- [Configuration](#configuration)
- [Error Responses](#error-responses)
- [Security Properties](#security-properties)
- [Testing Webhooks](#testing-webhooks)

---

## Overview

OpBX receives webhooks from Cloudonix CPaaS for voice routing, call status updates, CDRs, and auto-dialer events. Authentication is implemented via middleware that validates Bearer tokens against organization settings or global worker secrets.

There are four distinct authentication contexts:

| Context | Middleware | Auth Method | Response Format | Routes |
|---------|-----------|-------------|-----------------|--------|
| Voice Routing | `VerifyVoiceWebhookAuth` (`voice.webhook.auth`) | Bearer token (required) | CXML | `/api/voice/*`, `/api/callbacks/voice/*` |
| Status/CDR | `VerifyCloudonixSignature` (`webhook.signature`) | Bearer token (conditional) | JSON | `/api/webhooks/cloudonix/*` |
| Auto-Dialer | `VerifyCloudonixSignature` + `EnsureWebhookIdempotency` | Bearer token (conditional) | JSON | `/api/webhooks/auto-dialer/*`, `/api/webhooks/cloudonix/dialer` |
| AMD Action | Inline in `AmdActionController` | Bearer token (required) | JSON | `/api/voice/amd-action` |

---

## Authentication Flows Diagram

```mermaid
flowchart TD
    subgraph Voice["Voice Routing Webhooks (CXML)"]
        V1[POST /api/voice/route] --> VM[VerifyVoiceWebhookAuth]
        V2[POST /api/voice/ivr-input] --> VM
        V3[POST /api/callbacks/voice/ring-group-callback] --> VM
        V4[POST /api/callbacks/voice/albs-follow-through] --> VM
    end

    subgraph Status["Status/CDR Webhooks (JSON)"]
        S1[POST /api/webhooks/cloudonix/call-initiated] --> SM[VerifyCloudonixSignature]
        S2[POST /api/webhooks/cloudonix/call-status] --> SM
        S3[POST /api/webhooks/cloudonix/cdr] --> SM
        S4[POST /api/webhooks/cloudonix/session-update] --> SM
        S5[POST /api/webhooks/cloudonix/dialer] --> SM
    end

    subgraph Idemp["Idempotency Layer"]
        SM --> IM[EnsureWebhookIdempotency]
    end

    subgraph Dialer["Auto-Dialer Webhooks"]
        D1[POST /api/webhooks/auto-dialer/call-status] --> SM2[VerifyCloudonixSignature]
        D2[POST /api/webhooks/auto-dialer/amd-result] --> SM2
        SM2 --> IM2[EnsureWebhookIdempotency]
    end

    subgraph AMD["AMD Action Callback"]
        A1[POST /api/voice/amd-action] --> AC[Inline Bearer Check]
    end

    VM --> VT{Token matches<br/>domain_requests_api_key?}
    VT -->|Yes| VO[Attach _organization_id<br/>Proceed to controller]
    VT -->|No| VCXML[Return CXML:<br/>&lt;Say&gt;Unauthorized&lt;/Say&gt;<br/>&lt;Hangup/&gt;]

    SM --> SD{Domain found in<br/>CloudonixSettings?}
    SD -->|No| SJ[401 JSON:<br/>Unknown domain]
    SD -->|Yes| SA{API key<br/>configured?}
    SA -->|Yes| ST{Bearer token<br/>matches?}
    ST -->|Yes| SO[Attach _organization_id<br/>Proceed to controller]
    ST -->|No| SJ2[401 JSON:<br/>Invalid Bearer token]
    SA -->|No| SB[Proceed<br/>(backward compatible)]

    AC --> AT{Bearer matches<br/>AMD_WORKER_API_TOKEN?}
    AT -->|Yes| AO[Proceed to controller]
    AT -->|No| AJ[401 JSON:<br/>Unauthorized]
```

---

## Voice Routing Authentication

**Middleware:** `App\Http\Middleware\VerifyVoiceWebhookAuth`  
**Alias:** `voice.webhook.auth`

Voice routing webhooks are real-time CXML requests from Cloudonix that determine how inbound calls are routed. Authentication is **strictly required** — there is no backward-compatible mode without a token.

### Authentication Flow

1. **Extract Bearer token** from the `Authorization` header.
2. **Extract `Domain`** from the JSON body (`Domain` or `domain`).
3. **Extract `to` and `from`** numbers from the JSON body.
4. **Find `CloudonixSettings`** by `domain_name` or `domain_uuid`.
5. **Verify token** matches `domain_requests_api_key` using `hash_equals()`.
6. **Attach `_organization_id`** to the request for downstream controllers.

### Protected Routes

| Route | Method | Controller | Purpose |
|-------|--------|------------|---------|
| `/api/voice/route` | POST | `VoiceRoutingController@handleInbound` | Main inbound call routing |
| `/api/voice/ivr-input` | POST | `VoiceRoutingController@handleIvrInput` | IVR digit input callback |
| `/api/callbacks/voice/ring-group-callback` | POST | `VoiceRoutingController@handleRingGroupCallback` | Ring group sequential routing |
| `/api/callbacks/voice/albs-follow-through` | POST | `AlbsFollowThroughController@handle` | ALB failover routing |

### Error Responses

All voice routing errors return **CXML** with HTTP 401 or 400:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say language="en-US">Unauthorized. Authentication failed.</Say>
  <Hangup/>
</Response>
```

---

## Status/CDR Webhook Authentication

**Middleware:** `App\Http\Middleware\VerifyCloudonixSignature`  
**Alias:** `webhook.signature`

Status and CDR webhooks are asynchronous events from Cloudonix. The authentication flow was **unified and rewritten on 2026-04-11** to support both authenticated and backward-compatible (unauthenticated) organizations.

### Unified Authentication Flow

1. **Extract domain** from the payload:
   - `payload.domain` (session-update, call-status)
   - `payload.owner.domain.name` (CDR)
   - `payload.owner.domain.uuid` (CDR fallback)
2. **Match domain to organization** via `CloudonixSettings` (`domain_name` or `domain_uuid`).
3. **Check organization's auth configuration:**
   - If `domain_requests_api_key` is set → Bearer token **REQUIRED** and **MUST match**.
   - If `domain_requests_api_key` is `null`/`empty` → Bearer token **OPTIONAL** (backward compatible).
4. **Attach `_organization_id`** to the request.

### Token Comparison

Token comparison uses PHP's `hash_equals()` for **timing-safe comparison**, preventing timing attacks:

```php
if (!hash_equals($settings->domain_requests_api_key, $providedToken)) {
    // Reject
}
```

### Protected Routes

| Route | Middleware | Purpose |
|-------|-----------|---------|
| `/api/webhooks/cloudonix/call-initiated` | `webhook.signature`, `webhook.idempotency` | Call initiation event |
| `/api/webhooks/cloudonix/call-status` | `webhook.signature`, `webhook.idempotency` | Call status changes |
| `/api/webhooks/cloudonix/cdr` | `webhook.signature`, `webhook.idempotency` | Call detail records |
| `/api/webhooks/cloudonix/session-update` | `webhook.signature` | High-velocity session updates |
| `/api/webhooks/cloudonix/dialer` | `webhook.signature`, `webhook.idempotency` | Dialer proxy events |

### Backward Compatibility

Organizations that have not configured a `domain_requests_api_key` will continue to receive webhooks without authentication. This is intentional to avoid breaking existing integrations. Organizations should configure an API key in **Settings → Cloudonix** to enable webhook authentication.

---

## Idempotency Mechanism

**Middleware:** `App\Http\Middleware\EnsureWebhookIdempotency`  
**Alias:** `webhook.idempotency`

Idempotency prevents duplicate processing of webhook events caused by retries, network issues, or Cloudonix redelivery.

### Key Features

| Feature | Implementation |
|---------|---------------|
| Cache store | Redis |
| Cache key prefix | `idem:webhook:{key}` |
| Default TTL | 24 hours (`86400` seconds) |
| Max response cache size | 100 KB (`102400` bytes) |
| Replay protection max age | 5 minutes (`300` seconds) |
| Future timestamp tolerance | 1 minute (`60` seconds) |

### Idempotency Key Generation

The key is generated in priority order:

1. **`X-Idempotency-Key` header** — explicit key from the sender.
2. **`call_id` + `event_type`** — SHA-256 hash of `CallSid`/`call_id` + `CallStatus`/`event_type`.
3. **Full payload** — SHA-256 hash of the entire JSON body.

```php
private function getIdempotencyKey(Request $request): ?string
{
    // Priority 1: Header
    $headerKey = $request->header('X-Idempotency-Key');
    if ($headerKey) return $headerKey;

    // Priority 2: call_id + event_type
    $callId = $request->input('CallSid') ?? $request->input('call_id');
    $eventType = $request->input('CallStatus') ?? $request->input('event_type')
        ?? $request->route()?->getName();

    if ($callId && $eventType) {
        return hash('sha256', json_encode([
            'call_id' => $callId,
            'event_type' => $eventType,
        ], JSON_PRESERVE_ZERO_FRACTION));
    }

    // Priority 3: Full payload
    $payload = $request->all();
    if (!empty($payload)) {
        return hash('sha256', json_encode($payload, JSON_PRESERVE_ZERO_FRACTION));
    }

    return null;
}
```

### Replay Protection

Webhooks with timestamps are validated to prevent replay attacks:

- **Too old:** If `timestamp` is more than 5 minutes in the past → rejected with 400 (except CDR webhooks, which are accepted with a warning log due to processing delays).
- **Future:** If `timestamp` is more than 1 minute in the future → rejected with 400.

### Response Caching

When a webhook is first processed, its response is cached:

- **Small responses** (≤ 100 KB): Full content cached and returned on duplicate.
- **Large responses** (> 100 KB): Only metadata cached; duplicate returns empty 200.

### Routes with Idempotency

| Route | Middleware Stack |
|-------|-----------------|
| `/api/webhooks/cloudonix/call-initiated` | `webhook.signature`, `webhook.idempotency` |
| `/api/webhooks/cloudonix/call-status` | `webhook.signature`, `webhook.idempotency` |
| `/api/webhooks/cloudonix/cdr` | `webhook.signature`, `webhook.idempotency` |
| `/api/webhooks/cloudonix/dialer` | `webhook.signature`, `webhook.idempotency` |
| `/api/webhooks/auto-dialer/call-status` | `webhook.signature`, `webhook.idempotency` |
| `/api/webhooks/auto-dialer/amd-result` | `webhook.signature`, `webhook.idempotency` |

**Note:** `/api/webhooks/cloudonix/session-update` uses only `webhook.signature` because it is a high-velocity endpoint.

---

## Auto-Dialer Webhooks

Auto-dialer webhooks reuse the same middleware stack as status/CDR webhooks:

| Route | Middleware | Controller |
|-------|-----------|------------|
| `/api/webhooks/auto-dialer/call-status` | `webhook.signature`, `webhook.idempotency` | `AutoDialerWebhookController@callStatus` |
| `/api/webhooks/auto-dialer/amd-result` | `webhook.signature`, `webhook.idempotency` | `AutoDialerWebhookController@amdResult` |
| `/api/webhooks/cloudonix/dialer` | `webhook.signature`, `webhook.idempotency` | `DialerWebhookProxyController@handleCloudonixWebhook` |

Authentication follows the **unified flow** described in [Status/CDR Webhook Authentication](#statuscdr-webhook-authentication):

1. Domain is extracted from the payload.
2. Organization is matched via `CloudonixSettings`.
3. Bearer token is validated if `domain_requests_api_key` is configured.

---

## AMD Action Callback

**Route:** `POST /api/voice/amd-action`  
**Controller:** `App\Http\Controllers\Voice\AmdActionController`

The AMD (Answering Machine Detection) action callback is a special endpoint that receives detection results from the AMD worker. It uses **inline authentication** (not middleware) because it does not follow the organization-scoped pattern.

### Authentication

```php
$authHeader = $request->header('Authorization', '');
$expectedToken = config('services.amd_worker.api_token', env('AMD_WORKER_API_TOKEN', ''));

if (!str_starts_with($authHeader, 'Bearer ') || !hash_equals('Bearer '.$expectedToken, $authHeader)) {
    return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
}
```

### Key Differences

- Uses a **global token** (`AMD_WORKER_API_TOKEN`) rather than a per-organization key.
- Validates the **full header string** (`Bearer <token>`) against `hash_equals()`.
- Returns **JSON** on error (not CXML).

### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `callSid` | string | Yes | Cloudonix call SID |
| `streamSid` | string | Yes | Stream SID |
| `session` | string | Yes | Session token |
| `result` | string | Yes | `voicemail`, `human`, or `unknown` |
| `action` | string | Yes | `HANGUP`, `CONTINUE`, or URL |
| `confidence` | numeric | No | Detection confidence |
| `detectionTimeMs` | integer | No | Detection time in milliseconds |
| `reason` | string | No | Detection reason |

---

## Dialer Worker Authentication

**Middleware:** `App\Http\Middleware\DialerWorkerAuth`  
**Alias:** `dialer.worker.auth`

The Go dialer worker authenticates to Laravel API routes under `/api/v1/dialer/worker/*` using a Bearer token.

### Behavior

1. Extracts the Bearer token from the `Authorization` header.
2. Compares it to `config('services.dialer_worker.token')` (`DIALER_WORKER_API_TOKEN`).
3. Optionally accepts a secondary token (`DIALER_WORKER_API_TOKEN_SECONDARY`) for zero-downtime rotation.
4. Uses `hash_equals()` for constant-time comparison.

If the primary token is not configured, the middleware returns `503 Service Unavailable`.

### Protected Routes

See `routes/api.php` for the full `/api/v1/dialer/worker/*` route group.

---

## Configuration

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `AMD_WORKER_API_TOKEN` | Yes | *(empty)* | Shared secret for AMD worker callbacks |
| `DIALER_WORKER_API_TOKEN` | Yes | *(empty)* | Token for dialer worker API authentication |
| `DIALER_WORKER_API_TOKEN_SECONDARY` | No | *(empty)* | Secondary token for zero-downtime rotation |
| `WEBHOOK_IDEMPOTENCY_TTL` | No | `86400` | Idempotency key TTL in seconds (24h) |
| `WEBHOOK_MAX_CACHE_SIZE` | No | `102400` | Max response cache size in bytes (100 KB) |
| `WEBHOOK_REPLAY_MAX_AGE` | No | `300` | Max webhook age in seconds (5 min) |
| `CLOUDONIX_CDR_AUTH_KEY` | No | *(empty)* | Optional global CDR auth key (legacy) |

### Per-Organization Settings

| Setting | Table | Description |
|---------|-------|-------------|
| `domain_requests_api_key` | `cloudonix_settings` | Encrypted Bearer token for webhook auth |
| `domain_name` | `cloudonix_settings` | Cloudonix domain name |
| `domain_uuid` | `cloudonix_settings` | Cloudonix domain UUID |

The `domain_requests_api_key` is **encrypted at rest** using Laravel's encryption:

```php
// app/Models/CloudonixSettings.php
protected $casts = [
    'domain_requests_api_key' => 'encrypted',
];
```

### Middleware Aliases

Registered in `bootstrap/app.php`:

```php
$middleware->alias([
    'webhook.signature' => \App\Http\Middleware\VerifyCloudonixSignature::class,
    'webhook.idempotency' => \App\Http\Middleware\EnsureWebhookIdempotency::class,
    'voice.webhook.auth' => \App\Http\Middleware\VerifyVoiceWebhookAuth::class,
    'dialer.worker.auth' => \App\Http\Middleware\DialerWorkerAuth::class,
]);
```

---

## Error Responses

### Voice Routing (CXML)

| Scenario | HTTP Status | Response |
|----------|-------------|----------|
| Missing `Authorization` | 401 | `<Say>Unauthorized. Authentication failed.</Say><Hangup/>` |
| Non-Bearer format | 401 | `<Say>Unauthorized. Authentication failed.</Say><Hangup/>` |
| Missing `Domain` | 400 | `<Say>Bad request. Missing Domain in request.</Say><Hangup/>` |
| Missing `to` number | 400 | `<Say>Bad request. Missing destination number.</Say><Hangup/>` |
| Domain not found | 401 | `<Say>Unauthorized. Authentication failed.</Say><Hangup/>` |
| API key not configured | 401 | `<Say>Unauthorized. Authentication failed.</Say><Hangup/>` |
| Invalid token | 401 | `<Say>Unauthorized. Authentication failed.</Say><Hangup/>` |

### Status/CDR (JSON)

| Scenario | HTTP Status | Response |
|----------|-------------|----------|
| Missing domain parameter | 400 | `{"error": "Bad Request - Missing domain parameter"}` |
| Unknown domain | 401 | `{"error": "Unauthorized - Unknown domain"}` |
| Bearer token required | 401 | `{"error": "Unauthorized - Bearer token required"}` |
| Invalid Bearer token | 401 | `{"error": "Unauthorized - Invalid Bearer token"}` |

### Idempotency (JSON)

| Scenario | HTTP Status | Response |
|----------|-------------|----------|
| Replay attack (too old) | 400 | `{"error": "Webhook Expired", "message": "Webhook timestamp is too old..."}` |
| Future timestamp | 400 | `{"error": "Invalid Timestamp", "message": "Webhook timestamp is in the future."}` |
| Duplicate webhook | 200 | Cached response or empty body |

### AMD Action (JSON)

| Scenario | HTTP Status | Response |
|----------|-------------|----------|
| Missing/invalid auth | 401 | `{"status": "error", "message": "Unauthorized"}` |

---

## Security Properties

### Timing Attack Prevention

All token comparisons use `hash_equals()`:

```php
// Prevents timing attacks by comparing strings in constant time
hash_equals($expectedToken, $providedToken);
```

This applies to:
- `VerifyVoiceWebhookAuth`
- `VerifyCloudonixSignature`
- `AmdActionController`
- `DialerWorkerAuth`

### Sensitive Data Protection

The `domain_requests_api_key` is:
- **Encrypted at rest** via Laravel's `encrypted` cast.
- **Masked in API responses** (only last 4 characters shown).
- **Excluded from logs** via exception context filtering.

### Log Sanitization

The following fields are automatically redacted from exception context:

```php
// bootstrap/app.php
$exceptions->context(function ($data) {
    $input = $data->except([
        'token', 'access_token', 'api_key', 'api_token',
        'domain_api_key', 'domain_requests_api_key', 'domain_cdr_auth_key',
        'secret', 'webhook_secret', 'sip_password',
    ]);
});
```

### Request Logging

All webhook middleware logs:
- Client IP address
- Request path
- Organization ID (on success)
- Domain
- Failure reason (on failure)

No sensitive tokens are ever logged.

---

## Testing Webhooks

### Voice Routing

```bash
# Valid request
curl -X POST https://your-opbx.com/api/voice/route \
  -H "Authorization: Bearer YOUR_DOMAIN_REQUESTS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "Domain": "your-domain.cloudonix.io",
    "from": "+14155551234",
    "to": "+14155555678"
  }'

# Invalid token (returns CXML 401)
curl -X POST https://your-opbx.com/api/voice/route \
  -H "Authorization: Bearer wrong-token" \
  -H "Content-Type: application/json" \
  -d '{
    "Domain": "your-domain.cloudonix.io",
    "from": "+14155551234",
    "to": "+14155555678"
  }'
```

### Status Webhook

```bash
# Organization with API key configured
curl -X POST https://your-opbx.com/api/webhooks/cloudonix/call-status \
  -H "Authorization: Bearer YOUR_DOMAIN_REQUESTS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "your-domain.cloudonix.io",
    "call_id": "abc123",
    "event_type": "answered"
  }'

# Organization without API key (backward compatible)
curl -X POST https://your-opbx.com/api/webhooks/cloudonix/call-status \
  -H "Content-Type: application/json" \
  -d '{
    "domain": "your-domain.cloudonix.io",
    "call_id": "abc123",
    "event_type": "answered"
  }'
```

### CDR Webhook

```bash
curl -X POST https://your-opbx.com/api/webhooks/cloudonix/cdr \
  -H "Authorization: Bearer YOUR_DOMAIN_REQUESTS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "owner": {
      "domain": {
        "name": "your-domain.cloudonix.io",
        "uuid": "123e4567-e89b-12d3-a456-426614174000"
      }
    },
    "call_id": "abc123"
  }'
```

### AMD Action

```bash
curl -X POST https://your-opbx.com/api/voice/amd-action \
  -H "Authorization: Bearer YOUR_AMD_WORKER_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "callSid": "abc123",
    "streamSid": "stream456",
    "session": "session789",
    "result": "voicemail",
    "action": "HANGUP",
    "confidence": 0.95,
    "detectionTimeMs": 2500
  }'
```

### Health Check

```bash
curl https://your-opbx.com/api/health
```

---

## See Also

- [`app/Http/Middleware/VerifyVoiceWebhookAuth.php`](../app/Http/Middleware/VerifyVoiceWebhookAuth.php)
- [`app/Http/Middleware/VerifyCloudonixSignature.php`](../app/Http/Middleware/VerifyCloudonixSignature.php)
- [`app/Http/Middleware/EnsureWebhookIdempotency.php`](../app/Http/Middleware/EnsureWebhookIdempotency.php)
- [`app/Http/Controllers/Voice/AmdActionController.php`](../app/Http/Controllers/Voice/AmdActionController.php)
- [`app/Http/Middleware/DialerWorkerAuth.php`](../app/Http/Middleware/DialerWorkerAuth.php)
- [`routes/webhooks.php`](../routes/webhooks.php)
- [`config/webhooks.php`](../config/webhooks.php)
- [Cloudonix API Security Documentation](https://developers.cloudonix.com/Documentation/apiSecurity)
