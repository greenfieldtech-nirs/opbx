# Comprehensive OpBX Documentation Refresh — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Update `docs/opbx-openapi/` and `docs/opbx-userguide/` to reflect the current OpBX codebase, generate the OpenAPI spec from source routes, and add architecture/worker guides.

**Architecture:** The work is split into independent documentation tracks: (1) OpenAPI generation from `route:list` and code, (2) Docusaurus user-guide refresh, (3) new architecture and worker-service guides. Each track can be worked in parallel. Reviews focus on accuracy against source code and consistency across docs.

**Tech Stack:** Markdown, MDX, OpenAPI 3.1.0 YAML, PHP/Laravel route introspection, Docusaurus.

---

## Task 0: Foundation — Extract Source-of-Truth Artifacts

**Files:**
- Create: `docs/superpowers/work/route-list.json`
- Create: `docs/superpowers/work/controller-index.md`

- [ ] **Step 1: Generate route list JSON**

Run inside Docker:

```bash
docker compose exec app php artisan route:list --json > docs/superpowers/work/route-list.json
```

Expected: a JSON file with every named route, URI, method, middleware, and controller action.

- [ ] **Step 2: Build a controller index**

Run:

```bash
git ls-files app/Http/Controllers/ > docs/superpowers/work/controllers.txt
```

Expected: a plain-text list of all controllers to hand to task owners.

- [ ] **Step 3: Commit foundation artifacts**

```bash
git add docs/superpowers/work/
git commit -m "docs(foundation): capture route list and controller index for documentation refresh"
```

---

## Task 1: OpenAPI — Regenerate Root Spec from Routes

**Files:**
- Modify: `docs/opbx-openapi/openapi.yaml`
- Modify: `docs/opbx-openapi/README.md`
- Create: `docs/superpowers/work/openapi-generator.php` (optional helper)

- [ ] **Step 1: Update `openapi.yaml` info block**

Set:

```yaml
info:
  title: OPBX REST API
  version: 1.0.0
  description: |
    Auto-generated from routes/api.php, routes/webhooks.php, and routes/platform.php.
    Last generated: 2026-06-21.
```

- [ ] **Step 2: List every tag and tag description**

Add tags matching route groups:

```yaml
tags:
  - name: Authentication
  - name: Profile
  - name: Users
  - name: Extensions
  - name: Phone Numbers
  - name: Ring Groups
  - name: Business Hours
  - name: IVR Menus
  - name: Conference Rooms
  - name: AI Assistants
  - name: AI Load Balancers
  - name: Call Detail Records
  - name: Recordings
  - name: Inbound Blacklist
  - name: Outbound Whitelist
  - name: Settings
  - name: Session Updates
  - name: Call Notifications
  - name: Auto Dialer Campaigns
  - name: Distribution Lists
  - name: Dialer Worker
  - name: Webhooks
  - name: Voice Routing
  - name: Platform Manager
  - name: Health
```

- [ ] **Step 3: Verify paths block references current set**

Ensure `paths:` uses `$ref` to every file listed in `docs/opbx-openapi/paths/` and matches the route list JSON.

- [ ] **Step 4: Document generation command in README.md**

Create/append:

```markdown
# Regenerating the OpenAPI spec

1. Dump current routes:
   ```bash
   docker compose exec app php artisan route:list --json > docs/superpowers/work/route-list.json
   ```

2. Compare against existing path files and add/remove path files as needed.

3. Validate the bundled spec:
   ```bash
   npx @redocly/cli lint docs/opbx-openapi/openapi.yaml
   ```
```

- [ ] **Step 5: Commit**

```bash
git add docs/opbx-openapi/openapi.yaml docs/opbx-openapi/README.md
git commit -m "docs(openapi): regenerate root spec and tag list from current routes"
```

---

## Task 2: OpenAPI — Audit, Add, and Update Path Files

**Files:**
- All `docs/opbx-openapi/paths/**/*.yaml`
- Some `docs/opbx-openapi/components/schemas/*.yaml`
- Some `docs/opbx-openapi/components/responses/*.yaml`

- [ ] **Step 1: Diff route list against existing path files**

Produce a report (markdown or stdout) listing:
- Routes missing from OpenAPI
- OpenAPI paths for routes that no longer exist
- Routes whose URI or method changed

- [ ] **Step 2: Create missing path files**

For every route in `routes/api.php`, `routes/webhooks.php`, and `routes/platform.php` not already in `docs/opbx-openapi/paths/`, create a YAML file using this template:

