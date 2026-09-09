# OpBX Project Memory Index

> **Agent Instruction**: Read this file first to orient yourself. Each module has its own memory file in this directory. Update the relevant memory file after making changes to any module.
>
> **Last Updated**: 2026-07-09
> **Last Audit**: 2026-07-08 — Full memory audit against current codebase completed. See individual module files for updates and known discrepancies.
> **Project**: OpBX - Open Source Business PBX on Cloudonix CPaaS
> **Stack**: Laravel 12 (PHP 8.4) + React 18 (TypeScript) + Go Dialer Worker + Java/Vert.x 5 AMD Worker

---

## Architecture Overview

- **Multi-tenant**: All data scoped by `organization_id` via `OrganizationScope` global scope
- **Cloudonix CPaaS**: All VoIP/telephony handled externally; OpBX provides PBX configuration + runtime CXML routing decisions
- **Control Plane**: React + Laravel API + MySQL (CRUD/config)
- **Execution Plane**: Webhook ingestion + Redis state + CXML responses + Laravel queue workers
- **Real-time**: Soketi WebSocket server (Laravel Echo/Pusher protocol)
- **Storage**: MySQL (source of truth) + Redis (ephemeral: state, locks, queues) + MinIO (audio recordings)

---

## Module Index

| Module | Memory File | Key Backend Files | Key Frontend Files |
|--------|------------|-------------------|-------------------|
| **Authentication & Authorization** | [authentication-authorization.md](authentication-authorization.md) | AuthController, RegisterController, User model | Login, Register, AuthContext |
| **Auth0 Syndicated Auth** | [auth0-syndicated-auth.md](auth0-syndicated-auth.md) | Auth0Controller, Auth0Service, OrganizationJoinRequestController | Auth0Callback, Auth0Onboarding, SocialAuthButtons |
| **Multi-Tenancy** | [multi-tenancy.md](multi-tenancy.md) | OrganizationScope, EnsureTenantScope | ConfigContext |
| **User Management** | [user-management.md](user-management.md) | UsersController, UserPolicy | UsersComplete |
| **Supervisor Role** | [supervisor.md](supervisor.md) | SupervisorAssignmentController, SupervisorDashboardController, SupervisorFilterService | Dashboard, LiveCalls, CallLogs, UsersComplete |
| **Profile Management** | [profile-management.md](profile-management.md) | ProfileController | Profile |
| **Extensions** | [extensions.md](extensions.md) | ExtensionCrudController, Extension model | Extensions |
| **Phone Numbers (DIDs)** | [phone-numbers.md](phone-numbers.md) | PhoneNumberController, DidNumber model | PhoneNumbers |
| **Ring Groups** | [ring-groups.md](ring-groups.md) | RingGroupController, RingGroup model | RingGroups |
| **IVR Menus** | [ivr-menus.md](ivr-menus.md) | IvrMenuController, IvrMenu model | IVRMenus |
| **Business Hours** | [business-hours.md](business-hours.md) | BusinessHoursController, BusinessHoursSchedule model | BusinessHours |
| **Conference Rooms** | [conference-rooms.md](conference-rooms.md) | ConferenceRoomController, ConferenceRoom model | ConferenceRooms |
| **AI Assistants** | [ai-assistants.md](ai-assistants.md) | AiAssistantController, ProviderRegistry | AiAssistants |
| **AI Load Balancers** | [ai-load-balancers.md](ai-load-balancers.md) | AiAssistantLoadBalancerController, AlbsDistributionService | AiAssistantLoadBalancers |
| **Recordings & Announcements** | [recordings-announcements.md](recordings-announcements.md) | RecordingsController, Recording model | Announcements |
| **Call Logs** | [call-logs.md](call-logs.md) | CallDetailRecordController, CDR model | CallLogs |
| **Call Detail Records** | [call-detail-records.md](call-detail-records.md) | CallDetailRecordController, CDR model (amd_status accessor) | (via CallLogs) |
| **Live Calls** | [live-calls.md](live-calls.md) | SessionUpdateController, SessionUpdate model | LiveCalls |
| **Voice Routing Engine** | [voice-routing-engine.md](voice-routing-engine.md) | VoiceRoutingController, VoiceRoutingManager, CxmlBuilder, AmdActionController | N/A |
| **Outbound Whitelist** | [outbound-whitelist.md](outbound-whitelist.md) | OutboundWhitelistController | OutboundWhitelist |
| **Inbound Blacklist** | [inbound-blacklist.md](inbound-blacklist.md) | InboundBlacklistController, InboundBlacklistService | InboundBlacklist |
| **Call Notifications** | [call-notifications.md](call-notifications.md) | CallNotificationsSettingsController, WebhookDispatcher | CallNotificationsSettings |
| **Call Tracking** | [call-tracking.md](call-tracking.md) | CallTrackingCampaignController, CallTrackingRoutingStrategy | CallTrackingCampaigns, CallTrackingDashboard |
| **Auto Dialer Campaigns** | [auto-dialer-campaigns.md](auto-dialer-campaigns.md) | AutoDialerCampaignController, DialerWorkerController | AutoDialerCampaigns, AutoDialerMonitor |
| **Distribution Lists** | [distribution-lists.md](distribution-lists.md) | DistributionListController, ListManagementService | DistributionLists |
| **Dialer Worker (Go)** | [dialer-worker.md](dialer-worker.md) | dialer-worker/cmd/worker/main.go | N/A |
| **AMD Worker (Java/Vert.x)** | [amd-worker.md](amd-worker.md) | amd-worker/src/main/java/... (Java/Vert.x 5) | N/A |
| **Auto Dialer Caller ID Pooling** | [auto-dialer-caller-id-pooling.md](auto-dialer-caller-id-pooling.md) | AutoDialerCampaignCallerId, AutoDialerCallerIdStat | (via AutoDialerCampaigns) |
| **Platform Management** | [platform-management.md](platform-management.md) | Platform controllers, PlatformAuditService | PlatformDashboard, PlatformOrganizations |
| **Settings & Cloudonix** | [settings-cloudonix.md](settings-cloudonix.md) | SettingsController, CloudonixClient | Settings |
| **Transactional Email** | [transactional-email.md](transactional-email.md) | TransactionalEmailService, email drivers | N/A |
| **WebSocket / Real-Time** | [websocket-realtime.md](websocket-realtime.md) | channels.php, Soketi | echo.service, useCallPresence |
| **Webhook Processing** | [webhook-processing.md](webhook-processing.md) | CloudonixWebhookController, VerifyCloudonixSignature | N/A |
| **Resilience Patterns** | [resilience-patterns.md](resilience-patterns.md) | CircuitBreaker, ResilientCacheService | N/A |
| **MCP Server** | [mcp-server.md](mcp-server.md) | mcp-server/ (Node/TS/Fastify, Phase 1 done) | N/A |
| **Security** | [security.md](security.md) | SecurityHeaders, rate limiting middleware | N/A |
| **Logging & Auditing** | [logging-auditing.md](logging-auditing.md) | AuditLogger, LogSanitizer | N/A |
| **Dashboard** | [dashboard.md](dashboard.md) | ConfigurationController, ApplicationConfig | Dashboard |
| **Public Website** | [public-website.md](public-website.md) | Home page | `frontend/src/pages/Home.tsx` |
| **Destination Routing System** | [destination-routing-system.md](destination-routing-system.md) | ResourceReferenceChecker, RoutingDestinationType | DestinationSelector components |
| **Scoped API Keys** | [api-keys.md](api-keys.md) | ApiKeyController, ApiKeyService, ResolveApiKey + EnforceApiKeyScope middleware | ApiKeysSettings, apiKeys.service |
| **Trunk Management** | [trunk-management.md](trunk-management.md) | TrunkController, CloudonixTrunksClient (CRUD), TrunkResource | Trunks page, trunks.service |
| **Infrastructure & Docker** | [infrastructure-docker.md](infrastructure-docker.md) | docker-compose.yml, Dockerfile, nginx.conf | N/A |

