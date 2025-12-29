# Webhook Authentication Guide

## Overview

OpBX uses two different authentication methods for incoming webhooks from Cloudonix CPaaS, depending on the webhook type and use case. This dual-method approach balances security with operational requirements.

## Authentication Methods

### 1. Voice Routing Webhooks (Real-time Call Control)

**Method:** Bearer Token Authentication
**Middleware:** `VerifyVoiceWebhookAuth`
**Response Format:** CXML (XML)

Voice routing webhooks require real-time responses to control active phone calls. These use per-organization Bearer tokens for authentication.

#### Endpoints

- `POST /api/voice/route` - Main inbound call routing
- `POST /api/voice/ivr-input` - IVR digit input handling
- `POST /api/voice/ring-group-callback` - Ring group sequential routing

#### How It Works

1. Cloudonix sends a webhook request with organization-specific Bearer token
2. Middleware extracts the token from `Authorization: Bearer {token}` header
3. Identifies organization by DID number (external calls) or extension (internal calls)
4. Validates token against organization's `domain_requests_api_key` in CloudonixSettings
5. Attaches `organization_id` to request for controller use
6. Returns CXML error responses on authentication failure

#### Configuration

**Per Organization:**
```php
// CloudonixSettings model
$settings->domain_requests_api_key = 'your-org-specific-token';
```

The token is configured in the Organization Settings UI and stored in the `cloudonix_settings` table.

#### Example Request

```http
POST /api/voice/route HTTP/1.1
Host: your-domain.com
Authorization: Bearer XI_abc123xyz...
Content-Type: application/json

{
  "from": "+14155551234",
  "to": "+14155559999",
  "call_id": "call_abc123"
}
```

#### Example Success Response (CXML)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Dial timeout="30">+14155551001</Dial>
</Response>
```

#### Example Error Response (CXML)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say language="en-US">Unauthorized. Authentication failed.</Say>
  <Hangup/>
</Response>
```

---

### 2. Status & CDR Webhooks (Asynchronous Notifications)

**Method:** HMAC-SHA256 Signature Verification (+ Domain UUID for CDR)
**Middleware:** `VerifyCloudonixSignature`
**Response Format:** JSON

Status update and CDR webhooks are asynchronous notifications that don't require real-time responses. These use cryptographic signature verification.

#### Endpoints

- `POST /api/webhooks/cloudonix/call-initiated` - Call started notification
- `POST /api/webhooks/cloudonix/call-status` - Call status updates
- `POST /api/webhooks/cloudonix/session-update` - Session state changes
- `POST /api/webhooks/cloudonix/cdr` - Call Detail Records (special handling)

#### How It Works

**For call-initiated, call-status, session-update:**
1. Cloudonix signs the webhook payload using HMAC-SHA256 with shared secret
2. Signature sent in `X-Cloudonix-Signature` header
3. Middleware computes expected signature from raw request body
4. Uses timing-safe comparison to validate signature
5. Returns JSON error responses on authentication failure

**For CDR webhooks (special case):**
- CDR webhooks do NOT include Authorization headers or signatures
- Organization identified by `owner.domain.uuid` in the CDR payload
- Matched against `cloudonix_settings.domain_uuid` in database
- Attaches `organization_id` to request for controller use

#### Configuration

**Global Webhook Secret:**
```bash
# .env
CLOUDONIX_WEBHOOK_SECRET=your_64_char_secret_here
CLOUDONIX_VERIFY_SIGNATURE=true
```

Generate a secure secret:
```bash
php artisan generate:password --length=64 --format=base64
# or
openssl rand -base64 64
```

**CDR Domain UUID:**
Configured per organization in CloudonixSettings:
```php
$settings->domain_uuid = 'uuid-from-cloudonix-portal';
```

#### Example Request (with signature)

```http
POST /api/webhooks/cloudonix/call-status HTTP/1.1
Host: your-domain.com
X-Cloudonix-Signature: a1b2c3d4e5f6...
Content-Type: application/json

{
  "call_id": "call_abc123",
  "status": "completed",
  "duration": 120
}
```

#### Example CDR Request (no signature)

