---
date: 2026-06-22
status: draft
title: Call Tracking Feature Specification
version: 1.0
---

# Call Tracking Feature Specification

> **Module:** Call Tracking
> **Status:** Draft — pending review
> **Approach:** Hybrid (DIDs provisioned in Phone Numbers, claimed into Call Tracking campaigns)
> **Scope:** v1 — inbound call tracking with campaign attribution, conversion rules, analytics dashboard, custom webhooks, and ad-platform integration placeholders.

---

## 1. Problem Statement

Marketing teams run campaigns across multiple channels (Google Ads, Facebook, print, radio, etc.) and need to know which campaigns drive phone calls. A single business destination number may be reached through many inbound "tracking" numbers, each tied to a specific campaign or media type.

OPBX already supports inbound DIDs and a `forward` extension type, but extensions are limited to 5 digits and do not provide campaign-level analytics, conversion rules, or attribution dashboards. This feature introduces a first-class Call Tracking module that decouples tracking numbers from extensions while reusing existing DID provisioning and voice routing infrastructure.

---

## 2. Goals

1. Allow an organization to create **Call Tracking Campaigns**.
2. Assign one or more provisioned DIDs to a campaign as **tracking numbers**.
3. Route calls to either an **external forward number** or an existing **OPBX destination**.
4. Capture call-level attribution (source, medium, campaign) for analytics.
5. Support **per-campaign conversion rules** (answered duration, disposition).
6. Provide an **analytics dashboard** with KPIs, charts, and campaign comparison.
7. Send **custom webhook notifications** for tracking-specific events.
8. Provide placeholders for **Google Ads** and **Facebook/Meta** conversion upload integrations.

---

## 3. Non-Goals (v1)

- WebRTC softphone or outbound call tracking.
- Billing or cost-per-call analytics.
- AI transcription or sentiment analysis.
- Automatic provisioning or release of DIDs from Cloudonix (DIDs are still provisioned manually via Phone Numbers).
- Direct insertion of phone numbers into Google/Meta ad creatives via API (not supported by ad platforms).
- Real-time live-call dashboard for call tracking (use existing Live Calls module).

---

## 4. Terminology

| Term | Definition |
|------|------------|
| **Campaign** | A marketing initiative (e.g., "Summer Google Ads"). Owns conversion rules and destination. |
| **Tracking Number** | A DID assigned to a campaign. Callers see this number; OPBX routes and attributes the call. |
| **Destination** | Where the tracking call is sent: external E.164 number or OPBX destination. |
| **Conversion** | A tracked call that satisfies the campaign's conversion rule. |
| **Source / Medium** | Marketing attribution fields (e.g., source=google, medium=cpc). |
| **DNI** | Dynamic Number Insertion — JS snippet that swaps a displayed number based on URL parameters. |

---

## 5. User Workflow

1. User provisions one or more DIDs in **Phone Numbers**.
2. User sets each DID's routing type to **Call Tracking** and selects or creates a campaign.
3. User opens the **Call Tracking** module and configures campaigns:
   - Name, source, medium, description, status.
   - Destination (forward number or OPBX destination).
   - Conversion rule (minimum answered duration, disposition).
   - Custom webhook notification settings.
4. User copies the **DNI snippet** and embeds it on landing pages.
5. Calls arrive on tracking numbers; OPBX routes, records CDRs, evaluates conversions, and updates analytics.
6. User views the **Call Tracking Dashboard** and exports data if needed.

---

## 6. Data Model

### 6.1 `call_tracking_campaigns`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| organization_id | FK | Tenant scope |
| name | string(255) | Display name |
| source | string(100) nullable | e.g. google, facebook |
| medium | string(100) nullable | e.g. cpc, display |
| description | text nullable | |
| status | enum | active, inactive |
| destination_type | enum | forward, extension, ring_group, business_hours, conference_room, ivr_menu, ai_assistant, ai_load_balancer |
| destination_config | JSON | `{forward_to: "+1..."}` or `{extension_id: N}` etc. |
| conversion_rule | JSON | `{min_answered_duration_seconds: 60, require_disposition: "answered"}` |
| created_at / updated_at | timestamps | |

### 6.2 `call_tracking_numbers`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| organization_id | FK | Tenant scope |
| call_tracking_campaign_id | FK | |
| did_number_id | FK | References `did_numbers` |
| friendly_name | string nullable | e.g. "Google Ads — NYC" |
| status | enum | active, inactive |
| created_at / updated_at | timestamps | |

### 6.3 `call_tracking_sessions`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| organization_id | FK | Tenant scope |
| call_tracking_campaign_id | FK | |
| call_tracking_number_id | FK | |
| did_number_id | FK | Denormalized for quick lookup |
| call_id | string | From Cloudonix |
| session_id | string nullable | From Cloudonix |
| caller_number | string | E.164 normalized |
| caller_country | string nullable | From libphonenumber |
| called_number | string | Tracking number |
| source | string nullable | Captured attribution |
| medium | string nullable | Captured attribution |
| campaign_name | string nullable | Snapshot at call time |
| disposition | string | From CDR: answered, busy, no-answer, failed, etc. |
| duration | int | Total call duration in seconds |
| billsec | int | Billed seconds |
| is_answered | boolean | Derived from disposition |
| is_converted | boolean | Derived from conversion rule |
| conversion_value | decimal nullable | Optional value (placeholder) |
| started_at | datetime | Call start time |
| answered_at | datetime nullable | |
| ended_at | datetime nullable | |
| raw_cdr | JSON | Full Cloudonix CDR payload |
| created_at / updated_at | timestamps | |

