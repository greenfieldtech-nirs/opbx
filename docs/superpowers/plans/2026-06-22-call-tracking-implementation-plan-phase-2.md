> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add notification webhooks, analytics/export, DNI swap, and ad-platform adapter placeholders to the Call Tracking module.

**Architecture:** Build on Phase 1 models/services. Webhook delivery uses a dedicated Call Tracking dispatcher + payload builder (separate from existing Call Notifications). Analytics aggregates `call_tracking_sessions` with MySQL `DATE_FORMAT`/group-by. DNI is a public rate-limited endpoint plus a small JS snippet. Ad-platform integration is stub-only for v1.

**Tech Stack:** Laravel 12, MySQL, Redis rate limiting, PHPUnit.

---

## Phase 2 Definition of Done

- Per-campaign notification settings can be stored and retrieved.
- Custom webhooks fire for enabled events when a CDR creates a session.
- Webhook delivery attempts are logged in `call_tracking_notification_logs`.
- Analytics endpoint returns KPIs and time-series grouped by day/week/month.
- CSV export endpoint streams session/campaign rollup data.
- Public DNI swap endpoint returns the correct tracking number for source/medium.
- `public/js/call-tracking-dni.js` snippet swaps numbers on landing pages.
- Google Ads / Meta conversion upload service stubs exist.
- All new backend code has passing tests; `./run-tests.sh --filter=CallTracking` passes.

**Out of scope for Phase 2:**
- Real-time live dashboard (use existing Live Calls module)
- Actual OAuth/API calls to Google/Meta
- Frontend UI (Phase 3)

---

## Conventions

- All PHP files start with `declare(strict_types=1);`.
- All new controllers extend `Controller` (not `AbstractApiCrudController`) so FormRequests can be type-hinted.
- All models scoped by `organization_id`; webhook/lookup code bypasses `OrganizationScope`.
- All service classes have unit/feature tests.
- Code style: PSR-12 + Laravel conventions; run `vendor/bin/pint` after backend changes.
- Each task ends with a commit.

---

## File Map

| File | Responsibility |
|------|----------------|
| `app/Http/Controllers/Api/CallTrackingNotificationSettingsController.php` | Show/update notification settings, send test event |
| `app/Http/Requests/CallTracking/StoreNotificationSettingsRequest.php` | Validate notification settings |
| `app/Http/Resources/CallTrackingNotificationSettingsResource.php` | Settings API resource |
| `app/Services/CallTracking/NotificationPayloadBuilder.php` | Build normalized webhook payload from session |
| `app/Services/CallTracking/CallTrackingWebhookDispatcher.php` | Send webhook, log result, handle auth |
| `app/Services/CallTracking/CallTrackingEventDispatcher.php` | Decide which events to fire and dispatch jobs |
| `app/Jobs/DispatchCallTrackingWebhookJob.php` | Queue job that calls dispatcher |
| `app/Http/Controllers/Api/CallTrackingAnalyticsController.php` | Aggregate analytics + CSV export |
| `app/Http/Requests/CallTracking/AnalyticsRequest.php` | Validate analytics filters |
| `app/Http/Resources/CallTrackingAnalyticsResource.php` | Analytics response resource |
| `app/Services/CallTracking/CallTrackingAnalyticsService.php` | Query aggregation logic |
| `app/Http/Controllers/Api/CallTrackingDniController.php` | Public DNI swap endpoint |
| `app/Http/Requests/CallTracking/DniSwapRequest.php` | Validate DNI params |
| `public/js/call-tracking-dni.js` | Browser snippet |
| `app/Services/CallTracking/Adapters/GoogleAdsConversionUploadService.php` | Stub upload adapter |
| `app/Services/CallTracking/Adapters/MetaConversionsApiService.php` | Stub upload adapter |
| `app/Models/Organization.php` | Add encrypted `call_tracking_settings` JSON (optional) |
| `routes/api.php` | Add analytics, DNI, notification routes |

---

## Task 1: Notification Settings API

**Files:**
- Create: `app/Http/Controllers/Api/CallTrackingNotificationSettingsController.php`
- Create: `app/Http/Requests/CallTracking/StoreNotificationSettingsRequest.php`
- Create: `app/Http/Resources/CallTrackingNotificationSettingsResource.php`
- Create: `app/Policies/CallTrackingNotificationSettingsPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/CallTrackingNotificationSettingsControllerTest.php`

