# Ring Groups

## Overview
Ring groups allow incoming calls to ring multiple extensions simultaneously or sequentially. Supports three strategies and six fallback action types with full fallback chains.

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/RingGroupController.php` | CRUD (extends AbstractApiCrudController, 298 lines) |
| `app/Models/RingGroup.php` | Ring group model |
| `app/Models/RingGroupMember.php` | Member pivot model |
| `app/Enums/RingGroupStrategy.php` | SIMULTANEOUS, ROUND_ROBIN, SEQUENTIAL |
| `app/Enums/RingGroupStatus.php` | ACTIVE, INACTIVE |
| `app/Enums/RingGroupFallbackAction.php` | EXTENSION, RING_GROUP, IVR_MENU, AI_ASSISTANT, AI_LOAD_BALANCER, HANGUP |
| `app/Services/VoiceRouting/Strategies/RingGroupRoutingStrategy.php` | Real-time call routing (579 lines) |
| `app/Policies/RingGroupPolicy.php` | Authorization |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/pages/RingGroups.tsx` | Ring group management (1730 lines) |
| `frontend/src/utils/ringGroups.ts` | Display helpers |
| `frontend/src/components/design-system/RingGroupStrategySelector.tsx` | Strategy picker |

## Database Tables

### `ring_groups`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| organization_id | FK | Tenant scope |
| name | string | |
| description | string nullable | |
| strategy | enum | simultaneous, round_robin, sequential |
| timeout | integer | Ring timeout (seconds) |
| ring_turns | integer | Number of ring cycles |
| fallback_action | enum | What to do when no one answers |
| fallback_extension_id | FK nullable | |
| fallback_ring_group_id | FK nullable | Self-referencing |
| fallback_ivr_menu_id | FK nullable | |
| fallback_ai_assistant_id | FK nullable | |
| fallback_ai_load_balancer_id | FK nullable | |
| status | enum | active, inactive |

### `ring_group_members`
| Column | Type |
|--------|------|
| id | bigint |
| ring_group_id | FK |
| extension_id | FK |
| priority | integer |

## Key Business Logic

### Fallback Normalization (`RingGroupController.php:129`)
Only ONE fallback ID is populated based on `fallback_action`. All five fallback FK fields are cleared, then the matching one is set. Preserves existing value on update if new value not provided.

### Concurrency Control (`RingGroupController.php:213`)
Redis distributed lock on update: `lock:ring_group:{id}`, 10s timeout, 5s block. Returns 409 Conflict if lock cannot be acquired.

### Member Management (`RingGroupController.php:188`)
Delete-and-recreate pattern: on update, all existing members are deleted and new ones created from the request payload.

### Delete Protection (`RingGroupController.php:292`)
`checkResourceReferencesBeforeDelete()` prevents deletion if referenced by DIDs, IVR options, or IVR failovers.

### Controller Hooks
| Hook | Line | Purpose |
|------|------|---------|
| `beforeStore` | 176 | Extract members, normalize fallback fields |
| `afterStore` | 188 | Create RingGroupMember records |
| `beforeUpdate` | 257 | Extract members, normalize fallback with existing model |
| `afterUpdate` | 269 | Delete+recreate members |
| `beforeDestroy` | 290 | Check resource references |

## Strategies
| Strategy | Behavior |
|----------|----------|
| simultaneous | All members ring at once |
| round_robin | Distributes calls evenly across members |
| sequential | Tries members one by one (uses ring_turns for multiple passes) |

## API Routes
Standard apiResource at `/v1/ring-groups`

## Related Modules
- [Voice Routing](voice-routing-engine.md) - RingGroupRoutingStrategy handles real-time routing
- [Extensions](extensions.md) - Members are extensions
- [Phone Numbers](phone-numbers.md) - DIDs can route to ring groups
- [IVR Menus](ivr-menus.md) - IVR options can route to ring groups; ring groups can fallback to IVR