```yaml
summary: Short action description
operationId: routeNameFromLaravel
tags:
  - TagName
security:
  - bearerAuth: []
parameters:
  - $ref: ../../components/parameters/path/Id.yaml
requestBody:
  required: true
  content:
    application/json:
      schema:
        $ref: ../../components/schemas/SomeRequest.yaml
responses:
  '200':
    description: OK
    content:
      application/json:
        schema:
          $ref: ../../components/schemas/SuccessMessage.yaml
  '401':
    $ref: ../../components/responses/Unauthorized.yaml
  '422':
    $ref: ../../components/responses/ValidationError.yaml
```

- [ ] **Step 3: Update existing path files**

Read the actual controller method for each existing path and update:
- operationId to match route name
- URI parameters
- request body schema
- response schemas
- middleware notes (rate limiting, auth mode)

- [ ] **Step 4: Update shared schemas**

Create or refresh request/response schemas used by multiple paths:

- `PaginationMeta.yaml`
- `SuccessMessage.yaml`
- `ValidationError.yaml`
- `Error.yaml`
- `User.yaml`
- `Extension.yaml`
- `RingGroup.yaml`
- `BusinessHoursSchedule.yaml`
- `PhoneNumber.yaml`
- `IvrMenu.yaml`
- `ConferenceRoom.yaml`
- `AiAssistant.yaml`
- `AiAssistantLoadBalancer.yaml`
- `CallDetailRecord.yaml`
- `CallLog.yaml`
- `SessionUpdate.yaml`
- `Recording.yaml`
- `InboundBlacklist.yaml`
- `OutboundWhitelist.yaml`
- `CallNotificationsSettings.yaml`
- `CloudonixSettings.yaml`
- `AutoDialerCampaign.yaml`
- `DistributionList.yaml`
- `Organization.yaml` (platform)
- Webhook payloads: `CallInitiatedPayload.yaml`, `CallStatusPayload.yaml`, `SessionUpdatePayload.yaml`, `CdrPayload.yaml`
- CXML: `CxmlResponse.yaml`

- [ ] **Step 5: Validate with Redocly**

Run:

```bash
npx @redocly/cli lint docs/opbx-openapi/openapi.yaml
```

Fix all errors before marking task complete.

- [ ] **Step 6: Commit**

```bash
git add docs/opbx-openapi/
git commit -m "docs(openapi): update all path and schema files from current source"
```

---

## Task 3: Docusaurus — Refresh Core Pages

**Files:**
- Modify: `docs/opbx-userguide/index.mdx`
- Modify: `docs/opbx-userguide/installation/index.mdx`
- Modify: `docs/opbx-userguide/installation/first-login.mdx`
- Modify: `docs/opbx-userguide/installation/cloudonix-setup.mdx`
- Modify: `docs/opbx-userguide/installation/concepts.mdx`

- [ ] **Step 1: Update `index.mdx` to match current feature set**

Ensure the feature list includes:
- Multi-tenant organizations
- Auth (cookie + Bearer)
- Users / Extensions / DID routing / Ring Groups / Business Hours
- IVR Menus / Conference Rooms
- AI Assistants / AI Load Balancers
- Auto Dialer / Distribution Lists
- Call Logs / CDR / Live Calls / Recordings
- Inbound Blacklist / Outbound Whitelist
- Call Notifications
- Platform management

Update architecture diagram to include dialer-worker and amd-worker.

- [ ] **Step 2: Update installation guide**

Replace stale service list with current `docker-compose.yml.example` services:
- nginx
- app (php-fpm)
- queue-worker
- scheduler
- mysql
- redis
- soketi
- minio
- ngrok
- frontend (vite dev)
- dialer-worker
- amd-worker (when enabled)

Mention `docker-compose.yml` is gitignored; copy from `.env.example` and `docker-compose.yml.example`.

- [ ] **Step 3: Update first-login and Cloudonix setup**

Match current UI flow:
1. Default admin: `admin@example.com` / `password`
2. Create organization (or use platform manager flag)
3. Settings → Cloudonix: domain UUID, domain API key, requests API key, webhook base URL
4. Save triggers domain profile + voice app + default app setup
5. Configure ngrok for local webhooks

- [ ] **Step 4: Update concepts page**

Ensure definitions match code:
- Organization / Tenant
- User roles: Owner, PBX Admin, PBX User, Reporter
- Extension (Cloudonix subscriber)
- DID / Phone Number
- Ring Group strategies
- Business Hours schedules + exceptions
- IVR menus, destinations
- Auto Dialer campaigns, distribution lists

- [ ] **Step 5: Commit**

```bash
git add docs/opbx-userguide/index.mdx docs/opbx-userguide/installation/
git commit -m "docs(userguide): refresh core pages to current app state"
```

---

## Task 4: Docusaurus — Refresh Module Guides