**Steps:**
- [ ] Create policy: Owner/Admin can view/update; same organization check.
- [ ] Create resource with `webhook_url`, `auth_method`, `auth_username`, `enabled_events`, `is_active`.
- [ ] Create request rules:
  - `webhook_url`: required, url, max:2048
  - `auth_method`: required, in:none,bearer_token,basic_auth
  - `auth_secret`: nullable, required_if:auth_method,bearer_token|basic_auth, max:2048
  - `auth_username`: nullable, required_if:auth_method,basic_auth, max:255
  - `enabled_events`: required, array
  - `enabled_events.*`: in:call.received,call.answered,call.missed,call.converted,call.failed
  - `is_active`: boolean
- [ ] Create controller with `show` and `update`. On update, create if missing (`updateOrCreate` by `call_tracking_campaign_id`).
- [ ] Register policy in `AppServiceProvider`.
- [ ] Add routes under auth:sanctum:
  - `GET /api/v1/call-tracking-campaigns/{campaign}/notification-settings`
  - `PUT /api/v1/call-tracking-campaigns/{campaign}/notification-settings`
- [ ] Write feature tests: Owner can update, Agent cannot, events validation, settings created on first update.
- [ ] Run `./run-tests.sh --filter=CallTrackingNotificationSettingsControllerTest`
- [ ] Commit: `feat(call-tracking): add notification settings API`

---

## Task 2: Webhook Payload Builder

**Files:**
- Create: `app/Services/CallTracking/NotificationPayloadBuilder.php`
- Test: `tests/Unit/Services/CallTracking/NotificationPayloadBuilderTest.php`

**Steps:**
- [ ] Write failing test expecting payload structure for `call.converted` event.
- [ ] Implement `build(CallTrackingSession $session, string $eventType, string $eventId): array` returning:
```php
[
    'event' => $eventType,
    'event_id' => $eventId,
    'timestamp' => now()->toIso8601String(),
    'organization_id' => $session->organization_id,
    'campaign' => ['id' => $session->call_tracking_campaign_id, 'name' => $session->campaign_name],
    'tracking_number' => $session->called_number,
    'caller_number' => $session->caller_number,
    'source' => $session->source,
    'medium' => $session->medium,
    'duration' => $session->duration,
    'billsec' => $session->billsec,
    'is_answered' => $session->is_answered,
    'is_converted' => $session->is_converted,
    'conversion_value' => $session->conversion_value,
]
```
- [ ] Run tests.
- [ ] Commit: `feat(call-tracking): add notification payload builder`

---

## Task 3: Webhook Dispatcher + Logs

**Files:**
- Create: `app/Services/CallTracking/CallTrackingWebhookDispatcher.php`
- Modify: `app/Models/CallTrackingNotificationLog.php` (no changes expected, confirm casts)
- Test: `tests/Feature/CallTracking/WebhookDispatchTest.php`

**Steps:**
- [ ] Implement dispatcher with `dispatch(CallTrackingNotificationSettings $settings, CallTrackingSession $session, string $eventType, string $eventId): CallTrackingNotificationLog`.
- [ ] Build payload via `NotificationPayloadBuilder`.
- [ ] Send HTTP POST with timeout 30s using Laravel HTTP client.
- [ ] Auth:
  - `bearer_token`: header `Authorization: Bearer {secret}`
  - `basic_auth`: `withBasicAuth($username, $secret)`
- [ ] Log attempt to `call_tracking_notification_logs` with request payload, response status/body, response time, success flag.
- [ ] Use SSRF-safe URL validation: `filter_var($url, FILTER_VALIDATE_URL)` and reject private/IP URLs via `UrlValidator` if available; otherwise simple `parse_url` check for `http/https` scheme.
- [ ] Write feature test using `Http::fake()`; assert log row created with success=true and correct event_id.
- [ ] Run `./run-tests.sh --filter=WebhookDispatchTest`
- [ ] Commit: `feat(call-tracking): add webhook dispatcher and delivery logging`

---

## Task 4: Event Dispatcher + CDR Hook

**Files:**
- Create: `app/Services/CallTracking/CallTrackingEventDispatcher.php`
- Create: `app/Jobs/DispatchCallTrackingWebhookJob.php`
- Modify: `app/Services/CallTracking/CallTrackingSessionService.php`
- Modify: `app/Jobs/ProcessCDRJob.php`
- Test: `tests/Feature/CallTracking/WebhookEventDispatchTest.php`

**Steps:**
- [ ] Create `DispatchCallTrackingWebhookJob` implementing `ShouldQueue`. Constructor takes `(int $settingsId, int $sessionId, string $eventType, string $eventId)`. Handle loads models (bypass scope), checks active, calls dispatcher.
- [ ] Create `CallTrackingEventDispatcher` with `dispatch(CallTrackingSession $session, array $eventTypes): void`. For each event type, load settings for session's campaign; if configured, active, and event enabled, generate `event_id` (`ct_event_{uuid}`), and dispatch `DispatchCallTrackingWebhookJob` (sync in tests via `Queue::fake()` or queue=sync).
- [ ] Modify `CallTrackingSessionService::createFromCDR` to return the created session and derive event list:
  - always `call.received`
  - `call.answered` if `is_answered`
  - `call.missed` if not answered and ended normally
  - `call.converted` if `is_converted`
  - `call.failed` if disposition in `FAILED`, `BUSY`