```http
POST /api/webhooks/cloudonix/cdr HTTP/1.1
Host: your-domain.com
Content-Type: application/json

{
  "owner": {
    "domain": {
      "uuid": "550e8400-e29b-41d4-a716-446655440000"
    }
  },
  "call_id": "call_abc123",
  "from": "+14155551234",
  "to": "+14155559999",
  "duration": 120
}
```

#### Example Success Response (JSON)

```json
{
  "status": "received",
  "message": "Webhook processed successfully"
}
```

#### Example Error Response (JSON)

```json
{
  "error": "Unauthorized - Invalid signature"
}
```

---

## Configuration Summary

### Environment Variables (.env)

```bash
# Webhook signature verification (for status/CDR webhooks)
CLOUDONIX_WEBHOOK_SECRET=CHANGE_ME_GENERATE_64_CHAR_SECRET
CLOUDONIX_VERIFY_SIGNATURE=true

# Signature header name (can customize if needed)
CLOUDONIX_SIGNATURE_HEADER=X-Cloudonix-Signature
```

### Organization-Specific Settings (Database)

Configured via Organization Settings UI (`/settings`):

- `domain_uuid` - Cloudonix domain UUID for organization identification
- `domain_requests_api_key` - Bearer token for voice routing authentication

---

## Security Best Practices

### 1. Secret Management

**Webhook Secret (Global):**
- Rotate every 90 days or after security incidents
- Use cryptographically secure random generation
- Minimum 64 characters (base64 encoded)
- Never commit to version control
- Store in `.env` file only

**Bearer Tokens (Per Organization):**
- Generate unique token per organization
- Rotate on security incidents or organizational changes
- Minimum 32 characters
- Store securely in database

### 2. Transport Security

**Production:**
- ✅ ALWAYS use HTTPS for webhook endpoints
- ✅ Use valid SSL/TLS certificates
- ✅ Enforce TLS 1.2 or higher
- ❌ NEVER expose webhooks over HTTP

**Development:**
- Use ngrok with HTTPS for local webhook testing
- Never use `http://` ngrok URLs
- Access ngrok web interface at `http://localhost:4040`

### 3. Rate Limiting

Rate limits are enforced via middleware (configured in `.env`):

```bash
# Voice routing (real-time, high volume)
RATE_LIMIT_VOICE=1000  # requests per minute

# Webhooks (asynchronous notifications)
RATE_LIMIT_WEBHOOKS=100  # requests per minute
```

Override in route definitions:
```php
->middleware(['voice.webhook.auth', 'throttle:voice'])
->middleware(['webhook.signature', 'throttle:webhooks'])
```

### 4. Logging & Monitoring

All authentication attempts are logged:

**Success:**
```php
Log::info('Voice webhook authenticated', [
    'ip' => $request->ip(),
    'path' => $request->path(),
    'organization_id' => $organizationId,
]);
```

**Failure:**
```php
Log::warning('Voice webhook auth token verification failed', [
    'ip' => $request->ip(),
    'path' => $request->path(),
    'organization_id' => $organizationId,
]);
```

**Monitoring Recommendations:**
- Alert on repeated authentication failures from same IP
- Monitor for unusual request patterns
- Track authentication failure rates per organization
- Set up alerts for signature verification failures

### 5. Idempotency Protection

All webhooks (except health checks) use idempotency middleware:

```php
->middleware(['webhook.idempotency'])
```

**How it works:**
- Idempotency key generated from webhook payload hash
- Stored in Redis with configurable TTL (default: 24 hours)
- Duplicate requests return cached response
- Prevents duplicate processing of webhook events

**Configuration:**
```bash
CLOUDONIX_IDEMPOTENCY_TTL=86400  # 24 hours in seconds
```

---

## Troubleshooting

### Voice Webhook Authentication Failures

**Symptom:** Calls fail with "Unauthorized" message

**Common Causes:**
1. Bearer token not configured in Organization Settings
2. Token mismatch between Cloudonix portal and database
3. Organization not found (DID or extension lookup failed)

**Debug Steps:**
```bash
# Check logs
docker-compose logs app | grep "Voice webhook"

# Verify organization settings
php artisan tinker
>>> $org = Organization::find(1);
>>> $org->cloudonixSettings->domain_requests_api_key;

# Test DID lookup
>>> DidNumber::where('phone_number', '+14155559999')->first();
```

### Signature Verification Failures

**Symptom:** Status/CDR webhooks return "Unauthorized - Invalid signature"

