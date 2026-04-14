# OpBX Project Memory Index

## Architecture
- Laravel 12 (PHP 8.4) backend + React 18 (TypeScript) SPA + Go dialer worker
- Multi-tenant: all data scoped by organization_id
- Cloudonix CPaaS for VoIP (CXML-based call routing)
- MySQL + Redis + MinIO + Soketi (WebSocket)

## Tech Stack
- Backend: Laravel 12, Sanctum auth, Redis caching, MySQL 8
- Frontend: React 18, Vite, TanStack Query, shadcn/ui, Zod, react-hook-form
- Dialer: Go 1.21+, Gin HTTP framework, go-redis
- Infra: Docker Compose, Nginx reverse proxy, MinIO S3, Soketi WebSocket

## Module Index
| Module | Memory File | Key Backend Files | Key Frontend Files |
|--------|------------|-------------------|-------------------|
| Authentication & Authorization | [authentication-authorization.md](authentication-authorization.md) | AuthController, RegisterController, User model | Login, Register, AuthContext |
| Multi-Tenancy | [multi-tenancy.md](multi-tenancy.md) | OrganizationScope, EnsureTenantScope | ConfigContext |
| User Management | [user-management.md](user-management.md) | UsersController, UserPolicy | UsersComplete |
| Profile Management | [profile-management.md](profile-management.md) | ProfileController | Profile |
| Extensions | [extensions.md](extensions.md) | ExtensionCrudController, Extension model | Extensions |
| Phone Numbers (DIDs) | [phone-numbers.md](phone-numbers.md) | PhoneNumberController, DidNumber model | PhoneNumbers |
| Ring Groups | [ring-groups.md](ring-groups.md) | RingGroupController, RingGroup model | RingGroups |
| IVR Menus | [ivr-menus.md](ivr-menus.md) | IvrMenuController, IvrMenu model | IVRMenus |
| Business Hours | [business-hours.md](business-hours.md) | BusinessHoursController, BusinessHoursSchedule model | BusinessHours |
| Conference Rooms | [conference-rooms.md](conference-rooms.md) | ConferenceRoomController, ConferenceRoom model | ConferenceRooms |
| AI Assistants | [ai-assistants.md](ai-assistants.md) | AiAssistantController, ProviderRegistry | AiAssistants |
| AI Load Balancers | [ai-load-balancers.md](ai-load-balancers.md) | AiAssistantLoadBalancerController, AlbsDistributionService | AiAssistantLoadBalancers |
| Recordings | [recordings-announcements.md](recordings-announcements.md) | RecordingsController, Recording model | Announcements |
| Call Logs | [call-logs.md](call-logs.md) | CallLogController, CallLog model | CallLogs |
| Call Detail Records | [call-detail-records.md](call-detail-records.md) | CallDetailRecordController, CDR model | (via CallLogs) |
| Live Calls | [live-calls.md](live-calls.md) | SessionUpdateController, SessionUpdate model | LiveCalls |
| Voice Routing Engine | [voice-routing-engine.md](voice-routing-engine.md) | VoiceRoutingController, VoiceRoutingManager, CxmlBuilder | N/A (server-side) |
| Outbound Whitelist | [outbound-whitelist.md](outbound-whitelist.md) | OutboundWhitelistController | OutboundWhitelist |
| Inbound Blacklist | [inbound-blacklist.md](inbound-blacklist.md) | InboundBlacklistController, InboundBlacklistService | InboundBlacklist |
| Call Notifications | [call-notifications.md](call-notifications.md) | CallNotificationsSettingsController, WebhookDispatcher | CallNotificationsSettings |
| Auto Dialer Campaigns | [auto-dialer-campaigns.md](auto-dialer-campaigns.md) | AutoDialerCampaignController, DialerWorkerController | AutoDialerCampaigns |
| Distribution Lists | [distribution-lists.md](distribution-lists.md) | DistributionListController, ListManagementService | DistributionLists |
| Dialer Worker (Go) | [dialer-worker.md](dialer-worker.md) | dialer-worker/cmd/worker/main.go | N/A |
| AMD Worker (Node.js) | [amd-worker.md](amd-worker.md) | amd-worker/src/index.ts | N/A |
| Auto Dialer Caller ID Pooling | [auto-dialer-caller-id-pooling.md](auto-dialer-caller-id-pooling.md) | See feature specification | See feature specification |
| Platform Management | [platform-management.md](platform-management.md) | Platform controllers, PlatformAuditService | PlatformDashboard, PlatformOrganizations |
| Settings & Cloudonix | [settings-cloudonix.md](settings-cloudonix.md) | SettingsController, CloudonixClient | Settings |
| Transactional Email | [transactional-email.md](transactional-email.md) | TransactionalEmailService, email drivers | N/A |
| WebSocket / Real-Time | [websocket-realtime.md](websocket-realtime.md) | channels.php, Soketi | echo.service, useCallPresence |
| Webhook Processing | [webhook-processing.md](webhook-processing.md) | CloudonixWebhookController, signature middleware | N/A |
| Resilience Patterns | [resilience-patterns.md](resilience-patterns.md) | CircuitBreaker, ResilientCacheService | N/A |
| Security | [security.md](security.md) | SecurityHeaders, rate limiting middleware | N/A |
| Logging & Auditing | [logging-auditing.md](logging-auditing.md) | AuditLogger, LogSanitizer | N/A |
| Dashboard | [dashboard.md](dashboard.md) | ConfigurationController, ApplicationConfig | Dashboard |
| Destination Routing System | [destination-routing-system.md](destination-routing-system.md) | ResourceReferenceChecker, RoutingDestinationType | DestinationSelector components |
| Infrastructure & Docker | [infrastructure-docker.md](infrastructure-docker.md) | docker-compose.yml, Dockerfile, nginx.conf | N/A |

