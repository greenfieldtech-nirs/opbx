# IVR Menus

## Bug Fix History

### 2026-04-11: IvrMenuController Refactored (CR-11)
**File:** `app/Http/Controllers/Api/IvrMenuController.php`

**Change:** Refactored to extend `AbstractApiCrudController` instead of base `Controller`.

**Before:**
- 588 lines of custom CRUD implementation
- Duplicated CRUD logic

**After:**
- 786 lines using base controller + hooks
- Consistent with other resource controllers

**Hook Methods Implemented:**
- `beforeStore()` - Extract options, resolve audio config
- `afterStore()` - Create IVR menu options
- `beforeUpdate()` - Extract options, resolve audio config  
- `afterUpdate()` - Replace options
- `beforeDestroy()` - Check resource references

**Custom Methods Kept:**
- `getVoices()` - Fetch TTS voices
- `toggleStatus()` - Status toggle

---

### 2026-04-11: AI Assistant Destination Validation Bug
**File:** `app/Models/IvrMenuOption.php` (line 180)

**Problem:** IVR routes to AI Assistants returned "Destination is no longer available" error even when the AI Assistant was active.

**Root Cause:** The `isValidDestination()` method compared AI Assistant status against `UserStatus::ACTIVE`, but the `AiAssistant` model uses `AiAssistantStatus` enum. The comparison always failed because they are different enum types.

**Fix:**
```php
// Before (broken):
IvrDestinationType::AI_ASSISTANT => $destination->status === UserStatus::ACTIVE,

// After (fixed):
IvrDestinationType::AI_ASSISTANT => $destination->status->isActive(),
```

### 2026-04-11: Missing AI Destination Types in routeToDestination
**File:** `app/Services/VoiceRouting/VoiceRoutingManager.php`

**Problem:** IVR failover routing to AI Assistant or AI Load Balancer failed with "Unknown destination type" error.

**Root Cause:** The `routeToDestination()` method was missing validation lookup and routing logic for `AI_ASSISTANT` and `AI_LOAD_BALANCER` destination types.

**Fix:** Added validation and routing for both destination types:
```php
} elseif ($destinationType === \App\Enums\IvrDestinationType::AI_ASSISTANT) {
    $validatedDestination = \App\Models\AiAssistant::withoutGlobalScope(...)->first();
} elseif ($destinationType === \App\Enums\IvrDestinationType::AI_LOAD_BALANCER) {
    $validatedDestination = \App\Models\AiAssistantLoadBalancer::withoutGlobalScope(...)->first();
}
```

And added routing logic:
```php
} elseif ($destinationType === \App\Enums\IvrDestinationType::AI_ASSISTANT) {
    $destination = ['ai_assistant' => $validatedDestination];
    return $this->executeStrategy(\App\Enums\ExtensionType::AI_ASSISTANT, ...);
} elseif ($destinationType === \App\Enums\IvrDestinationType::AI_LOAD_BALANCER) {
    $destination = ['ai_load_balancer' => $validatedDestination];
    return $this->executeStrategy(\App\Enums\ExtensionType::AI_LOAD_BALANCER, ...);
}
```

---

### 2026-04-11: Unified isActive() Interface for Destination Validation
**Files:** `app/Models/IvrMenuOption.php`, `app/Models/ConferenceRoom.php`, `app/Models/AiAssistant.php`

**Problem:** The `isValidDestination()` method used a complex `match` expression with inconsistent patterns:
- Some destinations checked `$destination->status === UserStatus::ACTIVE`
- Some used `$destination->isActive()`
- Conference rooms returned `true` (hardcoded)
- AI Assistants used `$destination->status->isActive()` (different pattern)

**Solution:** Applied polymorphism - all destination models now implement a consistent `isActive()` method:

```php
// Unified validation - all destinations use same interface
$isValid = $destination->isActive();
```

**Added `isActive()` to:**
- `ConferenceRoom` - returns `$this->status === UserStatus::ACTIVE`
- `AiAssistant` - returns `$this->status === AiAssistantStatus::ACTIVE`

**Already had `isActive()`:**
- `Extension`, `RingGroup`, `IvrMenu`, `AiAssistantLoadBalancer`, `BusinessHoursSchedule`

---