**Files:**
- Modify: `docs/opbx-userguide/modules/index.mdx`
- Modify/Create: every `docs/opbx-userguide/modules/*.mdx`

- [ ] **Step 1: Update `modules/index.mdx`**

List every module and link to its page. Add missing modules.

- [ ] **Step 2: Refresh existing module pages**

For each existing page, read the corresponding controller and frontend page, then update:
- Purpose
- Prerequisites / permissions
- UI workflow
- API endpoints used (link to OpenAPI)
- Data model fields

Existing pages to refresh:
- `user-management.mdx`
- `extensions.mdx`
- `phone-numbers.mdx`
- `ring-groups.mdx`
- `business-hours.mdx`
- `ivr-menus.mdx`
- `conference-rooms.mdx`
- `inbound-blacklist.mdx`
- `outbound-whitelist.mdx`
- `recordings.mdx`
- `reporting.mdx`

- [ ] **Step 3: Create missing module pages**

Create new MDX files for modules not yet documented:
- `live-calls.mdx` (SessionUpdateController, LiveCalls.tsx)
- `call-notifications.mdx` (CallNotificationsSettingsController, CallNotificationsSettings.tsx)
- `call-logs.mdx` (CallDetailRecordController, CallLogs.tsx)
- `settings.mdx` (SettingsController, Settings.tsx)
- `auto-dialer-campaigns.mdx` (AutoDialerCampaignController, AutoDialerCampaigns.tsx)
- `distribution-lists.mdx` (DistributionListController, DistributionLists.tsx)
- `ai-assistants.mdx` (AiAssistantController, AiAssistants.tsx)
- `ai-load-balancers.mdx` (AiAssistantLoadBalancerController, AiAssistantLoadBalancers.tsx)

- [ ] **Step 4: Commit**

```bash
git add docs/opbx-userguide/modules/
git commit -m "docs(userguide): refresh and add module guides"
```

---

## Task 5: Docusaurus — Worker-Service Guides

**Files:**
- Create: `docs/opbx-userguide/workers/index.mdx`
- Create: `docs/opbx-userguide/workers/dialer-worker.mdx`
- Create: `docs/opbx-userguide/workers/amd-worker.mdx`

- [ ] **Step 1: Create workers index page**

Overview of why worker services exist and when they run.

- [ ] **Step 2: Document dialer-worker**

Include:
- Purpose: executes outbound auto-dialer campaigns
- Communication: polls Laravel API, initiates calls via Cloudonix
- Build: `cd dialer-worker && make build`
- Run: `make run` or via Docker service
- Env vars: `DIALER_WORKER_API_TOKEN`, API base URL, Redis
- Lifecycle: active / paused / archived campaigns

- [ ] **Step 3: Document amd-worker**

Include:
- Purpose: real-time answering-machine detection on bi-directional audio streams
- Technology: Java Vert.x 5 + ONNX
- Build: `cd amd-worker && mvn package -DskipTests -B`
- Run: via Docker service or `java -jar target/amd-worker-*-shaded.jar`
- Env vars: `AMD_WORKER_API_TOKEN`, Cloudonix stream connection
- Triggered by auto-dialer campaigns

- [ ] **Step 4: Commit**

```bash
git add docs/opbx-userguide/workers/
git commit -m "docs(userguide): add worker-service overview and build/run guides"
```

---

## Task 6: Docusaurus — Architecture and Operational Guides

**Files:**
- Create: `docs/opbx-userguide/architecture/index.mdx`
- Create: `docs/opbx-userguide/architecture/multi-tenancy.mdx`
- Create: `docs/opbx-userguide/architecture/webhooks.mdx`
- Create: `docs/opbx-userguide/architecture/call-flow.mdx`
- Create: `docs/opbx-userguide/architecture/security.mdx`
- Create: `docs/opbx-userguide/architecture/real-time.mdx`
- Refresh: `docs/WEBHOOK-AUTHENTICATION.md`
- Refresh: `docs/DATABASE-PERSISTENCE.md`

- [ ] **Step 1: Architecture index**

High-level control plane vs execution plane, service diagram, data stores.

- [ ] **Step 2: Multi-tenancy guide**

`OrganizationScope`, tenant scoping, bypass pattern, platform manager role.

- [ ] **Step 3: Webhooks guide**

Authentication modes:
- Voice routing: `voice.webhook.auth` (Bearer token from `domain_requests_api_key`)
- Status/CDR: `webhook.signature` (HMAC-SHA256 or domain UUID)
- Idempotency keys in Redis

Webhook types and expected responses:
- call-initiated, call-status, cdr, session-update
- CXML responses for voice route/ivr-input/ring-group-callback

- [ ] **Step 4: Call flow guide**

