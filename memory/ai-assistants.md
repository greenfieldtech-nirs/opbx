# AI Assistants

## Overview
AI-powered voice assistants that handle calls via SIP, WebSocket, CXML Endpoint, or a built-in dummy test protocol. 19 providers supported (16 SIP + 1 WebSocket + 1 CXML Endpoint + 1 Dummy). Provider configuration is registry-based with dynamic config fields.

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/AiAssistantController.php` | CRUD (extends AbstractApiCrudController) |
| `app/Http/Controllers/Api/AiAssistantProviderController.php` | Provider definitions (read-only) |
| `app/Models/AiAssistant.php` | AI assistant model (SoftDeletes) |
| `app/Enums/AiAssistantStatus.php` | ACTIVE, INACTIVE |
| `app/Services/AiAssistant/ProviderRegistry.php` | Provider definitions registry (singleton) |
| `app/Services/AiAssistant/ProviderDefinition.php` | Provider DTO |
| `app/Services/AiAssistant/ProviderConfigField.php` | Config field DTO |
| `app/Services/AiAssistant/WebSocketUrlBuilder.php` | Secure WSS URL builder |
| `app/Services/AiAssistant/AiAssistantService.php` | Main service |
| `app/Services/AiAssistant/CxmlProxyService.php` | CXML Endpoint proxy/forwarding |
| `app/Services/AiAssistant/CxmlProxyResult.php` | CXML proxy result DTO |
| `app/Services/VoiceRouting/Strategies/AiAgentRoutingStrategy.php` | Real-time routing |
| `app/Services/VoiceRouting/Strategies/AiLoadBalancerRoutingStrategy.php` | ALB routing |
| `app/Http/Controllers/Voice/AlbsFollowThroughController.php` | ALB failover follow-through |
| `app/Services/AutoDialer/AutoDialerCloudonixService.php` | Auto-dialer AI CXML generation |
| `app/Services/AutoDialer/CxmlGenerationService.php` | Dialer-worker AI CXML generation |
| `app/Services/CxmlBuilder/CxmlBuilder.php` | CXML builder (includes dummy message) |
| `app/Http/Requests/AiAssistant/StoreAiAssistantRequest.php` | Validation with provider-specific field checks |
| `app/Policies/AiAssistantPolicy.php` | Authorization |
| `app/Http/Controllers/Api/VoiceAgentController.php` | **Deprecated** — legacy CRUD, no active routes |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/pages/AiAssistants.tsx` | Management page |
| `frontend/src/pages/AiAssistants/components/AiAssistantForm.tsx` | Create/edit form |
| `frontend/src/services/aiAssistants.service.ts` | API calls |
| `frontend/src/services/aiAssistantProviders.service.ts` | Provider definition API |
| `frontend/src/services/cdr.service.ts` | CDR/CXML events API |
| `frontend/src/types/aiAssistant.ts` | TypeScript types |

## Database: `ai_assistants` Table
| Column | Type | Notes |
|--------|------|-------|
| id, organization_id | FK | Tenant scope, soft deletes |
| name | string | Unique per org |
| status | enum | active, inactive |
| provider | string(100) | Provider key from registry |
| protocol | enum | sip, websocket, cxml_endpoint, dummy |
| configuration | JSON | Provider-specific config (empty for dummy) |
| created_by, updated_by | FK nullable | User tracking |

## Registered Providers (ProviderRegistry)

### SIP Providers (16)
synthflow, dasha, superdash.ai, ultravox, elevenlabs, deepvox, relayhawk, voicehub, retell, vapi, fonio, sigmamind, modon, puretalk, millis-us, millis-eu
- All share same config: `phone_number` field (E.164 format)

### WebSocket Providers (1)
deepdub - URL template: `wss://bot.deepdub.dev/ws/{bot_id}/{auth_token}?session={session}&from={from}&to={to}`
- Config fields: `bot_id` (text), `auth_token` (password)