## Overview
Interactive Voice Response menus that play audio/TTS prompts and route calls based on DTMF digit input. Supports audio files, remote URLs, and TTS with Cloudonix voices. Max 20 options per menu with failover destination.

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/IvrMenuController.php` | CRUD extending AbstractApiCrudController (~786 lines) |
| `app/Models/IvrMenu.php` | IVR menu model (149 lines) |
| `app/Models/IvrMenuOption.php` | Menu option model with polymorphic destination (229 lines) |
| `app/Enums/IvrMenuStatus.php` | ACTIVE, INACTIVE |
| `app/Enums/IvrDestinationType.php` | 8 destination types (59 lines) |
| `app/Services/IvrMenuService.php` | Business logic |
| `app/Services/IvrStateService.php` | Redis-based call state for live IVR sessions |
| `app/Services/IvrMenu/IvrMenuValidationPipeline.php` | Pipeline validator |
| `app/Services/IvrMenu/Validators/` | Audio, Destination, Priority validators |
| `app/ValueObjects/IvrAudioConfig.php` | Audio config value object |
| `app/Http/Requests/StoreIvrMenuRequest.php` | Create validation |
| `app/Http/Requests/UpdateIvrMenuRequest.php` | Update validation (+ self-reference prevention) |
| `app/Services/VoiceRouting/Strategies/IvrRoutingStrategy.php` | Real-time IVR routing |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/pages/IVRMenus.tsx` | IVR menu builder (1658 lines) |

## Database Tables

### `ivr_menus`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| organization_id | FK | Tenant scope |
| name | string | |
| audio_file_path | string nullable | Audio URL or MinIO path |
| tts_text | string nullable | TTS content (max 1000) |
| tts_voice | string nullable | Voice identifier |
| max_timeout | integer | Wait for first input (1-30s) |
| inter_digit_timeout | integer | Between digits (1-30s) |
| max_turns | integer | Replays on invalid input (1-9) |
| failover_destination_type | enum | Where to route after max turns |
| failover_destination_id | integer nullable | Target entity ID |
| status | enum | active, inactive |

### `ivr_menu_options`
| Column | Type | Notes |
|--------|------|-------|
| ivr_menu_id | FK | Parent menu |
| input_digits | string | DTMF digits (1-10 chars) |
| description | string nullable | |
| destination_type | enum | Target type |
| destination_id | integer | Target entity ID |
| priority | integer | Display order (1-20) |

## IVR Destination Types (`app/Enums/IvrDestinationType.php`)
EXTENSION, RING_GROUP, CONFERENCE_ROOM, IVR_MENU, BUSINESS_HOURS, AI_ASSISTANT, AI_LOAD_BALANCER, HANGUP

## Audio Configuration Rules
Mutual exclusivity - exactly ONE audio source:
1. `recording_id` (MinIO-stored file)
2. `audio_file_path` (remote URL)
3. `tts_text` + optional `tts_voice`

## IVR Call State (`IvrStateService.php`)
Redis key: `ivr:call:{callSid}`, TTL: 3600s (1 hour)
State: `{ menu_id, turn_count, started_at, last_input_at, input_history[] }`
Idempotency key: `ivr:idempotency:{eventId}`, TTL: 300s

## IVR Option Destination Resolution (`IvrMenuOption.php:89-113`)
Each option resolves its destination by loading the target model using `withoutGlobalScope(OrganizationScope::class)`:
- EXTENSION -> Extension model
- AI_ASSISTANT -> AiAssistant model
- RING_GROUP -> RingGroup model
- CONFERENCE_ROOM -> ConferenceRoom model
- IVR_MENU -> IvrMenu model
- AI_LOAD_BALANCER -> AiAssistantLoadBalancer model
- BUSINESS_HOURS -> BusinessHoursSchedule model

## API Routes
| Method | URI | Purpose |
|--------|-----|---------|
| GET | `/v1/ivr-menus/voices` | Fetch Cloudonix TTS voices (registered before apiResource!) |
| Standard CRUD | `/v1/ivr-menus[/{ivrMenu}]` | apiResource |
| PATCH | `/v1/ivr-menus/{ivrMenu}/toggle-status` | Status toggle |

## Self-Reference Prevention
- UpdateIvrMenuRequest prevents an IVR menu from referencing itself as failover destination
- Options cannot route to the same menu they belong to

## Delete Protection
Checks references in: other IVR menu options, IVR failover destinations, DID routing configs. Returns 409 with detailed reference info.

## Related Modules
- [Voice Routing](voice-routing-engine.md) - IvrRoutingStrategy handles live IVR routing
- [Recordings](recordings-announcements.md) - Audio file sources
- [Ring Groups](ring-groups.md) - Bidirectional routing
- [Destination Routing](destination-routing-system.md) - Shared destination selection
