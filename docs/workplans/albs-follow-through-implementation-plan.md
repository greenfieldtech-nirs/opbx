# AI Load Balancer "Follow Through" Feature - Implementation Plan

## Overview
Add a new fallback action type "Follow Through" that enables call failover between AI Assistants within a load balancer. When a call to an AI Assistant fails (busy, no-answer, failed), the system will automatically route the call to the next available AI Assistant based on the distribution strategy.

## Architecture

### Call Flow with Follow Through

```
1. Call → Extension (AI Load Balancer)
   ↓
2. Select AI Assistant #1 using distribution strategy
   ↓
3. Route call to AI Assistant #1 with follow_through callback URL
   ↓
4. If call succeeds → Done
   ↓
5. If call fails (busy/no-answer/failed)
   ↓
6. Follow Through callback invoked
   ↓
7. Select AI Assistant #2
   ↓
8. Route call to AI Assistant #2
   ↓
9. Repeat until success or all assistants tried
   ↓
10. If all fail → Execute original fallback action (hangup/extension/etc.)
```

### Key Components

1. **New Endpoint:** `/api/voice/albs-follow-through`
   - Receives callback from failed AI Assistant calls
   - Selects next AI Assistant based on strategy
   - Returns CXML to continue routing

2. **State Storage:** Redis
   - Tracks which assistants have been tried
   - Stores call session data for follow-through continuation

3. **CXML Modifications:**
   - Add `action` parameter for SIP calls
   - Add `statusCallback` for WebSocket streams

---

## Implementation Phases

### Phase 1: Backend - Database & Configuration (30 mins)

**Files to modify:**
- `app/Enums/RingGroupFallbackAction.php`

**Changes:**
```php
case FOLLOW_THROUGH = 'follow_through';
 ````

**Updatedescription()`:**
```php
self::FOLLOW_THROUGH => 'Try next AI Assistant in load balancer (sequential)',
```

---

### Phase 2: Backend - Route Follow Through Endpoint (45 mins)

**Files to create:**
- `app/Http/Controllers/Api/AlbsFollowThroughController.php`

**Endpoint:** `POST /api/voice/albs-follow-through`

**Request Parameters:**
- `CallSid` - Cloudonix call session ID
- `albs_id` - AI Load Balancer ID
- `current_assistant_id` - AI Assistant that just failed
- `status` - Call status (busy, no-answer, failed, etc.)
- `Direction` - Call direction
- `From` - Caller number
- `To` - Called number

**Logic:**
1. Validate request
2. Get ALB configuration
3. Select next AI Assistant (excluding already-tried ones)
4. If found → return CXML to route to next assistant
5. If not found → return original fallback CXML

**Response CXML Examples:**

For WebSocket AI Assistant:
```xml
<Response>
  <Redirect method="POST">
    <Url>https://.../api/voice/albs-follow-through</Url>
  </Redirect>
</Response>
```

For SIP AI Assistant:
```xml
<Response>
  <Redirect method="POST">
    <Url>https://.../api/voice/albs-follow-through</Url>
  </Redirect>
</Response>
```

---

### Phase 3: Backend - Modify AiLoadBalancerRoutingStrategy (30 mins)

**Files to modify:**
- `app/Services/VoiceRouting/Strategies/AiLoadBalancerRoutingStrategy.php`

**Changes:**
1. Add `followThrough()` method to generate follow-through CXML
2. Modify routing to include follow-through callback URL
3. Store tried assistants in session/state for continuity

**CXML Generation for SIP:**
```php
return CxmlBuilder::dialServiceProviderWithAction(
    $provider,
    $phoneNumber,
    $actionUrl
);
```

**CXML Generation for WebSocket:**
```php
return CxmlBuilder::streamToWebSocketWithCallback(
    $websocketUrl,
    $statusCallbackUrl
);
```

---

### Phase 4: Backend - CxmlBuilder Extensions (20 mins)