### 6.4 `call_tracking_notification_settings`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| organization_id | FK | Tenant scope |
| call_tracking_campaign_id | FK | One row per campaign |
| webhook_url | string | SSRF-validated URL |
| auth_method | enum | none, bearer_token, basic_auth |
| auth_secret | string nullable | Encrypted |
| auth_username | string nullable | For basic auth |
| enabled_events | JSON | e.g. ["call.received","call.converted","call.missed"] |
| is_active | boolean | |
| created_at / updated_at | timestamps | |

### 6.5 `call_tracking_notification_logs`

Mirrors `call_notification_logs` structure but scoped to tracking events.

---

## 7. Routing & Runtime Behavior

### 7.1 New DID Routing Type

`did_numbers.routing_type` adds `call_tracking`. `routing_config` stores:

```json
{
  "call_tracking_campaign_id": 42
}
```

### 7.2 Voice Routing Strategy

A new strategy `CallTrackingRoutingStrategy` is registered in `VoiceRoutingManager`:

1. Receives inbound webhook with `To` = tracking number.
2. Loads DID; if `routing_type === 'call_tracking'`, resolves campaign.
3. If campaign inactive → return `unavailable` CXML.
4. If campaign `destination_type === 'forward'` → use `CxmlBuilder::simpleDial(...)` to the E.164 number.
5. If `destination_type` is an OPBX destination → delegate to the existing strategy for that type.
6. All calls are processed idempotently with Redis locks per `call_id`.

### 7.3 CDR Processing

`ProcessCDRJob` is extended (or a dedicated listener) to:

1. Look up `call_tracking_numbers` by called number.
2. Create/update `call_tracking_sessions`.
3. Evaluate conversion rule and set `is_converted`.
4. Trigger custom webhook notification if configured and event enabled.
5. Placeholder: enqueue conversion upload job for Google/Meta adapters.

### 7.4 Conversion Rule Evaluation

```
is_converted =
  disposition matches rule.require_disposition (default: answered)
  AND billsec >= rule.min_answered_duration_seconds
```

Rules are evaluated at CDR time. A re-processing endpoint may be provided for retroactive rule changes (v2).

---

## 8. Dynamic Number Insertion (DNI)

### 8.1 Public Endpoint

`GET /v1/call-tracking/dni/swap` — returns the tracking number to display for the current visitor.

Query parameters:
- `utm_source`, `utm_medium`, `utm_campaign` (optional)
- `default_number` (fallback)
- `organization` or resolved via domain (TBD)

Response:
```json
{
  "tracking_number": "+14155551234",
  "campaign_id": 42,
  "campaign_name": "Summer Google Ads",
  "source": "google",
  "medium": "cpc"
}
```

### 8.2 JS Snippet

A small, framework-agnostic script the user embeds:

```html
<script async src="https://opbx.example.com/js/call-tracking-dni.js"
  data-organization="my-org"
  data-default="+14155550000"
  data-selector=".phone-number"></script>
```

The script reads URL params, calls the swap endpoint, and replaces matched elements. It stores the assigned number in `localStorage` or a cookie for the session to avoid flicker.

### 8.3 Matching Logic

If `utm_source`/`utm_medium` are provided, OPBX returns a number from the campaign with matching source/medium. If no match, it returns the organization's default tracking number (if configured) or the fallback. If URL params are absent, the campaign's default number is used.

---

## 9. Analytics Dashboard

### 9.1 Filters

- Date range
- Campaign(s)
- Source / medium
- Group by: day, week, month

### 9.2 KPI Cards

- Total calls
- Unique callers
- Answered calls
- Missed calls
- Average duration
- Conversions
- Conversion rate

### 9.3 Charts

- Calls over time (grouped)
- Conversions over time (grouped)
- Top campaigns by calls/conversions
- Top sources/media by conversions

### 9.4 Table

Campaign rollup with: name, source/medium, calls, answered, missed, conversions, conversion rate, avg duration.

### 9.5 Detail View (v1.1 optional)

Click a campaign to see a dedicated report; v1 can expose the same dashboard with campaign filter pre-selected.

---

## 10. Custom Webhook Notifications

### 10.1 Events

- `call.received` — CDR received
- `call.answered`
- `call.missed`
- `call.converted`
- `call.failed`

### 10.2 Payload Example

```json
{
  "event": "call.converted",
  "event_id": "evt_...",
  "timestamp": "2026-06-22T12:34:56Z",
  "organization_id": 1,
  "campaign": {
    "id": 42,
    "name": "Summer Google Ads"
  },
  "tracking_number": "+14155551234",
  "caller_number": "+14155559876",
  "source": "google",
  "medium": "cpc",
  "duration": 72,
  "billsec": 70,
  "conversion_value": null
}
```

