# Web Phone

> **Last Updated**: 2026-11-22

Browser SIP phone (JsSIP) for the signed-in user's USER extension; the same component runs in the
Embedded Dialer (`frontend/src/embed/main.tsx`, config from `/embed/*`).

## Source map
| File | Purpose |
|------|---------|
| `frontend/src/components/WebPhone/WebPhone.tsx` | UA lifecycle, inbound/outbound calls, transfer, tabs; mounted once in `AppLayout` |
| `frontend/src/components/WebPhone/WebPhoneTabs.tsx` | Keypad / Recents / Settings (Settings hidden when embedded) |
| `frontend/src/components/WebPhone/SettingsView.tsx` | Settings tab: three switches, Save when dirty |
| `app/Services/WebPhoneConfigBuilder.php` | Config for SPA (`/v1/webphone/config`) and embed (`/embed/config`); includes `settings` |
| `app/Services/WebPhone/WebPhoneSettingsService.php` | Settings in the Cloudonix subscriber profile |
| `app/Http/Controllers/Api/WebPhoneSettingsController.php` | `PUT /v1/webphone/settings` (route `webphone.settings.update`, user-token only) |

## Settings (2026-11-22)
- Profile keys `webphone-auto-answer` (default false), `webphone-keep-alive` (true), `webphone-refresh-on-hangup` (true),
  stored as strings "true"/"false". Defaults written on config load when missing; Cloudonix down → defaults, no write.
- **Cloudonix subscriber PUT replaces the whole `profile`** (verified live 2026-11-22 on subscriber 248967); a PUT
  without `profile` keeps it. So writes GET + merge + PUT with msisdn/sipPassword/active (the sync's shape).
- Frontend keeps settings in their own state (not the config query): a config change restarts the UA.
  Auto-answer: 2 s timer on `incomingSession`; keep-alive: `ua.register()` every 60 s while `state === 'ready'`;
  refresh on hangup: `ua.register()` on session `ended`. Second inbound call while live is still 486 Busy.