### CXML Endpoint Providers (1)
cxml_endpoint - Protocol: `cxml_endpoint`
- Config fields: `endpoint_url` (text), `timeout_seconds` (integer, 1-30, default 5), `retry_count` (integer, 1-3, default 1), `proxy_did_number` (tel, optional), `custom_headers` (JSON key-value object)
- Forwards inbound voice webhooks to the remote endpoint and proxies the returned CXML back to Cloudonix
- Authenticates outbound requests with `X-OPBX-Signature` (HMAC-SHA256 of body using `CLOUDONIX_WEBHOOK_SECRET`)
- SSRF validation via `SsrfUrlValidator` blocks private/internal URLs
- On proxy failure (standalone): returns verbal unavailable message (`CxmlBuilder::cxmlEndpointUnavailable()`)
- On proxy failure (ALBS): delegates to load balancer follow-through behavior
- Auto Dialer: `AutoDialerCloudonixService` simulates a POST to `endpoint_url` with reversed `From`/`To` (destination becomes `From`, caller_id becomes `To`) and returns the proxied CXML inline as `cxml` (not `url`)
- Proxy events are logged to `call_notification_logs` with `type = 'cxml_proxy'`
- **Extension dialing override**: When an `AI_ASSISTANT` extension is dialed (subscriber direction) and `proxy_did_number` is configured, `AiAgentRoutingStrategy` passes payload overrides to `CxmlProxyService` that rewrite `Direction` to `inbound` and `To` to the proxy DID number, and synchronizes corresponding `X-Cx-*` headers before forwarding

### Dummy Providers (1)
dummy_ai - Protocol: `dummy`
- No config fields, no external connection
- CXML: `<Say>Hi There, this is not an AI assistant...</Say><Hangup/>`
- Useful for verifying routing setup without a real AI service

## Model Helpers (AiAssistant)
- `isWebSocket(): bool` — protocol === 'websocket'
- `isSip(): bool` — protocol === 'sip'
- `isCxmlEndpoint(): bool` — protocol === 'cxml_endpoint'
- `isDummy(): bool` — protocol === 'dummy'
- `getProviderDefinition(): ?ProviderDefinition`

## WebSocket URL Building (WebSocketUrlBuilder)
- Validates `wss://` prefix
- Allowed config placeholders: bot_id, auth_token, api_key, assistant_id, agent_id, app_id, workspace_id, project_id
- Runtime Cloudonix placeholders: session, from, to
- All values are `rawurlencode()`d
- Final `FILTER_VALIDATE_URL` check

## Routing (AiAgentRoutingStrategy)
- **SIP**: Checks `extension.service_url` first (newer), falls back to `provider + config.phone_number`
- **WebSocket**: Builds WSS URL via provider template -> `CxmlBuilder::streamToWebSocket()`
- **CXML Endpoint**: Proxies request via `CxmlProxyService` to remote endpoint; returns proxied CXML or unavailable message on failure
- **Dummy**: Returns `CxmlBuilder::dummyAiMessage()` — a fixed `<Say>` + `<Hangup>` response
- CXML: `<Connect><Stream url="wss://..."/></Connect>` for WS, `<Dial><Service provider="retell">+1234</Service></Dial>` for SIP, proxied `<Response>` for CXML Endpoint, or `<Say>...<Hangup/>` for dummy

## Delete Protection
Cannot delete if in use by any extensions (checked in beforeDestroy). Returns count of using extensions.

## API Routes
| Method | URI | Purpose |
|--------|-----|---------|
| GET | `/v1/ai-assistant/providers` | All providers grouped by protocol |
| GET | `/v1/ai-assistant/providers/{key}` | Single provider |
| GET | `/v1/ai-assistant/providers/protocol/{protocol}` | By protocol |
| Standard CRUD | `/v1/ai-assistants[/{ai_assistant}]` | apiResource |

## Related Modules
- [AI Load Balancers](ai-load-balancers.md) - Load balancing across AI assistants
- [Extensions](extensions.md) - AI_ASSISTANT type extensions
- [Voice Routing](voice-routing-engine.md) - AiAgentRoutingStrategy