Inbound call lifecycle:
1. Cloudonix POSTs to `/api/voice/route`
2. VoiceRoutingController resolves DID → destination
3. CxmlBuilder returns `<Connect>`, `<Dial>`, `<Play>`, etc.
4. session-update and CDR webhooks update state

- [ ] **Step 5: Security guide**

Sanctum auth, rate limiting, encrypted API keys, webhook secrets, MinIO signed URLs, CSRF for SPA.

- [ ] **Step 6: Real-time guide**

Soketi/Laravel Echo, presence channels, live call updates.

- [ ] **Step 7: Refresh standalone markdown docs**

Update `docs/WEBHOOK-AUTHENTICATION.md` and `docs/DATABASE-PERSISTENCE.md` with current middleware names, env vars, and schema notes.

- [ ] **Step 8: Commit**

```bash
git add docs/opbx-userguide/architecture/ docs/WEBHOOK-AUTHENTICATION.md docs/DATABASE-PERSISTENCE.md
git commit -m "docs(userguide): add architecture guides and refresh standalone security/db docs"
```

---

## Task 7: Docusaurus — Data Models and Configuration Reference

**Files:**
- Modify: `docs/opbx-userguide/data-models/index.mdx`
- Modify/Create: `docs/opbx-userguide/data-models/models/*.mdx`
- Modify/Create: `docs/opbx-userguide/data-models/enums/*.mdx`
- Modify/Create: `docs/opbx-userguide/data-models/configuration/*.mdx`

- [ ] **Step 1: Update data-models index**

List every model group.

- [ ] **Step 2: Refresh model pages**

Ensure tables match current migration columns for:
- `users`
- `organizations`
- `extensions`
- `did_numbers`
- `ring_groups`
- `business_hours_schedules`
- `ivr_menus`
- `conference_rooms`
- `call_logs`
- `call_detail_records`
- `recordings`
- `ai_assistants`
- `ai_assistant_load_balancers`
- `auto_dialer_campaigns`
- `distribution_lists`
- `inbound_blacklists`
- `outbound_whitelists`

- [ ] **Step 3: Add missing models**

Create pages for:
- `session_updates`
- `call_notifications_settings`
- `cloudonix_settings`
- `email_logs`
- `platform_audit_logs`

- [ ] **Step 4: Enums and configuration**

Document key enums (roles, call statuses, routing strategies, campaign statuses) and environment variables.

- [ ] **Step 5: Commit**

```bash
git add docs/opbx-userguide/data-models/
git commit -m "docs(userguide): refresh data model and configuration reference"
```

---

## Task 8: Integration, Link Check, and Final Validation

**Files:**
- All `docs/opbx-openapi/**/*.yaml`
- All `docs/opbx-userguide/**/*.mdx`
- Create: `docs/superpowers/work/docs-review-checklist.md`

- [ ] **Step 1: Run OpenAPI validation**

```bash
npx @redocly/cli lint docs/opbx-openapi/openapi.yaml
```

Expected: zero errors.

- [ ] **Step 2: Run Docusaurus build**

```bash
cd docs/opbx-userguide
npm install
npm run build
```

Expected: build succeeds with no broken internal links.

- [ ] **Step 3: Cross-check route coverage**

Run a script or manual diff to confirm every route in `route-list.json` has a corresponding OpenAPI path entry.

- [ ] **Step 4: Final review checklist**

Create `docs/superpowers/work/docs-review-checklist.md` with these items checked:
- [ ] OpenAPI bundles without errors
- [ ] Docusaurus builds without broken links
- [ ] Every Laravel route is documented
- [ ] Worker-service guides exist
- [ ] Architecture guides exist
- [ ] Security and DB standalone docs refreshed

- [ ] **Step 5: Final commit**

```bash
git add docs/
git commit -m "docs: comprehensive documentation refresh to current codebase"
```

---

## Self-Review Checklist

- [ ] Spec coverage: every route, module, worker, and architecture topic has a task.
- [ ] Placeholder scan: no TBD/TODO/"implement later" in plan steps.
- [ ] Type consistency: route names and schema names match code conventions.
- [ ] Parallel safety: OpenAPI, user-guide, and architecture tracks are independent.

## Execution Handoff

Use **subagent-driven-development**:
- Task 0 can be done once by a general agent.
- Tasks 1 and 2 can be assigned to an `api-designer` or `php-pro` subagent.
- Tasks 3–7 can be assigned to a `technical-writer` or `frontend-developer` subagent.
- Task 8 should be done by a `project-regression-tester` or the coordinating agent.

Each subagent should read the spec at `docs/superpowers/specs/2026-06-21-comprehensive-documentation-refresh.md` and the relevant memory files in `.my_agent/memory/` before starting.
