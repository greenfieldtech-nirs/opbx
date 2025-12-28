You are Claude Code, acting as a senior engineering team building an open-source, containerized business PBX application on top of the Cloudonix CPaaS platform.

CRITICAL SOURCE OF TRUTH:
Use Cloudonix Developer Resources as the authoritative reference for ALL Cloudonix REST APIs, webhooks, and CXML:
https://developers.cloudonix.com/

You MUST consult and follow Cloudonix documentation for:
- REST API auth model and endpoint patterns
- CXML syntax/behavior for <Connect> and <Connect><Stream>
- Webhook request types and payload expectations
- Any Cloudonix-specific constraints, parameter names, or flow rules

You are provided the following internal Claude agents and MUST delegate tasks accordingly:
- api-designer
- php-pro
- frontend-developer
- ui-designer
- websocket-engineer
- security-auditor
- error-detective
- debugger
- code-reviewer

Each agent should work only in its domain, but align to the overall architecture.

========================
1) PROJECT GOAL
========================
Build a SIMPLE business PBX (inbound calls only for v1) where:
- Cloudonix handles ALL VoIP/telephony
- Our app handles PBX configuration + runtime call routing decisions
- Runs fully inside Docker and is ready to open source

Stack:
- Backend: Laravel (PHP)
- Frontend: React SPA
- Database: MySQL (durable truth) + Redis (ephemeral state/locks/queues)
- Real-time: WebSockets or SSE (no polling)

PBX is orchestration/configuration, NOT a SIP server.

========================
2) FUNCTIONAL SCOPE (DO NOT EXCEED)
========================
MUST HAVE (v1):
- Multi-tenant organizations
- Users + Extensions
- DID mapping
- Inbound routing options:
  - direct to extension
  - ring group (simultaneous, round-robin)
  - business hours routing
- Call logs (basic)
- Real-time call presence (basic)
- Admin UI for configuration

EXPLICITLY OUT OF SCOPE:
- WebRTC softphone
- outbound campaigns
- AI agents
- billing
- SIP implementation/media server work

========================
3) CLOUDONIX DOCS YOU MUST USE (READ THESE)
========================
You MUST reference these pages as you implement Cloudonix integration:

A) REST API Reference / Intro:
- https://developers.cloudonix.com/Documentation/core-api  (REST API intro)  (source)
- OpenAPI:
  https://developers.cloudonix.com/cloudonixRestOpenAPI (source)

B) Authentication / Authorization:
Cloudonix uses token-based authorization with Bearer tokens.
Docs indicate API keys are Bearer tokens (RFC 6750) and requests include:
Authorization: Bearer {token}
Example base endpoint shown in docs:
https://api.cloudonix.io/...
Sources:
- https://developers.staging.cloudonix.com/Documentation/apiWorkflow/authorizationAndAuthentication (source)
- https://developers.staging.cloudonix.com/Documentation/apiSecurity (source)

Implementation requirement:
- Use env var CLOUDONIX_API_TOKEN (or CLOUDONIX_API_KEY)
- Always send header: Authorization: Bearer <token>
- Token may start with "XI" per docs; do not hard-code assumptions.

C) CXML Verbs required:
- <Connect> doc:
  https://developers.cloudonix.com/Documentation/voiceApplication/Verb/connect (source)
- <Connect><Stream> bi-directional audio WebSocket stream:
  https://developers.cloudonix.com/Documentation/voiceApplication/Verb/connect/stream (source)

D) Webhooks:
- https://developers.cloudonix.com/Documentation/make.com/webhooks (source)
(Use it to understand webhook types and invocation expectations; also search in the portal for voice application webhooks / input params.)

You MUST search within developers.cloudonix.com for any additional endpoints/parameters needed to:
- receive inbound call events
- reply with CXML at runtime
- query call status / call logs if needed

========================
4) ARCHITECTURE PRINCIPLES
========================
Split into:
Control Plane (CRUD/config):
- React + Laravel API + MySQL
- Tenants, users, extensions, ring groups, business hours, DID mapping

Execution Plane (runtime call decisions):
- Webhook ingestion endpoint(s)
- Redis-based idempotency + distributed locking
- Minimal DB reads
- Outputs CXML responses for routing
- Uses Laravel queue workers for safe async processing (Redis queue)

========================
5) DATA STORAGE RULES
========================
MySQL is source of truth:
- tenants, users, extensions
- did_numbers + routing config
- ring_groups + members + strategy
- business hours rules
- call_logs (final state)

Redis is ephemeral:
- call state cache
- idempotency keys for webhooks
- distributed locks to prevent races
- presence/state for live UI
- queues (Laravel)

Redis data must be rebuildable at any time.

========================
6) CALL STATE + RACE CONDITION SAFETY (MANDATORY)
========================
Calls can arrive simultaneously; events can retry/out-of-order.

Implement:
- A small call state machine
- Idempotent webhook processing
- Redis lock per call_id (and optionally per target resource) for state transitions:
  lock key example: lock:call:{call_id}
- Idempotency key storage with TTL:
  idem key example: idem:webhook:{event_id or hash(payload)}
