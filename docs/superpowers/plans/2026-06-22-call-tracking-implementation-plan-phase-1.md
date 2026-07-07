# Call Tracking Implementation Plan — Phase 1: Backend Foundation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Call Tracking backend foundation so a DID can be routed to a campaign, the call can be forwarded to an external number or an OPBX destination, and a CDR can create a tracking session with conversion evaluation.

**Phase 1 Definition of Done:**
- Database tables exist and migrations roll back cleanly.
- Enums, models, factories are in place and tested.
- Voice routing strategy handles `call_tracking` DIDs and produces valid CXML.
- CDR processing creates `CallTrackingSession` records with correct conversion flags.
- Campaign and Tracking Number REST APIs are functional with RBAC.
- All new backend code has passing tests; `./run-tests.sh --filter=CallTracking` passes.
- No frontend work in this phase.

**Out of scope for Phase 1:**
- Custom webhook notifications
- Analytics/export endpoints
- DNI swap endpoint + JS snippet
- Ad-platform adapters
- Frontend

---

## Conventions Used in This Plan

- All PHP files must start with `declare(strict_types=1);`.
- All models must use `#[ScopedBy([OrganizationScope::class])]`.
- All controller actions must enforce RBAC via policy gates.
- All service classes must have a corresponding unit or feature test.
- Code style: PSR-12 + Laravel conventions; run `vendor/bin/pint` after backend changes.
- Each task ends with a commit. Do not batch unrelated changes into one commit.

---

## Task 1: Database Migrations

**Files:**
- Create: `database/migrations/2026_06_22_000001_create_call_tracking_campaigns_table.php`
- Create: `database/migrations/2026_06_22_000002_create_call_tracking_numbers_table.php`
- Create: `database/migrations/2026_06_22_000003_create_call_tracking_sessions_table.php`
- Create: `database/migrations/2026_06_22_000004_create_call_tracking_notification_settings_table.php`
- Create: `database/migrations/2026_06_22_000005_create_call_tracking_notification_logs_table.php`
- Create: `database/migrations/2026_06_22_000006_add_call_tracking_to_did_numbers_routing_type.php`
- Test: `tests/Feature/CallTracking/MigrationsTest.php`

**Steps:**
- [ ] Create `call_tracking_campaigns` migration: id, organization_id FK, name, source, medium, description, status enum, destination_type enum, destination_config json, conversion_rule json, timestamps, indexes.
- [ ] Create `call_tracking_numbers` migration: id, organization_id FK, call_tracking_campaign_id FK, did_number_id FK unique, friendly_name, status enum, timestamps, indexes.
- [ ] Create `call_tracking_sessions` migration: id, organization_id FK, call_tracking_campaign_id FK, call_tracking_number_id FK, did_number_id FK, call_id indexed, session_id nullable indexed, caller_number, caller_country, called_number, source, medium, campaign_name, disposition, duration, billsec, is_answered, is_converted, conversion_value, started_at, answered_at, ended_at, raw_cdr json, timestamps, indexes.
- [ ] Create `call_tracking_notification_settings` migration: id, organization_id FK, call_tracking_campaign_id FK unique, webhook_url, auth_method enum, auth_secret, auth_username, enabled_events json, is_active, timestamps.
- [ ] Create `call_tracking_notification_logs` migration: id, organization_id FK, call_tracking_campaign_id FK, call_id, event_id indexed, event_type, webhook_url, request_payload json, request_headers json, response_body, response_headers json, response_status_code, response_time_ms, is_success, attempt_number, error_message, timestamps, indexes.
- [ ] Add `call_tracking` to `did_numbers.routing_type` enum via `DB::statement` migration.
- [ ] Run migrations in Docker: `docker compose exec app php artisan migrate`
- [ ] Write `MigrationsTest` asserting tables exist and routing type includes `call_tracking`.
- [ ] Run `./run-tests.sh --filter=Tests\Feature\CallTracking\MigrationsTest`
- [ ] Commit: `feat(call-tracking): add database migrations`

**Reference code:** See archived monolithic plan `docs/superpowers/plans/2026-06-22-call-tracking-implementation-plan-monolithic.md` Task 1 for full migration SQL examples.

---

## Task 2: Enums

**Files:**
- Create: `app/Enums/CallTrackingCampaignStatus.php`
- Create: `app/Enums/CallTrackingDestinationType.php`
- Create: `app/Enums/CallTrackingEventType.php`
- Test: `tests/Unit/Enums/CallTrackingEnumsTest.php`

