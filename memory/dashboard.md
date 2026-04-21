# Dashboard & Application Config

## Overview
Application-level configuration exposed to the frontend SPA. Controls deployment mode, webhook URL resolution, and feature flags like reCAPTCHA.

## Source Files
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/ConfigurationController.php` | Config endpoint (28 lines) |
| `app/Services/ApplicationConfig.php` | Config management (145 lines) |
| `frontend/src/pages/Dashboard.tsx` | Main dashboard page with auto-refresh timer |
| `frontend/src/contexts/ConfigContext.tsx` | Config state provider |
| `frontend/src/services/config.service.ts` | Config API calls |
| `frontend/src/services/dashboard.service.ts` | Dashboard data API |

## API Route
| Method | URI | Purpose |
|--------|-----|---------|
| GET | `/v1/configuration` | Public app configuration |

## Configuration Summary Response (ApplicationConfig::getConfigurationSummary)
```json
{
  "mode": "development|production",
  "is_production": false,
  "has_application_webhook_url": false,
  "is_valid_configuration": true,
  "warnings": [],
  "hide_webhook_fields": false,
  "recaptcha": { "enabled": false, "site_key": "..." }
}
```

## Key Settings
| Setting | Env Variable | Purpose |
|---------|-------------|---------|
| Mode | `OPBX_APPLICATION_MODE` | development or production |
| Webhook Base URL | `OPBX_APPLICATION_WEBHOOK_BASEURL` | Application-level webhook override |
| reCAPTCHA | `RECAPTCHA_ENABLED`, `RECAPTCHA_SITE_KEY` | Registration protection |

## Webhook URL Resolution Priority
1. `OPBX_APPLICATION_WEBHOOK_BASEURL` (application-level, hides per-org fields)
2. `CloudonixSettings::webhook_base_url` (per-organization)
3. `config('app.url')` (fallback)

## Configuration Warnings
- Production mode without webhook URL
- Invalid URL format (must be http/https)

## Frontend Dashboard
The Dashboard page displays organization statistics (extensions, DIDs, users, etc.) and system status. Uses TanStack Query for data fetching.

### Auto-Refresh
- Multiple queries refresh at different intervals: active calls (15s), everything else (30s)
- `RefreshTimer` component shows progress bar cycling every 15s (most frequent interval)
- Manual refresh triggers all queries simultaneously

## Related Modules
- [Settings & Cloudonix](settings-cloudonix.md) - Per-org webhook URL configuration
