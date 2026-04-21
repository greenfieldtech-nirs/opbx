# Destination Routing System

## Overview
Unified destination selection used across multiple modules: DID routing, IVR menu options, ring group fallbacks, business hours actions, and AI load balancer fallbacks. Shared frontend components and backend validation patterns.

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Services/ResourceReferenceChecker.php` | Cross-reference integrity checking |
| `app/Enums/RoutingDestinationType.php` | Auto-dialer routing types (AI_ASSISTANT, AI_LOAD_BALANCER, HANGUP) |
| `app/Enums/IvrDestinationType.php` | IVR destination types (8 types) |
| `app/Enums/RingGroupFallbackAction.php` | Ring group fallback types (6 types) |
| `app/Enums/BusinessHoursActionType.php` | Business hours action types (6 types) |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/components/destinations/DestinationTypeSelector.tsx` | Type dropdown |
| `frontend/src/components/destinations/DestinationSelector.tsx` | Entity dropdown per type |
| `frontend/src/components/destinations/DestinationTypeAndSelector.tsx` | Combined type+entity selector |
| `frontend/src/components/destinations/DestinationBadge.tsx` | Display badge with icon |

## Destination Types by Context

| Context | Available Types |
|---------|----------------|
| DID Routing | extension, ring_group, business_hours, conference_room, ai_assistant, ivr_menu, ai_load_balancer |
| IVR Options | extension, ring_group, conference_room, ivr_menu, business_hours, ai_assistant, ai_load_balancer, hangup |
| IVR Failover | extension, ring_group, conference_room, ivr_menu, ai_assistant, ai_load_balancer, hangup |
| Ring Group Fallback | extension, ring_group, ivr_menu, ai_assistant, ai_load_balancer, hangup |
| Business Hours | extension, ring_group, conference_room, ivr_menu, ai_assistant, ai_load_balancer |
| AI LB Fallback | extension, ring_group, ivr_menu, ai_assistant, hangup |
| Auto-Dialer Routing | ai_assistant, ai_load_balancer, hangup |

## Frontend Components

### DestinationTypeSelector
Renders a dropdown of destination types. Props: `allowedTypes` (filters available types), `includeHangup`, `showDescriptions`. Types have icons (Phone, Users, Menu, Clock, Bot, Scale, PhoneOff).

### DestinationSelector
Renders a dropdown of entities for the selected type. Uses `useDestinationOptions(type)` hook to fetch options from API. For `forward` type, renders an Input for phone/extension entry instead of dropdown.

### DestinationTypeAndSelector
Composite component combining both selectors. When type changes, clears destination. When type is `hangup`, shows static text. Supports horizontal/vertical/grid layouts.

### DestinationBadge
Display-only badge with type-specific icon and color. Used in tables and detail views.

## ResourceReferenceChecker (Backend)
Checks if a resource is referenced before allowing deletion. Three check types:

| Check | Table | Method |
|-------|-------|--------|
| DID routing | `did_numbers` | JSON column query on `routing_config->{type}_id` |
| IVR options | `ivr_menu_options` | Join with `ivr_menus`, match `destination_type` + `destination_id` |
| IVR failover | `ivr_menus` | Match `failover_destination_type` + `failover_destination_id` |

Returns `{has_references: bool, references: {dids: [], ivr_options: [], ivr_failovers: []}}`.

### Used By Controllers
- `RingGroupController::beforeDestroy()`
- `IvrMenuController::destroy()`
- `ConferenceRoomController::beforeDestroy()`
- `BusinessHoursController::beforeDestroy()`
- `AiAssistantLoadBalancerController::beforeDestroy()`

## Business Hours Target ID Convention
Business hours uses prefixed string IDs (not integer FKs):
- `ext-{id}`, `rg-{id}`, `conf-{id}`, `ivr-{id}`, `ai-{id}`, `alb-{id}`
- Parsed by `BusinessHoursSchedule::parseTargetId()` using regex
- Frontend adds/strips prefixes during form editing

## Validation Pattern
All destination selectors validate:
1. Target entity exists
2. Target belongs to same organization
3. Target is active (status-aware per type)
4. No circular references (IVR self-reference, ALBS circular fallback)

## Related Modules
- [Phone Numbers](phone-numbers.md) - DID routing configuration
- [IVR Menus](ivr-menus.md) - Option and failover destinations
- [Ring Groups](ring-groups.md) - Fallback action destinations
- [Business Hours](business-hours.md) - Open/closed hour actions
- [AI Load Balancers](ai-load-balancers.md) - Fallback destinations