- [ ] Modify `ProcessCDRJob::handle` to call `CallTrackingEventDispatcher::dispatch($session, $events)` after session creation.
- [ ] Write feature test: CDR creates session and queues webhook job for `call.converted`.
- [ ] Run `./run-tests.sh --filter=WebhookEventDispatchTest`
- [ ] Commit: `feat(call-tracking): dispatch webhooks from CDR events`

---

## Task 5: Analytics Service

**Files:**
- Create: `app/Services/CallTracking/CallTrackingAnalyticsService.php`
- Test: `tests/Unit/Services/CallTracking/CallTrackingAnalyticsServiceTest.php`

**Steps:**
- [ ] Define `AnalyticsFilters` value object / array shape:
  - `organization_id` (int)
  - `start_date` (Carbon)
  - `end_date` (Carbon)
  - `campaign_ids` (int[])
  - `sources` (string[])
  - `mediums` (string[])
  - `group_by` ('day'|'week'|'month')
- [ ] Implement methods:
  - `getKpis(array $filters): array` — total_calls, unique_callers, answered_calls, missed_calls, avg_duration, conversions, conversion_rate.
  - `getTimeSeries(array $filters): array` — grouped rows with date_key, calls, conversions.
  - `getTopCampaigns(array $filters, int $limit = 10): array`
  - `getTopSources(array $filters, int $limit = 10): array`
- [ ] Use MySQL `DATE_FORMAT` / SQLite `strftime` depending on driver for group_by. Keep SQL in service; add unit tests with factories creating sessions across dates.
- [ ] Run `./run-tests.sh --filter=CallTrackingAnalyticsServiceTest`
- [ ] Commit: `feat(call-tracking): add analytics aggregation service`

---

## Task 6: Analytics + Export API

**Files:**
- Create: `app/Http/Controllers/Api/CallTrackingAnalyticsController.php`
- Create: `app/Http/Requests/CallTracking/AnalyticsRequest.php`
- Create: `app/Http/Resources/CallTrackingAnalyticsResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/CallTrackingAnalyticsControllerTest.php`

**Steps:**
- [ ] Create request rules:
  - `start_date`: required, date, before_or_equal:end_date
  - `end_date`: required, date, after_or_equal:start_date
  - `campaign_ids`: nullable, array
  - `campaign_ids.*`: exists:call_tracking_campaigns,id
  - `sources`: nullable, array
  - `mediums`: nullable, array
  - `group_by`: nullable, in:day,week,month (default day)
- [ ] Create resource returning `kpis`, `time_series`, `top_campaigns`, `top_sources`.
- [ ] Create controller `index(AnalyticsRequest $request)` scoping by `$request->user()->organization_id`.
- [ ] Add CSV export method `export(AnalyticsRequest $request)` streaming `Symfony\Component\HttpFoundation\StreamedResponse`. Columns: date, campaign_name, source, medium, calls, answered, missed, conversions, avg_duration.
- [ ] Add routes:
  - `GET /api/v1/call-tracking-analytics`
  - `GET /api/v1/call-tracking-analytics/export`
- [ ] Write feature tests for analytics response shape and CSV export.
- [ ] Run `./run-tests.sh --filter=CallTrackingAnalyticsControllerTest`
- [ ] Commit: `feat(call-tracking): add analytics and export API`

---

## Task 7: DNI Swap Endpoint

**Files:**
- Create: `app/Http/Controllers/Api/CallTrackingDniController.php`
- Create: `app/Http/Requests/CallTracking/DniSwapRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/CallTracking/DniSwapTest.php`

**Steps:**
- [ ] Create request rules:
  - `organization_id`: required, exists:organizations,id
  - `utm_source`: nullable, string, max:100
  - `utm_medium`: nullable, string, max:100
  - `utm_campaign`: nullable, string, max:255
  - `default_number`: nullable, string, regex:/^\+[1-9]\d{1,14}$/
- [ ] Create controller `swap(DniSwapRequest $request)`:
  1. Find active campaign matching `utm_source` and `utm_medium` (exact, case-insensitive). If `utm_campaign` also provided, match name contains utm_campaign.
  2. If no match, find organization's default/first active tracking number.
  3. If still no match, return `default_number`.
  4. Return JSON: `{tracking_number, campaign_id, campaign_name, source, medium}`.