**Common Causes:**
1. Webhook secret mismatch between `.env` and Cloudonix portal
2. Secret not configured (`CLOUDONIX_WEBHOOK_SECRET` empty)
3. Signature verification disabled in development

**Debug Steps:**
```bash
# Check current secret
php artisan tinker
>>> config('cloudonix.webhook_secret');

# Check if verification is enabled
>>> config('cloudonix.verify_signature');

# View recent signature failures
docker-compose logs app | grep "signature verification failed"
```

### CDR Organization Identification Failures

**Symptom:** CDR webhooks return "Not Found - Unknown domain"

**Common Causes:**
1. Domain UUID not configured in Organization Settings
2. UUID mismatch between Cloudonix portal and database
3. Incorrect payload structure (missing `owner.domain.uuid`)

**Debug Steps:**
```bash
# Check organization domain UUID
php artisan tinker
>>> CloudonixSettings::where('organization_id', 1)->value('domain_uuid');

# View CDR webhook logs
docker-compose logs app | grep "CDR webhook"
```

---

## Testing

### Manual Testing with cURL

**Test Voice Webhook:**
```bash
# Get organization's Bearer token from database first
TOKEN="your-org-bearer-token"

curl -X POST http://localhost/api/voice/route \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "from": "+14155551234",
    "to": "+14155559999",
    "call_id": "test_call_123"
  }'
```

**Test Status Webhook:**
```bash
# Generate signature
PAYLOAD='{"call_id":"test_123","status":"completed"}'
SECRET="your-webhook-secret"
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | cut -d' ' -f2)

curl -X POST http://localhost/api/webhooks/cloudonix/call-status \
  -H "X-Cloudonix-Signature: $SIGNATURE" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"
```

**Test CDR Webhook:**
```bash
# CDR uses domain UUID, no signature required
curl -X POST http://localhost/api/webhooks/cloudonix/cdr \
  -H "Content-Type: application/json" \
  -d '{
    "owner": {
      "domain": {
        "uuid": "your-domain-uuid"
      }
    },
    "call_id": "test_cdr_123",
    "from": "+14155551234",
    "to": "+14155559999",
    "duration": 120
  }'
```

### Automated Tests

```bash
# Run webhook authentication tests
php artisan test --filter=WebhookAuth

# Run voice routing tests
php artisan test --filter=VoiceRouting

# Run all webhook tests
php artisan test tests/Feature/Webhooks/
```

---

## Middleware Reference

### VerifyVoiceWebhookAuth

**File:** `app/Http/Middleware/VerifyVoiceWebhookAuth.php`
**Alias:** `voice.webhook.auth`
**Used For:** Voice routing endpoints
**Authentication:** Bearer token
**Organization ID:** Attached to request as `_organization_id`

**Usage:**
```php
Route::post('/voice/route', [VoiceRoutingController::class, 'handleInbound'])
    ->middleware(['voice.webhook.auth', 'throttle:voice']);
```

### VerifyCloudonixSignature

**File:** `app/Http/Middleware/VerifyCloudonixSignature.php`
**Alias:** `webhook.signature`
**Used For:** Status, CDR, and session update webhooks
**Authentication:** HMAC-SHA256 signature (or domain UUID for CDR)
**Organization ID:** Attached to request as `_organization_id` (for CDR)

**Usage:**
```php
Route::post('/webhooks/cloudonix/call-status', [CloudonixWebhookController::class, 'callStatus'])
    ->middleware(['webhook.signature', 'webhook.idempotency', 'throttle:webhooks']);

Route::post('/webhooks/cloudonix/cdr', [CloudonixWebhookController::class, 'cdr'])
    ->middleware(['webhook.signature', 'webhook.idempotency', 'throttle:webhooks']);
```

---

## Related Documentation

- [Cloudonix API Documentation](https://developers.cloudonix.com)
- [Cloudonix Webhook Security](https://developers.cloudonix.com/Documentation/apiSecurity)
- [CLAUDE.md](../CLAUDE.md) - Project architecture and conventions
- [README.md](../README.md) - Project setup and installation

---

## Changelog

### 2025-12-29 - Phase 1 Task 1.9
- Consolidated 4 middleware into 2
- Added comprehensive documentation
- Clarified voice vs. status/CDR authentication patterns
- Added troubleshooting section