---

## Key Architectural Patterns

1. **Multi-tenancy**: `#[ScopedBy([OrganizationScope::class])]` on all models. Bypass: `OrganizationScope::bypass(fn() => ...)`
2. **Auth**: Dual-mode (cookie SPA via Sanctum + Bearer token API), role-based (Owner > PBX Admin > PBX User/Supervisor > Reporter). Optional Auth0 social auth in SaaS mode.
3. **Voice Routing**: Strategy pattern with 8 routing strategies. CXML generation via DOMDocument.
4. **CRUD Controllers**: Most extend `AbstractApiCrudController` with hooks (beforeStore, afterStore, beforeUpdate, afterUpdate, beforeDestroy)
5. **Destination System**: Unified destination selection across DID routing, IVR options, ring group fallbacks, business hours actions
6. **Rate Limiting**: Per-organization via Redis with configurable limits per endpoint type
7. **Caching**: Cache-aside pattern for voice routing with Redis + DB fallback
8. **Webhook Security**: Voice routing and Status/CDR middleware with idempotency keys
9. **Call State**: Redis locks per `call_id`, idempotency keys for webhook retries
10. **Frontend State**: TanStack Query for server state, React Context for client state, Zod + react-hook-form for forms

---

## Build / Test / Lint Commands

### PHP (Laravel)
```bash
./run-tests.sh                             # All tests (runs inside Docker, MySQL `opbx_test`)
./run-tests.sh --filter=TestClassName      # Single test class
./run-tests.sh --filter=test_method_name   # Single test method

vendor/bin/pint                            # Lint/fix all PHP (PSR-12)
vendor/bin/pint --dirty                    # Fix only changed files

composer dev                               # Dev server + queue + logs + Vite
php artisan serve                          # API only
php artisan queue:listen                   # Queue worker
```

### Testing Rules
- **MySQL only**: All tests run against the MySQL `opbx_test` database. SQLite is not used because its semantics can mask MySQL-specific failures.
- Feature tests must use the `RefreshDatabase` trait.
- Test factories live in `Database\Factories\`.

### Frontend (React)
```bash
cd frontend
npm run dev                  # Vite dev server on :5173
npm run build                # Production build (tsc + vite build)
npm run type-check           # TypeScript check only (tsc --noEmit)
```

### Docker
```bash
docker compose up -d                        # Full stack startup
docker compose logs -f [service]            # View logs
docker compose exec app php artisan migrate # Run migrations
# IMPORTANT: Wait 120 seconds after restart before testing.
```

---

**Last Updated**: 2026-07-08 (post-audit)