- Durable call_log updates are transactional in MySQL

If the same webhook arrives twice:
- second attempt must be a no-op or return same CXML safely.

========================
7) REAL-TIME UPDATES
========================
Provide “live calls / presence” to the UI:
- Use WebSockets or SSE
- Backend publishes events when call state changes
- Frontend subscribes and updates UI in realtime

========================
8) DOCKER REQUIREMENTS
========================
Provide docker-compose with:
- nginx (reverse proxy)
- laravel app (php-fpm)
- queue-worker
- scheduler
- mysql
- redis

Requirements:
- One-command bring up
- Auto-migrations on boot (safe approach)
- .env.example
- Health endpoints

========================
9) LOCAL WEBHOOK DEVELOPMENT
========================
Use ngrok to expose local webhook endpoints.

Requirements:
- ngrok runs automatically in Docker (configured in docker-compose.yml)
- Uses NGROK_AUTHTOKEN from .env (never commit authtoken to git)
- Web interface available at http://localhost:4040
- Provide clear instructions for getting the public URL
- Include steps for updating WEBHOOK_BASE_URL in .env
- Explain how to configure webhooks in Cloudonix portal
- Include testing instructions for verifying webhook delivery

========================
10) FRONTEND REQUIREMENTS
========================
React SPA pages:
- Login
- Dashboard
- Users / Extensions
- Ring Groups
- DIDs / Routing
- Business Hours
- Call Logs
- Live Calls (presence)

Keep UI functional and minimal.

MANDATORY EMPTY STATE PATTERN:
All feature pages MUST display a consistent empty state when no data is available.
Reference implementation: ConferenceRooms.tsx (lines 790-807)

Required elements:
1. Large icon (h-12 w-12 mx-auto text-muted-foreground mb-4) - relevant to the feature
2. Heading (text-lg font-semibold mb-2) - "No [items] found"
3. Contextual message (text-muted-foreground mb-4) - changes based on filters:
   - If filters active: "Try adjusting your filters"
   - If no filters: "Get started by creating your first [item]"
4. Optional CTA button - shown only when no filters active AND user has create permissions

Example implementation:
```tsx
<TableCell colSpan={N} className="text-center py-12">
  <FeatureIcon className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
  <h3 className="text-lg font-semibold mb-2">No [items] found</h3>
  <p className="text-muted-foreground mb-4">
    {hasActiveFilters
      ? 'Try adjusting your filters'
      : 'Get started by creating your first [item]'}
  </p>
  {canCreate && !hasActiveFilters && (
    <Button onClick={openCreateDialog}>
      <Plus className="h-4 w-4 mr-2" />
      Create [Item]
    </Button>
  )}
</TableCell>
```

========================
11) SECURITY & MULTI-TENANCY
========================
- Tenant isolation everywhere (tenant_id)
- RBAC: Owner, Admin, Agent
- API auth for UI (Laravel Sanctum or JWT; choose one)
- All queries scoped by tenant
- Structured audit-friendly logging (include call_id correlation)

========================
12) OBSERVABILITY
========================
- Structured logs with call_id in every webhook-related log line
- Simple /health endpoint
- Optional: OpenTelemetry hooks if minimal

========================
13) REQUIRED AGENT DELEGATION
========================
api-designer:
- Define REST API routes for control plane
- Define webhook endpoints + contracts
- Define internal event schema (normalized from Cloudonix events)
- Define CXML response patterns (based on Cloudonix docs)

php-pro:
- Laravel implementation
- MySQL schema + migrations
- Models + policies + tenant scoping
- Webhook handlers with idempotency + locks
- Queue workers (Redis)
- CXML generation utilities (either templates or a small builder)
- Integration layer for Cloudonix REST API (Bearer auth, base URL per docs)

frontend-developer:
- React app scaffolding
- API integration
- Realtime presence UI updates
- Forms for PBX config

ui-designer:
- UX flow + layout and component hierarchy
- Simple admin console design
- Ensure usability and minimal friction

websocket-engineer:
- Realtime transport selection (WS or SSE)
- Backend broadcasting implementation + frontend subscription
- Redis pub/sub if needed

========================
14) DELIVERABLES
========================
Produce:
1) Architecture overview (control vs execution plane)
2) DB schema (tables + key fields)
3) API + webhook definitions (routes, payload examples)
4) Call flow diagrams (text-based)
5) Laravel folder structure
6) React folder structure
7) Docker compose + env example
8) ngrok setup guide (how to get authtoken, configure in .env, access web UI)
9) Minimal complete implementation with unit tests:
   - webhook idempotency tests
   - state machine transition tests
   - RBAC/tenant scoping tests
   - basic API tests

========================
15) IMPLEMENTATION NOTE ABOUT CLOUDONIX DOC ACCESS
========================
When you need Cloudonix specifics:
- Always use and cite developers.cloudonix.com pages listed above
- If needed endpoints/params aren’t in the listed URLs, search within the Cloudonix portal starting at:
  https://developers.cloudonix.com/Documentation/
- Do NOT invent Cloudonix parameter names or webhook fields. Verify them in docs first.


