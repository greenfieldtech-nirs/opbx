# AI Assistants

> **Last Updated**: 2026-07-06
> **Status**: ACTIVE — Major feature module
> **Depends On**: Extensions, Voice Routing Engine, Settings & Cloudonix

---

## Overview

AI-powered voice assistants with 19 providers, registry-based configuration, and CXML endpoint proxying. AI assistants can be linked to extensions or used as auto-dialer campaign routing destinations.

---

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/AiAssistantController.php` | CRUD + provider registry (~400 lines) |
| `app/Models/AiAssistant.php` | AI assistant model (~200 lines) |
| `app/Services/AiAssistantService.php` | AI assistant service logic |
| `app/Services/AiAssistant/ProviderRegistry.php` | Provider configuration registry |
| `app/Services/AiAssistant/Providers/` | Per-provider configuration handlers |
| `app/Http/Requests/AiAssistant/StoreAiAssistantRequest.php` | Create validation |
| `app/Http/Requests/AiAssistant/UpdateAiAssistantRequest.php` | Update validation |
| `app/Http/Resources/AiAssistantResource.php` | API response transformer |

### Resource fix (2026-07-03)
- `AiAssistantResource` fixed a 500 on `GET /api/v1/ai-assistants/{ai_assistant}`: the `used_by_extensions` mapping was calling `$ext->when(...)` instead of `$this->when(...)`, causing the resource conditional to be invoked on an Eloquent model rather than the resource instance.
| `app/Policies/AiAssistantPolicy.php` | Authorization |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/pages/AiAssistants.tsx` | AI assistant management page |
| `frontend/src/services/aiAssistantsApi.ts` | API calls |
| `frontend/src/hooks/useAiAssistants.ts` | Queries/mutations |
| `frontend/src/components/Extensions/AiAssistantConfigForm.tsx` | Dynamic provider config form |

---

## Provider Registry

19 supported providers with JSON-based configuration schemas. Each provider defines:
- Required/optional fields
- Field types (string, number, boolean, select, url)
- Validation rules
- Default values

Common providers include: OpenAI, Anthropic, Google, Azure, and custom CXML endpoint providers.

---

## Extension Integration

Extensions of type `AI_ASSISTANT` link to an `AiAssistant` record via `ai_assistant_id`.

### CXML Endpoint Proxying
When an extension of type `AI_ASSISTANT` is dialed via subscriber (internal) direction and its AI assistant is a `cxml_endpoint` provider with `proxy_did_number` configured:
- `AiAgentRoutingStrategy::routeCxmlEndpoint` constructs payload overrides (`Direction` → `inbound`, `To` → `proxy_did_number`)
- The strategy merges overrides into the request body, regenerates `X-OPBX-Signature` on the modified body, and synchronizes forwarded `X-Cx-*` headers (case-insensitive) before posting to the remote endpoint
- This allows inbound-only CXML endpoints to handle extension calls by presenting them as inbound calls to a recognized proxy DID

---

## Database: `ai_assistants` Table

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| organization_id | FK | Tenant scope |
| name | string | Display name |
| provider | string | Provider key from registry |
| configuration | JSON | Provider-specific settings |
| phone_number | string nullable | Associated phone number |
| status | enum | active, inactive |

---

## API Routes

| Method | URI | Controller | Notes |
|--------|-----|-----------|-------|
| GET/POST/PUT/DELETE | `/v1/ai-assistants[/{assistant}]` | AiAssistantController | Standard CRUD |
| GET | `/v1/ai-assistants/providers` | AiAssistantController@providers | List available providers |
| GET | `/v1/ai-assistants/providers/{provider}/schema` | AiAssistantController@schema | Get provider config schema |

---

## Notes

### ProviderDefinition Properties (2026-07-05)
- `ProviderDefinition` exposes `configFields` (camelCase), not `config_fields`.
- `ProviderConfigField` exposes `name`, not `key`.
- `UpdateAiAssistantRequest` and `AiAssistantService` were both fixed to use the correct property names; previously they referenced the non-existent snake_case versions and caused `Undefined property` errors when updating an AI assistant with a provider configuration.

### Configuration Validation (2026-07-05)
- `StoreAiAssistantRequest` uses `configuration.present` + `configuration.array` (not `required`) so providers with no required fields — e.g. `dummy_ai` — can be created with `configuration: {}`.
- `UpdateAiAssistantRequest` uses `configuration.sometimes` + `configuration.array` so partial updates also accept an empty object when the provider requires nothing.
- Provider-specific required fields are still enforced in the `withValidator` after-hook and in `AiAssistantService::validateConfiguration`.

## Provider Registry Notes (2026-07-06)

- Registry now includes 21 providers: two new WebSocket providers `dograh-cloud` and `dograh-oss`.
- `dograh-cloud` uses a fixed read-only WebSocket endpoint (`wss://app.dograh.com/api/v1/agent-stream`) and requires an `agent_uuid`.
- `dograh-oss` uses a user-supplied `websocket_endpoint` and an `agent_uuid`.
- The assembled CXML WebSocket URL is `{websocket_endpoint}/{agent_uuid}`.
- `ProviderConfigField` supports `read_only` and `default_value` fields for UI rendering.
- `WebSocketUrlBuilder` treats `websocket_endpoint` as a raw URL placeholder (not URL-encoded) so the endpoint structure is preserved.

## Related Modules

- [Extensions](extensions.md) — AI_ASSISTANT type extensions
- [Voice Routing](voice-routing-engine.md) — AiAgentRoutingStrategy
- [Auto Dialer Campaigns](auto-dialer-campaigns.md) — Campaign routing destinations
- [AI Load Balancers](ai-load-balancers.md) — ALB can distribute to AI assistants