**Files to modify:**
- `app/Services/CxmlBuilder/CxmlBuilder.php`

**Add methods:**
```php
public static function dialServiceProviderWithAction(
    string $provider,
    string $phoneNumber,
    string $action
): string

public static function streamToWebSocketWithCallback(
    string $url,
    string $statusCallbackUrl
): string
```

---

### Phase 5: Backend - Register Routes & Middleware (15 mins)

**Files to modify:**
- `routes/api.php`

**Add route:**
```php
Route::post('/voice/albs-follow-through', [AlbsFollowThroughController::class, 'handle'])
    ->name('voice.albs-follow-through')
    ->middleware(['verify.voice.webhook.auth']);
```

---

### Phase 6: Frontend - Add Fallback Option (20 mins)

**Files to modify:**
- `frontend/src/pages/AiAssistantLoadBalancers.tsx`

**Changes:**
1. Add "Follow Through" option to fallback action dropdown
2. Add conditional display logic (only show if strategy supports it)

**UI Location:** Around line 1050-1070 in renderFormDialog()

---

### Phase 7: Testing & Validation (30 mins)

**Test Cases:**
1. Follow Through with Round Robin strategy
2. Follow Through with Priority strategy
3. Follow Through with Percentage strategy
4. All assistants fail → fallback to hangup
5. WebSocket AI Assistant follow-through
6. SIP AI Assistant follow-through

---

## Technical Details

### Redis Key Structure

```
# Track tried assistants for a call
albs:tried:{call_sid} → [assistant_id_1, assistant_id_2, ...]

# TTL: 1 hour
```

### Distribution Strategy Modification

**Round Robin:**
- Skip already-tried assistants
- Use Redis counter for next selection

**Priority:**
- Skip already-tried assistants
- Return highest priority available

**Percentage:**
- Skip already-tried assistants
- Redistribute weights among remaining

### Callback Request Flow

```
Cloudonix → POST /api/voice/albs-follow-through
   ├─ albs_id: 1
   ├─ current_assistant_id: 5
   ├─ status: busy
   └─ ...other params...

OPBX → Select next assistant (not in tried list)
OPBX → Return CXML redirect to next assistant

Cloudonix → Redirect call to next assistant
```

---

## API Endpoints

### New Endpoint

**POST** `/api/voice/albs-follow-through`

**Authentication:** Bearer token (Voice Webhook Auth)

**Request Body:**
```json
{
  "CallSid": "session-123",
  "albs_id": "1",
  "current_assistant_id": "5",
  "status": "failed",
  "Direction": "subscriber",
  "From": "1004",
  "To": "20000"
}
```

**Success Response (200):**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Redirect method="POST">
    <Url>https://domain/api/voice/albs-follow-through</Url>
  </Redirect>
</Response>
```

**Final Failure Response (200):**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say>All AI assistants are unavailable. Goodbye.</Say>
  <Hangup/>
</Response>
```

**Error Response (400/500):**
```json
{
  "error": "Invalid request",
  "message": "..."
}
```

---

## Files to Modify/Create

| Phase | File | Action |
|-------|------|--------|
| 1 | `app/Enums/RingGroupFallbackAction.php` | Add FOLLOW_THROUGH case |
| 2 | `app/Http/Controllers/Api/AlbsFollowThroughController.php` | Create |
| 3 | `app/Services/VoiceRouting/Strategies/AiLoadBalancerRoutingStrategy.php` | Modify |
| 4 | `app/Services/CxmlBuilder/CxmlBuilder.php` | Add methods |
| 5 | `routes/api.php` | Add route |
| 6 | `frontend/src/pages/AiAssistantLoadBalancers.tsx` | Add UI option |
| 7 | Tests | Create unit tests |

---

## Estimated Time: ~3 hours

## Rollback Plan

1. Remove route from `routes/api.php`
2. Remove FOLLOW_THROUGH from enum
3. Remove frontend UI option
4. CxmlBuilder methods can remain (will be unused)