**Steps:**
- [ ] Create `CallTrackingCampaignStatus` backed string enum: `active`, `inactive`.
- [ ] Create `CallTrackingDestinationType` backed string enum: `forward`, `extension`, `ring_group`, `business_hours`, `conference_room`, `ivr_menu`, `ai_assistant`, `ai_load_balancer`.
  - Add `toExtensionType(): ?ExtensionType` mapping each case to the existing `ExtensionType` enum.
  - Add `options(): array` helper for dropdowns.
- [ ] Create `CallTrackingEventType` backed string enum: `call.received`, `call.answered`, `call.missed`, `call.converted`, `call.failed`.
- [ ] Write unit tests verifying enum values and mappings.
- [ ] Run tests.
- [ ] Commit: `feat(call-tracking): add enums`

---

## Task 3: Models

**Files:**
- Create: `app/Models/CallTrackingCampaign.php`
- Create: `app/Models/CallTrackingNumber.php`
- Create: `app/Models/CallTrackingSession.php`
- Create: `app/Models/CallTrackingNotificationSettings.php`
- Create: `app/Models/CallTrackingNotificationLog.php`
- Test: `tests/Unit/Models/CallTrackingCampaignTest.php`

**Steps:**
- [ ] Create `CallTrackingCampaign` model with fillable, casts to enums, relations, scopes, `isActive()`, `getForwardTo()`, `getDestinationId()`.
- [ ] Create `CallTrackingNumber` model with casts, relations to organization, campaign, did.
- [ ] Create `CallTrackingSession` model with casts for booleans, timestamps, decimal, raw_cdr json, relations.
- [ ] Create `CallTrackingNotificationSettings` model with `$table` override, default attributes, `isEventEnabled()`, `isConfigured()`.
- [ ] Create `CallTrackingNotificationLog` model with `$table` override and casts.
- [ ] Write model unit test covering casting and scopes.
- [ ] Run tests.
- [ ] Commit: `feat(call-tracking): add models`

---

## Task 4: Factories

**Files:**
- Create: `database/factories/CallTrackingCampaignFactory.php`
- Create: `database/factories/CallTrackingNumberFactory.php`
- Create: `database/factories/CallTrackingSessionFactory.php`

**Steps:**
- [ ] Create campaign factory with default forward destination and conversion rule; add states `forwardTo()`, `toExtension()`, `inactive()`.
- [ ] Create tracking number factory linking campaign + DID.
- [ ] Create session factory with realistic CDR-style data; add `converted()` state.
- [ ] Run `./run-tests.sh --filter=Tests\Unit\Models\CallTrackingCampaignTest` to verify factories work.
- [ ] Commit: `feat(call-tracking): add factories`

---

## Task 5: Conversion Rule Evaluator

**Files:**
- Create: `app/Services/CallTracking/ConversionRuleEvaluator.php`
- Test: `tests/Unit/Services/CallTracking/ConversionRuleEvaluatorTest.php`

**Steps:**
- [ ] Write TDD tests: convert when answered+duration exceeds threshold; do not convert when duration below threshold or not answered; default rule requires answered disposition.
- [ ] Implement `ConversionRuleEvaluator::evaluate(array $rule, string $disposition, int $billsec): bool`.
- [ ] Run tests.
- [ ] Commit: `feat(call-tracking): add conversion rule evaluator`

---

## Task 6: Destination Resolver

**Files:**
- Create: `app/Services/CallTracking/CallTrackingDestinationResolver.php`
- Test: `tests/Unit/Services/CallTracking/CallTrackingDestinationResolverTest.php`

**Steps:**
- [ ] Write TDD tests covering forward destination and extension destination.
- [ ] Implement resolver that takes a `CallTrackingCampaign` and returns `['type' => ..., ...]`.
  - For non-forward types, use `withoutGlobalScope(OrganizationScope::class)` to load the related OPBX model scoped by `organization_id`.
  - Support `extension`, `ring_group`, `business_hours`, `conference_room`, `ivr_menu`, `ai_assistant`, `ai_load_balancer`.
- [ ] Run tests.
- [ ] Commit: `feat(call-tracking): add destination resolver`

---

## Task 7: Voice Routing Strategy

**Files:**
- Create: `app/Services/VoiceRouting/Strategies/CallTrackingRoutingStrategy.php`
- Modify: `app/Services/VoiceRouting/InboundRoutingService.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/CallTracking/VoiceRoutingTest.php`

**Steps:**
- [ ] Write feature test: inbound call to a DID with `routing_type=call_tracking` returns CXML dialing the campaign's forward number.
- [ ] Create `CallTrackingRoutingStrategy` implementing `RoutingStrategy`.
  - `canHandle()` returns `true` because selection is driven by DID routing_type.
  - `route()` validates DID routing type, loads active campaign, handles `forward` directly via `CxmlBuilder::simpleDial()`.
  - For OPBX destinations, resolves the target and delegates to the existing strategy (User, RingGroup, Conference, IVR, AI).