### 10.3 Delivery

Reuse existing `WebhookDispatcher` / `NotificationPayloadBuilder` patterns with SSRF validation, retry, and audit logging.

---

## 11. Ad-Platform Integration Placeholders

### 11.1 Google Ads

- Store OAuth credentials per organization (encrypted).
- Provide UI fields: developer token, customer ID, conversion action resource name.
- Stub service `GoogleAdsConversionUploadService` with method `uploadCallConversion(callSession)`.
- Real implementation will call `ConversionUploadService.UploadClickConversions` with GCLID and hashed phone number when those values are available.

### 11.2 Meta Conversions API

- Store pixel ID and access token per organization (encrypted).
- Stub service `MetaConversionsApiService` with method `sendOfflineEvent(callSession)`.
- Real implementation will POST to Conversions API with event name `Contact` or `Lead`.

### 11.3 UI Placeholders

- Settings section under each campaign or organization-level integration settings.
- Toggle "Enable Google Ads upload" / "Enable Meta upload" (disabled by default).
- Display "coming soon" or "manual setup required" helper text.

---

## 12. API Endpoints

### 12.1 Campaigns

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/v1/call-tracking/campaigns` | List campaigns |
| POST | `/v1/call-tracking/campaigns` | Create campaign |
| GET | `/v1/call-tracking/campaigns/{campaign}` | Show campaign |
| PUT | `/v1/call-tracking/campaigns/{campaign}` | Update campaign |
| DELETE | `/v1/call-tracking/campaigns/{campaign}` | Delete campaign |

### 12.2 Tracking Numbers

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/v1/call-tracking/campaigns/{campaign}/numbers` | List numbers |
| POST | `/v1/call-tracking/campaigns/{campaign}/numbers` | Assign a DID |
| DELETE | `/v1/call-tracking/numbers/{number}` | Remove assignment |

### 12.3 Sessions / Analytics

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/v1/call-tracking/sessions` | List sessions (filterable) |
| GET | `/v1/call-tracking/analytics` | Aggregate analytics |
| GET | `/v1/call-tracking/analytics/export` | CSV export |

### 12.4 Notifications

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/v1/call-tracking/campaigns/{campaign}/notifications` | Get settings |
| PUT | `/v1/call-tracking/campaigns/{campaign}/notifications` | Update settings |
| POST | `/v1/call-tracking/campaigns/{campaign}/notifications/test` | Send test event |

### 12.5 DNI

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/v1/call-tracking/dni/swap` | Return tracking number to display |

---

## 13. Frontend Pages

- **CallTrackingCampaigns** — list, create, edit, delete campaigns.
- **CallTrackingCampaignForm** — campaign details, destination selector, conversion rule.
- **CallTrackingNumbers** — assign/remove DIDs to campaign.
- **CallTrackingDashboard** — analytics with filters and charts.
- **CallTrackingSessions** — call log table with attribution.
- **CallTrackingNotificationsSettings** — per-campaign webhook settings.
- **CallTrackingDniSnippet** — copy/paste JS snippet.

---

## 14. Authorization

Use existing RBAC. Recommended policy:

| Action | Owner | PBX Admin | PBX User | Reporter |
|--------|-------|-----------|----------|----------|
| viewAny/view | Yes | Yes | Yes | Yes |
| create | Yes | Yes | No | No |
| update | Yes | Yes | No | No |
| delete | Yes | Yes | No | No |

---

## 15. Testing Strategy

- Unit: conversion rule evaluator.
- Feature: CRUD campaigns, assign DIDs, route call via new strategy.
- Feature: CDR creates session with correct attribution and conversion flag.
- Feature: custom webhook fires on enabled events.
- Feature: DNI endpoint returns correct number based on source/medium.
- Feature: RBAC scoping and tenant isolation.

---

## 16. Open Questions / Decisions

1. Should a DID be restricted to only one campaign at a time? **Decision:** Yes.
2. Should deleting a campaign release DIDs back to Phone Numbers or delete them? **Decision:** Release assignments only; never delete DIDs.
3. Should the DNI endpoint require authentication? **Decision:** No, it is public but rate-limited per organization.
4. Should conversion rules support time-of-day/day-of-week in v1? **Decision:** No, placeholder only.
5. Should sessions be created from `call-initiated` webhooks or only from CDR? **Decision:** Only from CDR for durability; v2 may add live session state.

---

## 17. References

- OPBX Memory: `phone-numbers.md`, `voice-routing-engine.md`, `call-detail-records.md`, `call-notifications.md`, `destination-routing-system.md`
- Cloudonix Docs: https://developers.cloudonix.com/
- Google Ads API — Manage offline conversions: https://developers.google.com/google-ads/api/docs/conversions/upload-clicks
- Google Ads Help — About phone call conversion tracking: https://support.google.com/google-ads/answer/6100664
- Meta Conversions API: https://developers.facebook.com/docs/marketing-api/conversions-api

---

*Drafted: 2026-06-22*