- [ ] Add public route with rate limiting `throttle:call-tracking-dni`:
  - `GET /api/v1/call-tracking-dni/swap`
- [ ] Register rate limiter in `AppServiceProvider::configureRateLimiting`: `RateLimiter::for('call-tracking-dni', fn (Request $request) => Limit::perMinute(60)->by($request->input('organization_id').'|'.$request->ip()));`
- [ ] Write feature tests for source/medium match, fallback, and default_number.
- [ ] Run `./run-tests.sh --filter=DniSwapTest`
- [ ] Commit: `feat(call-tracking): add DNI swap endpoint`

---

## Task 8: DNI JS Snippet

**Files:**
- Create: `public/js/call-tracking-dni.js`
- Test: Manual only (no browser tests required)

**Steps:**
- [ ] Create framework-agnostic script:
  - Read `data-organization-id`, `data-default`, `data-selector` from script tag.
  - Parse URL params for `utm_source`, `utm_medium`, `utm_campaign`.
  - Check `sessionStorage` for cached assigned number.
  - Call `/api/v1/call-tracking-dni/swap?organization_id=...&utm_source=...&utm_medium=...`.
  - On response, replace all elements matching `data-selector` with `tracking_number`.
  - Store assigned number in `sessionStorage` to avoid flicker.
  - On error, use `data-default`.
- [ ] Add CORS header handling comment; endpoint already supports JSON.
- [ ] Commit: `feat(call-tracking): add DNI website snippet`

---

## Task 9: Ad-Platform Adapter Stubs

**Files:**
- Create: `app/Services/CallTracking/Adapters/GoogleAdsConversionUploadService.php`
- Create: `app/Services/CallTracking/Adapters/MetaConversionsApiService.php`
- Test: `tests/Unit/Services/CallTracking/Adapters/GoogleAdsConversionUploadServiceTest.php`

**Steps:**
- [ ] Create GoogleAds stub with `uploadCallConversion(CallTrackingSession $session, array $config): array` that:
  - Validates `$config` has `developer_token`, `customer_id`, `conversion_action_resource_name`.
  - Returns `['status' => 'stub', 'message' => 'Google Ads upload not implemented in v1']`.
- [ ] Create Meta stub with `sendOfflineEvent(CallTrackingSession $session, array $config): array` that:
  - Validates `$config` has `pixel_id`, `access_token`.
  - Returns `['status' => 'stub', 'message' => 'Meta Conversions API not implemented in v1']`.
- [ ] Write unit test for GoogleAds stub validation and return shape.
- [ ] Run tests.
- [ ] Commit: `feat(call-tracking): add ad-platform conversion upload stubs`

---

## Task 10: Sessions List API

**Files:**
- Create: `app/Http/Controllers/Api/CallTrackingSessionController.php`
- Create: `app/Http/Requests/CallTracking/SessionIndexRequest.php`
- Create: `app/Http/Resources/CallTrackingSessionResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/CallTrackingSessionControllerTest.php`

**Steps:**
- [ ] Create resource returning all session fields plus campaign name and DID phone number when loaded.
- [ ] Create request with filters: `campaign_ids`, `sources`, `mediums`, `start_date`, `end_date`, `is_converted`, `search` (caller/called number).
- [ ] Create controller `index` scoping by organization, applying filters, sorting by `started_at desc`, paginating.
- [ ] Add route:
  - `GET /api/v1/call-tracking-sessions`
- [ ] Write feature tests for filtering and tenant isolation.
- [ ] Run `./run-tests.sh --filter=CallTrackingSessionControllerTest`
- [ ] Commit: `feat(call-tracking): add sessions list API`

---

## Task 11: Lint + Full Phase 2 Test Run

**Steps:**
- [ ] Run `vendor/bin/pint` on all new PHP files.
- [ ] Run `./run-tests.sh --filter=CallTracking` and fix failures.
- [ ] Run full `./run-tests.sh` and confirm no new failures introduced by Call Tracking.
- [ ] Update `.my_agent/memory/call-tracking.md` with Phase 2 completed files, routes, tests.
- [ ] Commit: `style(call-tracking): lint and fix Phase 2 code`

---

## Phase 2 Success Criteria

- `./run-tests.sh --filter=CallTracking` passes.
- Notification settings CRUD works with RBAC.
- Webhooks fire and log delivery for enabled events.
- Analytics endpoint returns KPIs + time series + top campaigns/sources.
- CSV export streams rows.
- DNI swap endpoint works publicly and rate-limited.
- Ad-platform stubs compile and pass unit tests.
- Sessions list API is filterable and scoped.

**Upon completion:** proceed to Phase 3 plan (Frontend).