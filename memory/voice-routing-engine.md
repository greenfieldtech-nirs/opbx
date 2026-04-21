# Voice Routing Engine

## Overview
The real-time call processing core. When Cloudonix CPaaS receives a call, it sends an HTTP webhook to OpBX which responds with CXML instructions. Uses a Strategy Pattern with 8 routing strategies. The VoiceRoutingManager (2,136 lines) is the central orchestrator.

**NEW (2026-04-18)**: AMD Action controller for handling voicemail detection results from the AMD worker.

## Source Files

### Core
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Voice/VoiceRoutingController.php` | Thin controller (96 lines) |
| `app/Http/Controllers/Voice/AmdActionController.php` | **AMD action execution — NEW 2026-04-18** |
| `app/Services/VoiceRouting/VoiceRoutingManager.php` | Central orchestrator (2,136 lines) |
| `app/Services/VoiceRouting/VoiceRoutingCacheService.php` | Redis cache-aside (356 lines) |
| `app/Services/CxmlBuilder/CxmlBuilder.php` | CXML XML generator (714 lines) |
| `config/voice_routing.php` | Cache TTLs, lock timeouts |

### Routing Strategies
| File | Handles | Lines |
|------|---------|-------|
| `Strategies/UserRoutingStrategy.php` | ExtensionType::USER | 59 |
| `Strategies/RingGroupRoutingStrategy.php` | ExtensionType::RING_GROUP | 579 |
| `Strategies/IvrRoutingStrategy.php` | ExtensionType::IVR | 148 |
| `Strategies/ConferenceRoutingStrategy.php` | ExtensionType::CONFERENCE | 48 |
| `Strategies/ForwardRoutingStrategy.php` | ExtensionType::FORWARD | 81 |
| `Strategies/QueueRoutingStrategy.php` | ExtensionType::QUEUE | 29 (stub) |
| `Strategies/AiAgentRoutingStrategy.php` | ExtensionType::AI_ASSISTANT | 246 |
| `Strategies/AiLoadBalancerRoutingStrategy.php` | ExtensionType::AI_LOAD_BALANCER | 511 |

### Supporting Services
| File | Purpose |
|------|---------|
| `app/Services/VoiceRouting/VoiceRoutingStrategyExecutor.php` | Strategy resolution and execution |
| `app/Services/VoiceRouting/InboundRoutingService.php` | Inbound call routing logic |
| `app/Services/VoiceRouting/ExtensionRoutingService.php` | Extension-specific routing |
| `app/Services/VoiceRouting/OutboundRoutingService.php` | Whitelist matching for outbound calls |
| `app/Services/VoiceRouting/RingGroupRoutingService.php` | Ring group call handling |
| `app/Services/VoiceRouting/IvrRoutingService.php` | IVR-specific routing support |
| `app/Services/VoiceRouting/BusinessHoursRoutingService.php` | Time-based routing |
| `app/Services/VoiceRouting/AlbsDistributionService.php` | AI LB distribution algorithms |
| `app/Services/IvrStateService.php` | Redis IVR call state |
| `app/Services/CallStateManager/CallStateManager.php` | Call lifecycle state |
| `app/Services/InboundBlacklist/InboundBlacklistService.php` | Blacklist checking |
| `app/Services/Voice/CxmlResponse.php` | Alternative CXML builder (legacy) |
| `app/Services/AiAssistant/CxmlProxyService.php` | CXML Endpoint proxy for AI routing |
| `app/Services/CxmlBuilder/AutoDialerCxmlBuilder.php` | CXML for auto-dialer |
| `app/ValueObjects/IvrAudioConfig.php` | IVR audio resolution |
| `app/Http/Controllers/Voice/AlbsFollowThroughController.php` | AI LB failover (782 lines) |

## Webhook Routes (routes/webhooks.php)
| Method | URI | Controller | Middleware |
|--------|-----|-----------|-----------|
| POST | `/voice/route` | VoiceRoutingController@handleInbound | `voice.webhook.auth`, `rate_limit_org:voice_routing` |
| POST | `/voice/ivr-input` | VoiceRoutingController@handleIvrInput | Same |
| POST | `/callbacks/voice/ring-group-callback` | VoiceRoutingController@handleRingGroupCallback | Same |
| POST | `/callbacks/voice/albs-follow-through` | AlbsFollowThroughController@handle | Same |
| **POST** | **`/voice/amd-action`** | **`AmdActionController@handle`** | **Bearer token auth** |
| GET | `/voice/health` | VoiceRoutingController@health | None |

## AmdActionController — NEW 2026-04-18

Handles AMD detection results from the AMD worker. Authenticates via `Authorization: Bearer {AMD_WORKER_API_TOKEN}`.

### Actions
- **URL** (starts with `http://` or `https://`): Calls `CloudonixClient::switchVoiceApplication(sessionToken, url)`
- **HANGUP**: Calls `CloudonixClient::disconnectSession(sessionToken)`
- **CONTINUE**: Logs only, no Cloudonix API call