- [ ] Update `InboundRoutingService::resolveDidDestination()` to populate `call_tracking_campaign_id` for `call_tracking` routing type.
- [ ] Update `InboundRoutingService::routeDidCall()` to short-circuit and invoke `CallTrackingRoutingStrategy` before the generic executor.
- [ ] Register strategy in `AppServiceProvider` under `voice_routing.strategies` tag.
- [ ] Run tests.
- [ ] Commit: `feat(call-tracking): add voice routing strategy`

---

## Task 8: CDR Session Creation

**Files:**
- Create: `app/Services/CallTracking/CallTrackingSessionService.php`
- Modify: `app/Jobs/ProcessCDRJob.php`
- Test: `tests/Feature/CallTracking/SessionCreationTest.php`

**Steps:**
- [ ] Write feature test: process a CDR for a call_tracking DID and assert a `CallTrackingSession` row is created with `is_converted=true` when rule matches.
- [ ] Implement `CallTrackingSessionService::createFromCDR(CallDetailRecord $cdr, int $organizationId): ?CallTrackingSession`.
  - Look up `CallTrackingNumber` by called number and organization.
  - Evaluate conversion rule.
  - Build timestamps from CDR/session payload.
  - Return null if no tracking number or campaign inactive.
- [ ] Hook into `ProcessCDRJob` after CDR creation; log session creation with `call_id` context.
- [ ] Run tests.
- [ ] Commit: `feat(call-tracking): create sessions from CDR`

---

## Task 9: Campaign Controller + API

**Files:**
- Create: `app/Http/Controllers/Api/CallTrackingCampaignController.php`
- Create: `app/Http/Requests/CallTracking/StoreCampaignRequest.php`
- Create: `app/Http/Requests/CallTracking/UpdateCampaignRequest.php`
- Create: `app/Http/Resources/CallTrackingCampaignResource.php`
- Create: `app/Policies/CallTrackingCampaignPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Api/CallTrackingCampaignControllerTest.php`

**Steps:**
- [ ] Create `CallTrackingCampaignPolicy` with Owner/PBXAdmin create/update/delete; all roles can view.
- [ ] Create resource with `tracking_numbers_count` when counted.
- [ ] Create `StoreCampaignRequest` with validation for all destination types and conversion rules.
- [ ] Create `UpdateCampaignRequest` (same fields, `sometimes` rules).
- [ ] Create controller with index, store, show, update, destroy.
  - Index scoped by organization with filters for status, source, search, sort.
- [ ] Register policy in `AppServiceProvider`.
- [ ] Write feature tests for RBAC and tenant scoping.
- [ ] Run tests.
- [ ] Commit: `feat(call-tracking): add campaign CRUD API`

---

## Task 10: Tracking Number Controller + API

**Files:**
- Create: `app/Http/Controllers/Api/CallTrackingNumberController.php`
- Create: `app/Http/Requests/CallTracking/StoreNumberRequest.php`
- Create: `app/Http/Resources/CallTrackingNumberResource.php`
- Create: `app/Policies/CallTrackingNumberPolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/CallTrackingNumberControllerTest.php`

**Steps:**
- [ ] Create `CallTrackingNumberPolicy` mirroring campaign policy.
- [ ] Create `StoreNumberRequest` validating `did_number_id` belongs to active org DID and is unique.
- [ ] Create resource including `phone_number` when DID is loaded.
- [ ] Create controller nested under campaigns: index, store, update status/friendly_name, destroy.
  - On create, auto-set `organization_id` and `call_tracking_campaign_id`.
  - Update the DID's `routing_type` to `call_tracking` and `routing_config` to `{call_tracking_campaign_id}` when a number is assigned.
- [ ] Register policy and routes.
- [ ] Write feature tests covering CRUD, uniqueness, tenant isolation.
- [ ] Run tests.
- [ ] Commit: `feat(call-tracking): add tracking number API`

---

## Task 11: Pint + Full Phase 1 Test Run

**Steps:**
- [ ] Run `vendor/bin/pint` on all new PHP files.
- [ ] Run `./run-tests.sh --filter=CallTracking` and fix any failures.
- [ ] Run full `./run-tests.sh` to ensure no regressions.
- [ ] Commit: `style(call-tracking): lint and fix Phase 1 code`

---

## Phase 1 Success Criteria

- `./run-tests.sh --filter=CallTracking` passes.
- Migrations can be rolled back via `php artisan migrate:rollback`.
- Voice routing test demonstrates CXML forward dialing.
- Session creation test demonstrates CDR-to-conversion flow.
- All new API endpoints respond correctly to RBAC scenarios.

**Upon completion:** proceed to Phase 2 plan (Notifications + Analytics + DNI).
