# Webhook Authentication Security Model

## Overview

This document describes the unified webhook authentication model for Cloudonix webhooks (session-update and CDR).

## Authentication Flow

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. EXTRACT DOMAIN                                               │
│    - Check 'domain' field in payload                            │
│    - Check 'owner.domain.name' (CDR format)                     │
│    - Check 'owner.domain.uuid' (CDR format)                     │
│                                                                 │
│    If no domain → 400 Bad Request                               │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2. MATCH DOMAIN TO ORGANIZATION                                 │
│    - Query CloudonixSettings by domain_name                     │
│    - If not found, query by domain_uuid                         │
│                                                                 │
│    If no organization found → 401 Unauthorized                  │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3. AUTHENTICATE REQUEST                                         │
│                                                                 │
│    Case A: Organization HAS domain_requests_api_key             │
│    ├─ Bearer token MUST be present                             │
│    ├─ Bearer token MUST match domain_requests_api_key          │
│    ├─ If missing token → 401 Unauthorized                      │
│    └─ If invalid token → 401 Unauthorized                      │
│                                                                 │
│    Case B: Organization has NO domain_requests_api_key          │
│    ├─ Bearer token is OPTIONAL                                 │
│    ├─ If token provided, it is IGNORED                         │
│    └─ Request processed normally                               │
└─────────────────────────────────────────────────────────────────┘
```

## Security Properties

### Domain Validation (Always Required)
- Every webhook MUST include a `domain` parameter
- Domain is extracted from multiple possible locations to support different webhook formats
- Unknown domains are rejected with 401 Unauthorized

### Token Validation (Conditional)
- Organizations can optionally configure `domain_requests_api_key`
- If configured, all webhooks for that organization MUST include a valid Bearer token
- If not configured, webhooks are accepted without authentication (backward compatible)

### Consistent Error Responses
- Missing domain → 400 Bad Request
- Unknown domain → 401 Unauthorized (not 404, to prevent information leakage)
- Authentication failure → 401 Unauthorized

## Configuration

### Enable Webhook Authentication for Organization

1. Navigate to Cloudonix Settings for the organization
2. Set `domain_requests_api_key` to a secure random string
3. Configure Cloudonix to send Bearer token in webhook requests

### Disable Webhook Authentication (Default)

- Leave `domain_requests_api_key` empty/null
- Webhooks will be accepted with or without Bearer tokens

## Implementation Details

### File: `app/Http/Middleware/VerifyCloudonixSignature.php`

The middleware handles authentication for all webhook routes:
- `/api/webhooks/cloudonix/session-update`
- `/api/webhooks/cloudonix/cdr`
- `/api/webhooks/cloudonix/call-status`
- `/api/webhooks/cloudonix/call-initiated`

### Token Extraction

```php
Authorization: Bearer <token>
```

The token is extracted from the standard Authorization header with Bearer scheme.

### Domain Extraction Priority

1. `payload['domain']` - Session-update, call-status webhooks
2. `payload['owner']['domain']['name']` - CDR webhooks
3. `payload['owner']['domain']['uuid']` - Alternative CDR format

### Security Considerations

1. **Timing-Safe Comparison**: Token comparison uses `hash_equals()` to prevent timing attacks
2. **Encrypted Storage**: `domain_requests_api_key` is stored encrypted in database
3. **No Information Leakage**: Unknown domains return 401 (not 404) to prevent domain enumeration
4. **IP Logging**: All authentication attempts are logged with IP address for audit

## Migration Guide

### For Existing Organizations (No Auth)

No action required. Organizations without `domain_requests_api_key` configured will continue to work without authentication.

### For Organizations Enabling Auth

1. Generate a secure API key:
   ```bash
   openssl rand -hex 32
   ```

2. Set the key in Cloudonix Settings

3. Configure Cloudonix portal to include the Bearer token in webhook requests

4. Test with a sample request:
   ```bash
   curl -X POST https://your-domain.com/api/webhooks/cloudonix/session-update \
     -H "Authorization: Bearer YOUR_API_KEY" \
     -H "Content-Type: application/json" \
     -d '{"domain": "your-domain.cloudonix.net", ...}'
   ```

## Testing

### Test Without Authentication (No Key Configured)
```bash
curl -X POST https://your-domain.com/api/webhooks/cloudonix/session-update \
  -H "Content-Type: application/json" \
  -d '{"domain": "your-domain.cloudonix.net", "status": "ringing"}'
# Expected: 200 OK
```

### Test With Valid Authentication (Key Configured)
```bash
curl -X POST https://your-domain.com/api/webhooks/cloudonix/session-update \
  -H "Authorization: Bearer VALID_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"domain": "your-domain.cloudonix.net", "status": "ringing"}'
# Expected: 200 OK
```

### Test With Invalid Authentication (Key Configured)
```bash
curl -X POST https://your-domain.com/api/webhooks/cloudonix/session-update \
  -H "Authorization: Bearer INVALID_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"domain": "your-domain.cloudonix.net", "status": "ringing"}'
# Expected: 401 Unauthorized
```

### Test Missing Authentication (Key Configured)
```bash
curl -X POST https://your-domain.com/api/webhooks/cloudonix/session-update \
  -H "Content-Type: application/json" \
  -d '{"domain": "your-domain.cloudonix.net", "status": "ringing"}'
# Expected: 401 Unauthorized
```

## Related Files

- `app/Http/Middleware/VerifyCloudonixSignature.php` - Authentication middleware
- `app/Models/CloudonixSettings.php` - Settings model with encrypted API keys
- `routes/webhooks.php` - Webhook route definitions
