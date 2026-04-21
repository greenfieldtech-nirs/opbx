# Settings & Cloudonix Integration

## Overview
Per-organization Cloudonix CPaaS configuration: API credentials, voice application setup, webhook URLs, outbound trunks. Settings are encrypted at rest.

## Source Files
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/SettingsController.php` | Settings CRUD + credential validation + voice app setup |
| `app/Models/CloudonixSettings.php` | Settings model (encrypted API keys) |
| `app/Services/CloudonixClient/CloudonixClient.php` | HTTP client facade with circuit breaker |
| `app/Services/CloudonixClient/CloudonixCallsClient.php` | Call management + **switchVoiceApplication()** 🆕 |
| `app/Services/CloudonixClient/CloudonixSessionsClient.php` | Session management + **updateSessionProfile()** 🆕 |
| `app/Services/Cloudonix/CloudonixVoiceService.php` | TTS voice fetching |
| `app/Services/Cloudonix/LanguageMapper.php` | Language code to name mapping |
| `app/Services/WebhookUrlResolver.php` | Dynamic webhook URL resolution |
| `app/Services/ApplicationConfig.php` | Application-level config |
| `frontend/src/pages/Settings.tsx` | Settings page (owner-only) |
| `frontend/src/services/settings.service.ts` | API calls |
| `frontend/src/services/cloudonix.service.ts` | Cloudonix settings API |

## Database: `cloudonix_settings` Table (HasOne per Organization)
| Column | Type | Notes |
|--------|------|-------|
| organization_id | FK | One-to-one |
| domain_uuid | string | Cloudonix domain UUID |
| domain_name | string | Cloudonix domain name |
| domain_api_key | string | **Encrypted** at rest |
| domain_requests_api_key | string | **Encrypted** - webhook auth key |
| webhook_base_url | string nullable | Override URL for webhooks |
| voice_application_id | string nullable | Cloudonix voice app ID |
| voice_application_uuid | string nullable | |
| voice_application_name | string nullable | |
| no_answer_timeout | integer nullable | |
| recording_format | string nullable | |
| cloudonix_package | string nullable | free_tier, standard, etc. |

## Settings Update Flow (SettingsController::updateCloudonixSettings)
1. Validate -> strip masked API keys (detects `***`) -> DB transaction
2. Build Cloudonix profile: call-timeout, recording-media-type, session-update-endpoint, authorization-api-key, cdr-endpoint
3. `updateDomain()` on Cloudonix API
4. `setupVoiceApplication()`: get-or-create voice app, set as domain default

## Voice Application Setup
1. Check for existing `voice_application_id`
2. If exists: update URL on Cloudonix
3. If not: create new app `opbx-routing-application-{random8}`, set as domain default
4. Store app details in settings

## CloudonixClient Key Methods
| Method | Purpose | Caching |
|--------|---------|---------|
| `validateDomainCredentials()` | Tests API key (static) | None |
| `getCallStatus()` | Get call info | 30s |
| `disconnectSession()` | End live call | None |
| **`updateSessionProfile()`** | **Update session profile (AMD result)** | **None** 🆕 |
| **`switchVoiceApplication()`** | **Switch active session to new voice app** | **None** 🆕 |
| `initiateCall()` | Start outbound call | None |
| `listOutboundTrunks()` | Get voice trunks | 5min |
| `getVoices()` | TTS voice list | Via service (1h) |
| `createSubscriber()` | Create SIP extension | None |

### New Methods (2026-04-18)

#### `CloudonixCallsClient::switchVoiceApplication(string $sessionToken, string $url): bool`
Switches an active session to a new voice application URL.
```
POST /calls/{domain-id}/sessions/{token}/application
Body: { "url": "https://example.com/new-app" }
```

#### `CloudonixSessionsClient::updateSessionProfile(int|string $sessionId, array $profile): bool`
Updates custom profile data for a session.
```
PUT /customers/self/domains/{domain-id}/sessions/{session-id}
Body: { "profile": { "amd": { "result": "voicemail", ... } } }
```

## Circuit Breaker
Wraps all Cloudonix API calls. Default: 5 failures threshold, 30s timeout, 60s retry. On open circuit, returns cached data or fallback value.

## Webhook URL Resolution
Priority: `ApplicationConfig::getApplicationWebhookBaseUrl()` (env `OPBX_APPLICATION_WEBHOOK_BASEURL`) -> `CloudonixSettings::webhook_base_url` -> `config('app.url')`

## API Routes
| Method | URI | Purpose |
|--------|-----|---------|
| GET | `/v1/settings/cloudonix` | Get settings (masked keys) |
| PUT | `/v1/settings/cloudonix` | Update settings + sync to Cloudonix |
| POST | `/v1/settings/cloudonix/validate` | Validate credentials |
| POST | `/v1/settings/cloudonix/generate-requests-key` | Generate webhook auth key |
| GET | `/v1/settings/cloudonix/outbound-trunks` | List voice trunks |

## Related Modules
- [Extensions](extensions.md) - Cloudonix subscriber sync requires settings
- [Voice Routing](voice-routing-engine.md) - Webhook URLs configured here
- [IVR Menus](ivr-menus.md) - TTS voices fetched from Cloudonix
- [AMD Worker](amd-worker.md) - Uses `switchVoiceApplication()` and `updateSessionProfile()`