### Pre-Action Step
Before executing any action, updates the Cloudonix session profile with AMD result:
```json
{
  "profile": {
    "amd": {
      "result": "voicemail",
      "confidence": 0.9,
      "detectionTimeMs": 13487,
      "reason": "Tone detected...",
      "timestamp": "2026-04-18T11:19:25Z"
    }
  }
}
```

This profile is later included in the CDR webhook and displayed in the Call Logs UI.

## Call Flow

### Direction Dispatch (VoiceRoutingManager::handleInbound, line 72)
```
Direction field -> match expression:
  "subscriber"   -> handleSubscriberDirection()  [internal PBX user]
  "inbound"      -> handleInboundDirection()     [external or internal-from-DID]
  "outbound"     -> handleOutgoingDirection()    [outbound only]
  "application"  -> handleApplicationDirection() [API-initiated]
  default        -> handleUnknownDirection()     [fallback to inbound]
```

### Inbound Call Routing
1. Check if `From` is an assigned DID (internal call from PBX user)
   - Yes: try extension -> DID -> outbound (full internal routing)
   - No: route ONLY to the called DID's configured destination

### DID Routing (routeDidCall, line 540)
Loads DID's `routing_type` and resolves target model:
- extension -> load Extension, resolve destination
- ring_group -> load RingGroup with members
- conference_room -> load ConferenceRoom
- ivr_menu -> load IvrMenu
- ai_assistant -> load Extension with AiAssistant
- ai_load_balancer -> load Extension with ALB config
- business_hours -> load schedule, check open/closed, parse target_id, resolve target

### Strategy Execution (executeStrategy, line 1784)
Iterates registered strategies -> first `canHandle()` match -> calls `route()` -> returns CXML Response

## CXML Generation (CxmlBuilder)
Uses `DOMDocument`. All input XML-encoded. Key static methods:
- `dialExtension(sipUri)` -> `<Dial><Sip>...</Sip></Dial>`
- `dialRingGroup(sipUris[])` -> `<Dial>` with multiple targets
- `simpleDial(dest, callerId, timeout, trunk)` -> outbound with trunk
- `joinConference(id, max, mute, announce)` -> `<Dial><Conference>...</Conference></Dial>`
- `streamToWebSocket(url)` -> `<Connect><Stream url="wss://..."/></Connect>`
- `streamToWebSocketWithAction(url, actionUrl)` -> Same with callback
- `dialServiceProvider(provider, phone)` -> `<Dial><Service provider="...">phone</Service></Dial>`
- `cxmlEndpointUnavailable()` -> `<Say>The remote application service is not available, good bye</Say><Hangup/>`
- `gather(verbs, action, timeout)` -> `<Gather>` for IVR
- `busy(msg)`, `unavailable(msg)`, `simpleHangup()` -> Error responses

## CXML Endpoint Extension Dialing Override
When an extension of type `AI_ASSISTANT` is dialed via subscriber (internal) direction and its AI assistant is a `cxml_endpoint` provider with `proxy_did_number` configured:
- `AiAgentRoutingStrategy::routeCxmlEndpoint` constructs payload overrides (`Direction` -> `inbound`, `To` -> `proxy_did_number`)
- `CxmlProxyService::proxy` merges overrides into the request body, regenerates `X-OPBX-Signature` on the modified body, and synchronizes forwarded `X-Cx-*` headers (case-insensitive) before posting to the remote endpoint
- This allows inbound-only CXML endpoints to handle extension calls by presenting them as inbound calls to a recognized proxy DID

## Cache Configuration (voice_routing.php)
| Setting | Default |
|---------|---------|
| extension_ttl | 1800s (30 min) |
| business_hours_ttl | 900s (15 min) |
| lock_timeout | 30s |
| lock_block | 3s |
| idempotency_ttl | 3600s (1h) |
| no_answer_timeout | 30s |

## IVR Input Processing (VoiceRoutingManager::handleIvrInput, line 1054)
1. Validate menu exists and is active
2. Check/update call state in Redis (turn count, input history)
3. No input -> increment turns, replay or failover
4. Valid option -> route to destination
5. Invalid option -> error message + replay or failover
6. After max_turns -> route to failover destination

## Related Modules
- [Phone Numbers](phone-numbers.md) - DIDs are the primary inbound entry point
- [Extensions](extensions.md) - Routing targets
- [Ring Groups](ring-groups.md) - RingGroupRoutingStrategy
- [IVR Menus](ivr-menus.md) - IvrRoutingStrategy + IvrStateService
- [AI Assistants](ai-assistants.md) - AiAgentRoutingStrategy
- [AI Load Balancers](ai-load-balancers.md) - AiLoadBalancerRoutingStrategy + AlbsFollowThroughController
- [Business Hours](business-hours.md) - Time-based routing dispatch
- [Inbound Blacklist](inbound-blacklist.md) - Call blocking before routing
- [Outbound Whitelist](outbound-whitelist.md) - Outbound routing with trunk selection
- [Webhook Processing](webhook-processing.md) - Voice routing is triggered by webhooks
- [AMD Worker](amd-worker.md) - 🆕 NEW — Stream-based voicemail detection
