# AI Assistant Load Balancers

## Overview
Distributes calls across multiple AI assistants using round-robin, priority, or percentage strategies. Supports follow-through failover (cascading to next assistant when one fails) and configurable fallback actions.

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/AiAssistantLoadBalancerController.php` | CRUD with Redis locking |
| `app/Http/Controllers/Voice/AlbsFollowThroughController.php` | Failover callback (782 lines) |
| `app/Models/AiAssistantLoadBalancer.php` | Load balancer model (SoftDeletes) |
| `app/Models/AiAssistantLoadBalancerMember.php` | Member model |
| `app/Enums/AlbsStatus.php` | ACTIVE, INACTIVE |
| `app/Enums/AlbsStrategy.php` | ROUND_ROBIN, PRIORITY, PERCENTAGE |
| `app/Services/VoiceRouting/AlbsDistributionService.php` | Distribution algorithms (372 lines) |
| `app/Services/VoiceRouting/Strategies/AiLoadBalancerRoutingStrategy.php` | Real-time routing (511 lines) |
| `app/Http/Requests/AiAssistantLoadBalancer/StoreAlbsRequest.php` | Validation |
| `app/Policies/AiAssistantLoadBalancerPolicy.php` | Authorization |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/pages/AiAssistantLoadBalancers.tsx` | Management page (1133 lines) |

## Database Tables

### `ai_assistant_load_balancers`
| Column | Type | Notes |
|--------|------|-------|
| id, organization_id | FK | Tenant scope, soft deletes |
| name | string | Unique per org |
| strategy | enum | round_robin, priority, percentage |
| follow_through | boolean | Cascade to next assistant on failure |
| status | enum | active, inactive |
| fallback_action | enum | Uses RingGroupFallbackAction |
| fallback_extension_id, fallback_ring_group_id, fallback_ivr_menu_id, fallback_ai_assistant_id | FK nullable | Only one populated |

### `ai_assistant_load_balancer_members`
| Column | Type | Notes |
|--------|------|-------|
| load_balancer_id, ai_assistant_id | FK | Unique pair |
| priority | integer | Lower = higher (for PRIORITY strategy) |
| weight | integer 0-100 | For PERCENTAGE strategy |
| position | integer | For ROUND_ROBIN order |
| status | enum | active, inactive |

## Distribution Algorithms (AlbsDistributionService)
| Strategy | Algorithm |
|----------|-----------|
| round_robin | Redis atomic `INCR` (`albs:rr:{id}`) + modulo. Falls back to random if Redis fails. |
| priority | First active member ordered by priority ascending |
| percentage | Weighted random: cumulative weights, `random_int(1, totalWeight)` |

## Follow-Through Failover (AlbsFollowThroughController)
1. Cloudonix calls callback URL when AI assistant call fails (busy/no-answer/failed/canceled/unknown)
2. If `follow_through=false`: immediately execute fallback action
3. If `follow_through=true`: get tried assistants from Redis cache (`albs:tried:{callSid}`, 1h TTL)
4. Add failed assistant to tried list
5. Select next untried assistant using the ALBS strategy
6. If none available: execute fallback action
7. Route to next assistant with NEW callback URL (cascading)

## Fallback Actions
EXTENSION, RING_GROUP, IVR_MENU, AI_ASSISTANT, HANGUP
- Fallback AI_ASSISTANT routes WITHOUT follow-through callback (prevents infinite loops)
- Other fallbacks delegate to `VoiceRoutingManager::executeStrategy()` for recursive routing

## Concurrency Control
Redis lock: `lock:albs:{id}`, 10s timeout, 5s block. Returns 409 on conflict.

## Cache Keys
| Key | Purpose | TTL |
|-----|---------|-----|
| `albs:rr:{id}` | Round-robin counter | 24h |
| `albs:tried:{callSid}` | Follow-through tried list | 1h |
| `albs:{id}` | ALBS cache | 30min |

## API Routes
Standard apiResource at `/v1/ai-assistant-load-balancers`

## Related Modules
- [AI Assistants](ai-assistants.md) - Members are AI assistants
- [Voice Routing](voice-routing-engine.md) - AiLoadBalancerRoutingStrategy
- [Ring Groups](ring-groups.md) - Shares RingGroupFallbackAction enum