## Key Patterns
- **Multi-tenancy**: OrganizationScope global scope auto-filters all queries by organization_id
- **Auth**: Dual-mode (cookie SPA + Bearer token API), role-based (Owner > PBX Admin > PBX User > Reporter)
- **Voice Routing**: Strategy pattern with CXML response generation
- **CRUD Controllers**: Most extend AbstractApiCrudController with hooks (beforeStore, afterStore, beforeUpdate, afterUpdate, beforeDestroy)
- **Destination System**: Unified destination selection across DID routing, IVR options, ring group fallbacks, business hours actions
- **Rate Limiting**: Per-organization via Redis with configurable limits per endpoint type
- **Caching**: Cache-aside pattern for voice routing with Redis + DB fallback

## Documentation
| Directory | Contents |
|-----------|---------|
| `docs/opbx-userguide/` | Docusaurus user/admin guide (30 .mdx files) |
| `docs/opbx-openapi/` | Multi-file OpenAPI 3.1.0 spec (135 files) |
| `docs/specifications/` | Feature specifications (CPS, monitor, worker) |
| `docs/review-workplan/` | Code & security review workplans and results |
| `docs/review-workplan/CODE-REVIEW-WORKPLAN.md` | Repeatable code review workplan (9 areas, to-do template) |
| `docs/review-workplan/SECURITY-REVIEW-WORKPLAN.md` | Repeatable security review workplan (11 areas, OWASP-mapped, to-do template) |
| `docs/review-workplan/code-review-{date}/` | Code review result to-do documents (per review) |
| `docs/review-workplan/security-review-{date}/` | Security review result to-do documents (per review) |
| `docs/review-workplan/code-review-2026-04-10/FINDINGS.md` | Code review: 48 findings (5 critical, 13 high, 18 medium, 12 low) |
| `docs/review-workplan/security-review-2026-04-10/FINDINGS.md` | Security review: 46 findings (3 critical, 9 high, 16 medium, 12 low, 6 info) |
| `docs/feature-specification/voicemail-detection/` | Stream-based AMD spec + workplan (Node.js/TypeScript AMD worker, ONNX ML, Cloudonix `<Start><Stream>`) |

## Directory Structure (Key Paths)
```
app/Http/Controllers/Api/          # REST API controllers
app/Http/Controllers/Platform/     # Cross-tenant admin controllers
app/Http/Controllers/Voice/        # Voice routing + ALBS follow-through
app/Http/Controllers/Webhooks/     # Cloudonix webhook handlers
app/Models/                        # 35 Eloquent models
app/Services/                      # 85+ service classes (organized by subdirectory)
app/Enums/                         # 26 PHP enums
app/Policies/                      # 17 authorization policies
app/Http/Requests/                 # 52 form request validators
app/Scopes/                        # OrganizationScope global scope
routes/api.php                     # Main REST API routes
routes/webhooks.php                # Voice + webhook routes
routes/platform.php                # Platform admin routes
frontend/src/pages/                # 33 React page components
frontend/src/components/           # Reusable React components
frontend/src/services/             # API service layer
frontend/src/hooks/                # Custom React hooks
frontend/src/context/              # AuthContext, ConfigContext
dialer-worker/                     # Go dialer service
amd-worker/                        # Node.js AMD (voicemail detection) service (planned)
docs/opbx-userguide/               # Docusaurus user guide
docs/opbx-openapi/                 # OpenAPI 3.1.0 spec
docs/feature-specification/        # Feature specifications & workplans
```
