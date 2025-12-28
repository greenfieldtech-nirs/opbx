# Core Routing Application - Detailed Specification

## Executive Summary

The **Core Routing Application** is the heart of the OPBX (Open PBX) system, serving as a Cloudonix Voice Application that handles all inbound call routing decisions in real-time. It operates as a webhook endpoint that receives call events from Cloudonix and responds with CXML (Cloudonix Markup Language) documents that instruct the platform how to route each call based on organizational configuration.

This application transforms static PBX configuration (users, extensions, ring groups, business hours, etc.) into dynamic, real-time call routing instructions.

---

## 1. Architecture Overview

### 1.1 Position in the System

The Core Routing Application sits between two major components:

```
┌─────────────────┐      HTTP POST       ┌──────────────────────┐    CXML Response      ┌─────────────────┐
│   Cloudonix     │ ──────────────────→  │  Core Routing App    │ ──────────────────→   │   Cloudonix     │
│   Platform      │   (Call Event)       │   (Webhook)          │   (Instructions)      │   Platform      │
└─────────────────┘                      └──────────────────────┘                       └─────────────────┘
         │                                          │                                          │
         │                                          ↓                                          │
         │                                   ┌──────────────┐                                  │
         │                                   │   MySQL DB   │                                  │
         │                                   │  (Config)    │                                  │
         │                                   └──────────────┘                                  │
         │                                                                                     │
         └─────────────────────────────────────────────────────────────────────────────────────┘
                                    Executes CXML Instructions
```

**Key Characteristics:**
- **Stateless Webhook**: Each request is independent; state lives in MySQL and Redis
- **Read-Only Database Access**: Only reads configuration; never modifies during call routing
- **Fast Response Required**: Must respond within 2-3 seconds to avoid caller timeout
- **High Availability**: Must handle multiple concurrent calls (100+ simultaneous)
- **Tenant-Isolated**: All routing decisions scoped to organization/tenant

### 1.2 Technology Stack

- **Language**: PHP 8.4+ (Laravel Framework)
- **HTTP Framework**: Laravel Routes with dedicated controller
- **Caching**: Redis for hot-path data (business hours, active users)
- **Database**: MySQL (read-only for routing logic)
- **Output Format**: XML (CXML documents)
- **Deployment**: Docker container with PHP-FPM

---

## 2. Webhook Request Specification

### 2.1 Inbound Request from Cloudonix

When a call arrives at a configured DNID (phone number), Cloudonix makes an HTTP POST request to the Core Routing Application webhook URL.

**Request Details:**

| Aspect | Value |
|--------|-------|
| **HTTP Method** | POST |
| **Content-Type** | `application/x-www-form-urlencoded` or JSON |
| **Timeout** | 10 seconds (caller will hear silence/timeout if exceeded) |
| **Retry Behavior** | No retries; failure results in call disconnect |

**Request Headers:**

```
POST /api/voice/routeRequest HTTP/1.1
Host: opbx.example.com
Content-Type: application/x-www-form-urlencoded
X-CX-Domain: tenant-domain.cloudonix.io
Authorization: Bearer {webhook_auth_token}
User-Agent: Cloudonix/5.3
```

### 2.2 Request Parameters

The webhook receives these parameters (derived from [search results](https://developers.cloudonix.com/articles/callRouting/retellAndFreePBX/warmTransfer)):

| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| `From` | string | Caller ID (ANI) - who is calling | `+14155551234` |
| `To` | string | DNID - the phone number that was dialed | `+18005551000` |
| `Session` | string | Unique session identifier for this call | `abc123-session-uuid` |
| `Domain` | string | Cloudonix domain name for the tenant | `tenant.cloudonix.io` |
| `SessionData` | JSON string | Additional metadata including SIP headers | `{"trunk-sip-header": {...}}` |
| `CallSid` | string | Unique call identifier | `CAxxxxxxxxxxxxxx` |
| `Direction` | string | Call direction | `inbound` |
| `CallStatus` | string | Current call state | `ringing`, `in-progress` |

**Example POST Body:**
```
{
  "ApiVersion": "1",
  "CallStatus": "unknown",
  "From": "972546982826",
  "To": "972532006879",
  "CallSid": "6284916944dd44cf20a1668347eedfe9@sentry.bglobal.global",
  "SessionData": {
    "id": 2795535,
    "domainId": 102,
    "destination": "97229991390",
    "callerId": "18456402102",
    "token": "2eac5483c05842e885583d8f81f873dc",
    "profile": {
      "trunk-sip-headers": {
        "Ident": "Njg4NjUxMzIxNTAwMA==",
        "A2B-A2Bacc": "",
        "A2B-CLID": "18456402102",
        "A2B-Account": "",
        "A2B-DNID": "17188383588"
      },
      "callId": [
        "6284916944dd44cf20a1668347eedfe9@sentry.bglobal.global"
      ],
      "inbound-trunk-name": "inbound-147.234.16.245",
      "inbound-trunk-id": 380
    },
    "callStartTime": 1659451145018,
    "status": "new",
    "vappServer": "172.24.40.238",
    "domainNameOrId": "bglobal.global",
    "ringing": false
  },
  "Domain": "bglobal.global",
  "Direction": "inbound",
  "AccountSid": "bglobal.global",
  "Session": "2eac5483c05842e885583d8f81f873dc"
}
```


---

## 3. Core Routing Logic

### 3.1 High-Level Decision Flow

The routing application processes each call through this decision tree:

```
┌─────────────────────────────────────────────────────────────────┐
│  1. IDENTIFY TENANT                                             │
│     - Match 'To' (DNID) against did_numbers table               │
│     - Extract organization_id                                   │
│     - If no match → Reject call (404 response)                  │
└────────────────┬────────────────────────────────────────────────┘
                 ↓
┌─────────────────────────────────────────────────────────────────┐
│  2. CHECK BUSINESS HOURS                                        │
│     - Query business_hours_schedules for this DID               │
│     - Evaluate current time against active schedule             │
│     - Determine: OPEN or CLOSED                                 │
└────────────────┬────────────────────────────────────────────────┘
                 ↓
         ┌───────┴────────┐
         │                │
    [CLOSED]          [OPEN]
         │                │
         ↓                ↓
┌────────────────┐  ┌─────────────────────────────────────────────┐
│  3a. CLOSED    │  │  3b. OPEN HOURS ROUTING                     │
│  Execute       │  │     - Get DID routing configuration         │
│  after_hours   │  │     - Route based on routing_type:          │
│  action        │  │       • extension → Dial extension          │
└────────────────┘  │       • ring_group → Ring group routing     │
                    │       • ivr → IVR menu                      │
                    │       • voicemail → Direct to voicemail     │
                    └─────────────────────────────────────────────┘
```

### 3.2 Detailed Routing Types

#### 3.2.0 Call Routing inbound/outbound classification
Voice Application requests from Cloudonix are received for both internal and external call attempts.

A call will be deemed as an internal call attempt if (originating from a PBX User to anywhere else):
- The "From" attribute of the JSON object equals to an extension number that is assigned to a "PBX User".
- The "From" attribute of the JSON object equals to an extension number that is assigned to an "AI Assistant".
- The "To" attribute of the JSON object equals to any internal extension number, or is a valid E.164 formatted number.

A call will be deemed as an external call attempt if (originating from the outside world to a valid E.164 number):
- The "From" attribute of the JSON object isn't equal to any of application "Extensions".
- The "To" attribute of the JSON object equals to an application defined "Phone Number".

Upon receiving a "Call Routing Voice Application request", it must be validated against the above rules. If a rule matches,
the call may continue onwards for additional checking. If not, the result will be the following CXML response:

```
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Hangup />
</Response>
```

#### 3.2.1 Extension Routing (`routing_type: 'extension'`)

**Configuration:**
```php
did_numbers.routing_type = 'extension'
did_numbers.routing_destination_id = {extension_id}
```

**Decision Process:**
1. Look up extension by `routing_destination_id`
2. Check extension status: `active` or `inactive`
3. Check extension type: `user`, `conference`, `ring_group`, `ivr`, `ai_assistant`, `forward`
4. If no extension type is matched, the call is deemed to be routed externally - setting its type to "world".
5. If `type = 'user'`:
   - Look up user by `extension.user_id`
   - Check user status: `active` or `inactive`
   - Get user's SIP endpoint from `users` table
6. If `type = 'world'`:
   - Use From and To to generate and CXML document.
7. Generate appropriate CXML

**CXML Response for User Extension:**
**Description:** Inbound calls from +18005551000 to extension number 1005.
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Dial timeout="30" callerId="+18005551000">1005</Dial>
</Response>
```

**CXML for Unavailable Extension:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say voice="woman" language="en-US">
    The extension you are trying to reach is unavailable. Goodbye.
  </Say>
  <Hangup/>
</Response>
```

#### 3.2.2 Ring Group Routing (`routing_type: 'ring_group'`)

**Configuration:**
```php
did_numbers.routing_type = 'ring_group'
did_numbers.routing_destination_id = {ring_group_id}
```

**Ring Strategies:**

| Strategy | Behavior | CXML Pattern |
|----------|----------|--------------|
| **Simultaneous** | Ring all members at once | Multiple `<Number>` elements in single `<Dial>` |
| **Round Robin** | Ring one at a time in rotation | Sequential `<Dial>` with state tracking |
| **Priority** | Ring by priority order | Sequential `<Dial>` ordered by priority |
| **Longest Idle** | Ring member idle longest | Query last call time, order by idle duration |

**CXML for Simultaneous Ring:**
**Description:** Inbound calls from +18005551000 to multiple extensions.
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Dial timeout="25" callerId="+18005551000">1005&1006&1007</Dial>
  <!-- If no answer, handle fallback -->
  <Say>All agents are busy. Please try again later.</Say>
  <Hangup/>
</Response>
```

**Round Robin Implementation:**
- Use `action` callback URL to track dial result
- If no answer, webhook is called again with `DialCallStatus=no-answer`
- Respond with next member in sequence
- Requires Redis to track last-dialed member per ring group

**CXML for Round Robin (First Attempt):**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Dial timeout="20" action="https://opbx.example.com/api/voice/ring-group-callback?group_id=5&attempt=1">1005</Dial>
</Response>
```

**Callback Handler Response (After No Answer):**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Dial timeout="20" action="https://opbx.example.com/api/voice/ring-group-callback?group_id=5&attempt=2">1002</Number>
  </Dial>
</Response>
```

#### 3.2.3 IVR Menu Routing (`routing_type: 'ivr'`)

**Configuration:**
```php
did_numbers.routing_type = 'ivr'
did_numbers.routing_destination_id = {ivr_menu_id}
```

**IVR Flow:**
1. Look up IVR menu configuration
2. Generate greeting prompt
3. Use `<Gather>` to collect DTMF input
4. Set `action` callback URL to process input
5. Map digits to destinations

**CXML for IVR Menu:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Gather
    numDigits="1"
    timeout="5"
    finishOnKey="#"
    action="https://opbx.example.com/api/voice/ivr-input?menu_id=3">
    <Say voice="woman" language="en-US">
      Thank you for calling Acme Corporation.
      Press 1 for Sales.
      Press 2 for Support.
      Press 3 for Billing.
      Press 0 for the operator.
    </Say>
  </Gather>
  <!-- If no input -->
  <Say>We did not receive your selection. Goodbye.</Say>
  <Hangup/>
</Response>
```

**IVR Input Handler:**
- Receives `Digits` parameter with pressed key
- Looks up destination from `ivr_menus.options` JSON
- Routes to extension, ring group, or sub-menu
- Supports nested IVR menus (up to 5 levels deep)

**CXML After Digit Press (e.g., "1" for Sales):**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say>Connecting you to Sales.</Say>
  <Dial timeout="30">3000</Dial>
</Response>
```

#### 3.2.4 Conference Room Routing (`routing_type: 'conference'`)

**CXML for Conference:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say>Joining conference room 1234.</Say>
  <Dial>
    <Conference>
      room-1234-tenant-{org_id}
    </Conference>
  </Dial>
</Response>
```

**Conference Features:**
- Unique room name per conference: `room-{id}-tenant-{org_id}`
- Wait music before first participant joins
- Moderator controls (optional)
- Recording (optional)
- Entry/exit tones

#### 3.2.5 AI Assistant Routing (`routing_type: 'ai_assistant'`)

**CXML for AI Assistant:**
**Description:** Dialing to a VAPI AI Assistant.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Dial>
        <Service provider="vapi">+12127773456</Service>
    </Dial>
</Response>
```

**Configuration:**
- AI provider (VAPI, Retell, Custom)
- WebSocket stream URL
- Provider-specific parameters
- Session authentication

#### 3.2.6 Call Forwarding (`routing_type: 'forward'`)

**CXML for Forward:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say>Your call is being forwarded.</Say>
  <Dial timeout="30" callerId="+18005551000">+14155559999</Dial>
</Response>
```

**Forward Types:**
- **Unconditional**: Always forward
- **On Busy**: Forward if extension busy
- **On No Answer**: Forward after timeout
- **On Unavailable**: Forward if extension offline

---

## 4. Business Hours Evaluation

### 4.1 Business Hours Logic

**Data Model:**
```php
business_hours_schedules:
  - id
  - organization_id
  - name
  - timezone (e.g., 'America/New_York')
  - is_active

business_hours_rules:
  - schedule_id
  - day_of_week (0=Sunday, 6=Saturday)
  - is_open (boolean)
  - open_time ('09:00:00')
  - close_time ('17:00:00')

business_hours_exceptions:
  - schedule_id
  - date ('2024-12-25')
  - is_open (boolean)
  - open_time (nullable)
  - close_time (nullable)
  - description ('Christmas Day')
```

**Evaluation Algorithm:**

```php
function isBusinessHoursOpen($schedule_id, $current_datetime) {
    // 1. Check for date-specific exceptions (holidays)
    $exception = BusinessHoursException::where('schedule_id', $schedule_id)
        ->where('date', $current_datetime->format('Y-m-d'))
        ->first();

    if ($exception) {
        if (!$exception->is_open) {
            return false; // Closed for holiday
        }
        // Check exception hours
        return isTimeInRange(
            $current_datetime->format('H:i:s'),
            $exception->open_time,
            $exception->close_time
        );
    }

    // 2. Check regular weekly schedule
    $day_of_week = $current_datetime->dayOfWeek; // 0-6
    $rule = BusinessHoursRule::where('schedule_id', $schedule_id)
        ->where('day_of_week', $day_of_week)
        ->first();

    if (!$rule || !$rule->is_open) {
        return false; // Closed this day
    }

    // 3. Check if current time is within open hours
    return isTimeInRange(
        $current_datetime->format('H:i:s'),
        $rule->open_time,
        $rule->close_time
    );
}
```

### 4.2 After-Hours Routing

**Configuration:**
```php
did_numbers:
  - after_hours_action ('voicemail', 'forward', 'hangup', 'announcement')
  - after_hours_destination (extension_id, phone_number, or null)
  - after_hours_message (custom announcement)
```

**CXML for After-Hours (Voicemail):**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say voice="woman" language="en-US">
    You have reached Acme Corporation. Our office is currently closed.
    Please leave a message after the tone.
  </Say>
  <Record
    maxLength="180"
    playBeep="true"
    recordingStatusCallback="https://opbx.example.com/api/voice/voicemail-saved"/>
  <Say>Thank you. Goodbye.</Say>
  <Hangup/>
</Response>
```

**CXML for After-Hours (Announcement + Hangup):**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say voice="woman" language="en-US">
    Thank you for calling Acme Corporation.
    Our business hours are Monday through Friday, 9 AM to 5 PM Eastern Time.
    Please call back during business hours.
  </Say>
  <Hangup/>
</Response>
```

---

## 5. State Management & Redis Caching

### 5.1 Redis Cache Strategy

**Cache Keys:**

| Key Pattern | TTL | Purpose |
|-------------|-----|---------|
| `routing:did:{phone_number}` | 3600s | DID routing config |
| `routing:extension:{ext_id}` | 1800s | Extension details |
| `routing:ring_group:{rg_id}` | 1800s | Ring group members |
| `routing:business_hours:{schedule_id}` | 900s | Business hours rules |
| `routing:user:{user_id}` | 1800s | User SIP endpoint |
| `call:state:{call_sid}` | 7200s | Call state for sequential routing |
| `ring_group:last_dialed:{rg_id}` | 3600s | Round robin state |

**Cache Population:**
- **On-Demand**: Load from MySQL on cache miss
- **Warm Cache**: Pre-populate hot DIDs on deployment
- **Invalidation**: Event-driven from control plane updates

**Example Cache Structure:**
```json
{
  "routing:did:+18005551000": {
    "organization_id": 5,
    "routing_type": "ring_group",
    "routing_destination_id": 12,
    "business_hours_schedule_id": 3,
    "after_hours_action": "voicemail"
  }
}
```

### 5.2 Call State Tracking

For sequential routing (round robin, priority), maintain state:

```php
Redis::setex("call:state:{$call_sid}", 3600, json_encode([
    'ring_group_id' => 12,
    'attempt_number' => 2,
    'members_tried' => [101, 102],
    'fallback_action' => 'voicemail',
    'started_at' => time()
]));
```

---

## 6. Database Schema Requirements

### 6.1 Core Tables

**did_numbers:**
```sql
CREATE TABLE did_numbers (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    routing_type ENUM('extension', 'ring_group', 'ivr', 'voicemail', 'conference', 'ai_assistant', 'forward'),
    routing_destination_id BIGINT UNSIGNED NULL,
    business_hours_schedule_id BIGINT UNSIGNED NULL,
    after_hours_action ENUM('voicemail', 'forward', 'hangup', 'announcement'),
    after_hours_destination VARCHAR(255) NULL,
    after_hours_message TEXT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    INDEX idx_phone_number (phone_number),
    INDEX idx_organization (organization_id)
);
```

**extensions:**
```sql
CREATE TABLE extensions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    extension_number VARCHAR(10) NOT NULL,
    type ENUM('user', 'conference', 'ring_group', 'ivr', 'ai_assistant', 'forward'),
    user_id BIGINT UNSIGNED NULL,
    configuration JSON NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    voicemail_enabled BOOLEAN DEFAULT FALSE,
    UNIQUE KEY unique_org_extension (organization_id, extension_number)
);
```

**ring_groups:**
```sql
CREATE TABLE ring_groups (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    strategy ENUM('simultaneous', 'round_robin', 'priority', 'longest_idle'),
    ring_timeout INT DEFAULT 30,
    fallback_action ENUM('voicemail', 'forward', 'hangup', 'repeat'),
    fallback_destination VARCHAR(255) NULL,
    status ENUM('active', 'inactive') DEFAULT 'active'
);
```

**ring_group_members:**
```sql
CREATE TABLE ring_group_members (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    ring_group_id BIGINT UNSIGNED NOT NULL,
    extension_id BIGINT UNSIGNED NOT NULL,
    priority INT DEFAULT 0,
    position INT DEFAULT 0,
    UNIQUE KEY unique_member (ring_group_id, extension_id),
    INDEX idx_ring_group (ring_group_id)
);
```

**business_hours_schedules:**
```sql
CREATE TABLE business_hours_schedules (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    timezone VARCHAR(50) DEFAULT 'America/New_York',
    is_active BOOLEAN DEFAULT TRUE
);
```

**business_hours_rules:**
```sql
CREATE TABLE business_hours_rules (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    schedule_id BIGINT UNSIGNED NOT NULL,
    day_of_week TINYINT NOT NULL, -- 0=Sunday, 6=Saturday
    is_open BOOLEAN DEFAULT TRUE,
    open_time TIME NULL,
    close_time TIME NULL,
    INDEX idx_schedule (schedule_id)
);
```

**business_hours_exceptions:**
```sql
CREATE TABLE business_hours_exceptions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    schedule_id BIGINT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    is_open BOOLEAN DEFAULT FALSE,
    open_time TIME NULL,
    close_time TIME NULL,
    description VARCHAR(255) NULL,
    UNIQUE KEY unique_exception (schedule_id, date)
);
```

---

## 7. Implementation Architecture

### 7.1 Laravel Controller Structure

**Route Definition:**
```php
// routes/api.php
Route::post('/voice/route', [VoiceRoutingController::class, 'handleInbound'])
    ->middleware(['cloudonix.webhook.auth']);
Route::post('/voice/ivr-input', [VoiceRoutingController::class, 'handleIvrInput'])
    ->middleware(['cloudonix.webhook.auth']);
Route::post('/voice/ring-group-callback', [VoiceRoutingController::class, 'handleRingGroupCallback'])
    ->middleware(['cloudonix.webhook.auth']);
```

**Controller:**
```php
namespace App\Http\Controllers\Voice;

class VoiceRoutingController extends Controller
{
    public function handleInbound(Request $request): Response
    {
        // 1. Parse webhook parameters
        $from = $request->input('From');
        $to = $request->input('To');
        $callSid = $request->input('CallSid');

        // 2. Identify tenant and DID configuration
        $did = $this->didLookupService->findByPhoneNumber($to);

        if (!$did) {
            return $this->cxmlResponse->notFound();
        }

        // 3. Check business hours
        $isOpen = $this->businessHoursService->isOpen(
            $did->business_hours_schedule_id
        );

        // 4. Route call
        if (!$isOpen) {
            return $this->routeAfterHours($did, $callSid);
        }

        return $this->routeDuringHours($did, $from, $callSid);
    }

    protected function routeDuringHours($did, $from, $callSid)
    {
        switch ($did->routing_type) {
            case 'extension':
                return $this->extensionRoutingService->route(
                    $did->routing_destination_id,
                    $from,
                    $callSid
                );

            case 'ring_group':
                return $this->ringGroupRoutingService->route(
                    $did->routing_destination_id,
                    $from,
                    $callSid
                );

            case 'ivr':
                return $this->ivrRoutingService->route(
                    $did->routing_destination_id,
                    $callSid
                );

            // ... other cases
        }
    }
}
```

### 7.2 Service Layer Architecture

**ExtensionRoutingService:**
```php
class ExtensionRoutingService
{
    public function route($extension_id, $from, $call_sid): CxmlResponse
    {
        $extension = Extension::with('user')->find($extension_id);

        if (!$extension || $extension->status !== 'active') {
            return CxmlResponse::unavailable(
                "The extension is unavailable."
            );
        }

        switch ($extension->type) {
            case 'user':
                return $this->routeToUser($extension, $from);

            case 'conference':
                return $this->routeToConference($extension);

            case 'ring_group':
                $ring_group_id = $extension->configuration['ring_group_id'];
                return app(RingGroupRoutingService::class)
                    ->route($ring_group_id, $from, $call_sid);

            // ... other types
        }
    }

    protected function routeToUser($extension, $from)
    {
        if (!$extension->user || $extension->user->status !== 'active') {
            return CxmlResponse::unavailable(
                "The user is unavailable."
            );
        }

        $sip_endpoint = sprintf(
            'sip:ext%s@%s',
            $extension->extension_number,
            $extension->user->organization->domain
        );

        return CxmlResponse::dial([$sip_endpoint], [
            'timeout' => 30,
            'callerId' => $from
        ]);
    }
}
```

**RingGroupRoutingService:**
```php
class RingGroupRoutingService
{
    public function route($ring_group_id, $from, $call_sid): CxmlResponse
    {
        $ring_group = RingGroup::with('members.extension.user')
            ->find($ring_group_id);

        if (!$ring_group || $ring_group->status !== 'active') {
            return CxmlResponse::unavailable(
                "The ring group is unavailable."
            );
        }

        switch ($ring_group->strategy) {
            case 'simultaneous':
                return $this->simultaneousRing($ring_group, $from);

            case 'round_robin':
                return $this->roundRobinRing($ring_group, $from, $call_sid);

            case 'priority':
                return $this->priorityRing($ring_group, $from, $call_sid);

            case 'longest_idle':
                return $this->longestIdleRing($ring_group, $from, $call_sid);
        }
    }

    protected function simultaneousRing($ring_group, $from)
    {
        $endpoints = $ring_group->members
            ->filter(fn($m) => $m->extension->status === 'active')
            ->filter(fn($m) => $m->extension->user?->status === 'active')
            ->map(fn($m) => $this->getEndpoint($m->extension))
            ->values()
            ->all();

        if (empty($endpoints)) {
            return CxmlResponse::unavailable(
                "All agents are unavailable."
            );
        }

        return CxmlResponse::dial($endpoints, [
            'timeout' => $ring_group->ring_timeout,
            'callerId' => $from
        ])->withFallback(
            CxmlResponse::say("All agents are busy. Goodbye.")
                ->hangup()
        );
    }

    protected function roundRobinRing($ring_group, $from, $call_sid)
    {
        // Get last dialed member position from Redis
        $last_position = Redis::get("ring_group:last_dialed:{$ring_group->id}") ?? -1;

        // Get next member
        $members = $ring_group->members
            ->filter(fn($m) => $m->extension->status === 'active')
            ->sortBy('position')
            ->values();

        $next_index = ($last_position + 1) % $members->count();
        $next_member = $members[$next_index];

        // Update Redis
        Redis::setex(
            "ring_group:last_dialed:{$ring_group->id}",
            3600,
            $next_index
        );

        // Build callback URL for next attempt
        $callback_url = route('voice.ring-group-callback', [
            'group_id' => $ring_group->id,
            'call_sid' => $call_sid,
            'attempt' => 1
        ]);

        return CxmlResponse::dial(
            [$this->getEndpoint($next_member->extension)],
            [
                'timeout' => $ring_group->ring_timeout,
                'callerId' => $from,
                'action' => $callback_url
            ]
        );
    }
}
```

**CxmlResponse Builder:**
```php
class CxmlResponse
{
    protected $verbs = [];

    public static function dial(array $endpoints, array $options = [])
    {
        $instance = new self();
        $instance->addDial($endpoints, $options);
        return $instance;
    }

    public static function say(string $text, array $options = [])
    {
        $instance = new self();
        $instance->addSay($text, $options);
        return $instance;
    }

    public function hangup()
    {
        $this->verbs[] = '<Hangup/>';
        return $this;
    }

    public function toXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<Response>' . PHP_EOL;
        foreach ($this->verbs as $verb) {
            $xml .= '  ' . $verb . PHP_EOL;
        }
        $xml .= '</Response>';
        return $xml;
    }

    public function toResponse(): Response
    {
        return response($this->toXml(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8'
        ]);
    }
}
```

---

## 8. Error Handling & Resilience

### 8.1 Error Scenarios

| Scenario | Response Strategy | CXML Output |
|----------|------------------|-------------|
| DID not found | HTTP 404 with error CXML | `<Say>Number not in service</Say><Hangup/>` |
| Database connection failure | Fallback to default extension | `<Dial>sip:operator@domain</Dial>` |
| Redis cache miss | Query MySQL directly | Normal routing (slower) |
| Ring group all offline | Execute fallback action | Voicemail or announcement |
| IVR menu not found | Default announcement | `<Say>Invalid menu</Say><Hangup/>` |
| Timeout (>10s response) | Cloudonix disconnects call | N/A - avoid at all costs |

### 8.2 Logging & Observability

**Structured Logging:**
```php
Log::info('Inbound call routing', [
    'call_sid' => $call_sid,
    'from' => $from,
    'to' => $to,
    'organization_id' => $did->organization_id,
    'routing_type' => $did->routing_type,
    'business_hours_open' => $is_open,
    'response_time_ms' => $elapsed_ms
]);
```

**Key Metrics to Track:**
- Average response time (target: <500ms)
- Cache hit rate (target: >95%)
- Error rate by type
- Calls per minute per tenant
- Ring group fallback rate

**Database Call Logging:**
After routing, asynchronously create call log record:
```php
CallLog::create([
    'organization_id' => $organization_id,
    'call_sid' => $call_sid,
    'from' => $from,
    'to' => $to,
    'routing_type' => $routing_type,
    'routing_destination_id' => $destination_id,
    'business_hours_status' => $is_open ? 'open' : 'closed',
    'started_at' => now(),
    'status' => 'initiated'
]);
```

---

## 9. Security Considerations

### 9.1 Webhook Authentication

**Bearer Token Validation:**
```php
// Middleware: CloudonixWebhookAuth
public function handle($request, Closure $next)
{
    $token = $request->bearerToken();
    $domain = $request->header('X-CX-Domain');

    // Validate token against configured secret
    if (!$this->validateToken($token, $domain)) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    return $next($request);
}
```

**Configuration:**
```env
CLOUDONIX_WEBHOOK_SECRET=your-secure-webhook-secret
```

### 9.2 Tenant Isolation

**Strict Scoping:**
- All database queries MUST include `organization_id` filter
- Never trust `To` parameter alone - always verify against `did_numbers` table
- Prevent cross-tenant data leakage via call state Redis keys

**Example:**
```php
// WRONG - vulnerable to enumeration
$extension = Extension::find($extension_id);

// CORRECT - tenant-scoped
$extension = Extension::where('organization_id', $organization_id)
    ->find($extension_id);
```

---

## 10. Performance Optimization

### 10.1 Target Metrics

| Metric | Target | Critical Threshold |
|--------|--------|-------------------|
| Response Time (p50) | <300ms | <1000ms |
| Response Time (p95) | <800ms | <2000ms |
| Cache Hit Rate | >95% | >80% |
| Concurrent Calls | 100+ | N/A |
| Database Connection Pool | 20 connections | N/A |

### 10.2 Optimization Strategies

**1. Redis Caching:**
- Pre-load hot DIDs during deployment
- Use Redis pipeline for multi-key fetches
- Set appropriate TTLs (15-60 minutes)

**2. Database Indexing:**
```sql
CREATE INDEX idx_did_phone_org ON did_numbers(phone_number, organization_id);
CREATE INDEX idx_extension_org_number ON extensions(organization_id, extension_number);
CREATE INDEX idx_ring_group_members ON ring_group_members(ring_group_id, extension_id);
```

**3. Query Optimization:**
- Use eager loading (`with()`) to prevent N+1 queries
- Cache business hours rules (they rarely change)
- Avoid complex joins in hot path

**4. Connection Pooling:**
- PHP-FPM workers: 20-50 per container
- MySQL connection pool: 20 persistent connections
- Redis: 10 connections per worker

---

## 11. Testing Strategy

### 11.1 Unit Tests

**Test Coverage:**
- Business hours evaluation (all edge cases)
- Ring group strategy logic
- CXML generation
- Cache hit/miss scenarios

**Example Test:**
```php
public function test_simultaneous_ring_group_routes_to_all_active_members()
{
    $ringGroup = RingGroup::factory()
        ->has(RingGroupMember::factory()->count(3), 'members')
        ->create(['strategy' => 'simultaneous']);

    $response = $this->ringGroupService->route(
        $ringGroup->id,
        '+14155551234',
        'test-call-sid'
    );

    $xml = $response->toXml();

    $this->assertStringContainsString('<Dial', $xml);
    $this->assertStringContainsString('<Number>sip:ext', $xml);
    $this->assertEquals(3, substr_count($xml, '<Number>'));
}
```

### 11.2 Integration Tests

**Webhook Simulation:**
```php
public function test_inbound_call_routes_to_extension_during_business_hours()
{
    $did = DIDNumber::factory()->create([
        'phone_number' => '+18005551000',
        'routing_type' => 'extension'
    ]);

    $response = $this->postJson('/api/voice/route', [
        'From' => '+14155551234',
        'To' => '+18005551000',
        'CallSid' => 'CAtest123',
        'Domain' => 'tenant.cloudonix.io'
    ]);

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $this->assertStringContainsString('<Dial', $response->content());
}
```

### 11.3 Load Testing

**Artillery.io Script:**
```yaml
config:
  target: 'https://opbx.example.com'
  phases:
    - duration: 60
      arrivalRate: 10
      name: "Warm up"
    - duration: 300
      arrivalRate: 50
      name: "Sustained load"
scenarios:
  - name: "Inbound call routing"
    flow:
      - post:
          url: "/api/voice/route"
          headers:
            Content-Type: "application/x-www-form-urlencoded"
            Authorization: "Bearer {{ $env.WEBHOOK_TOKEN }}"
          form:
            From: "+1415555{{ $randomNumber(1000, 9999) }}"
            To: "+18005551000"
            CallSid: "CA{{ $randomString(32) }}"
            Domain: "tenant.cloudonix.io"
```

**Target:** 50 requests/second with p95 response time <800ms

---

## 12. Deployment & Operations

### 12.1 Docker Configuration

**Dockerfile:**
```dockerfile
FROM php:8.4-fpm-alpine

# Install extensions
RUN apk add --no-cache \
    mysql-client \
    redis \
    && docker-php-ext-install pdo_mysql opcache

# Copy application
COPY . /var/www/html

# Optimize autoloader
RUN composer install --no-dev --optimize-autoloader

CMD ["php-fpm"]
```

**docker-compose.yml:**
```yaml
services:
  routing-app:
    build: .
    environment:
      - APP_ENV=production
      - CACHE_DRIVER=redis
      - REDIS_HOST=redis
      - DB_HOST=mysql
    deploy:
      replicas: 3
      resources:
        limits:
          cpus: '1'
          memory: 512M
```

### 12.2 Environment Configuration

**Production .env:**
```env
APP_NAME="OPBX Core Routing"
APP_ENV=production
APP_DEBUG=false

DB_CONNECTION=mysql
DB_HOST=mysql
DB_DATABASE=opbx
DB_USERNAME=opbx_routing
DB_PASSWORD=secure-password

CACHE_DRIVER=redis
REDIS_HOST=redis
REDIS_PASSWORD=redis-password
REDIS_DB=0

CLOUDONIX_WEBHOOK_SECRET=your-webhook-secret
CLOUDONIX_API_TOKEN=your-api-token

LOG_CHANNEL=stack
LOG_LEVEL=info
```

### 12.3 Health Checks

**Health Endpoint:**
```php
Route::get('/health', function() {
    $checks = [
        'database' => DB::connection()->getPdo() !== null,
        'redis' => Redis::ping(),
        'timestamp' => now()->toIso8601String()
    ];

    $healthy = $checks['database'] && $checks['redis'];

    return response()->json($checks, $healthy ? 200 : 503);
});
```

**Kubernetes Liveness Probe:**
```yaml
livenessProbe:
  httpGet:
    path: /health
    port: 80
  initialDelaySeconds: 30
  periodSeconds: 10
```

---

## 13. Future Enhancements

### 13.1 Planned Features

1. **Advanced Call Queuing**
   - Hold music with periodic announcements
   - Position in queue announcements
   - Estimated wait time
   - Callback option

2. **Call Recording**
   - Automatic recording of all/selected calls
   - Recording storage in S3
   - Playback via API

3. **Real-Time Analytics**
   - Live dashboard of active calls
   - Ring group performance metrics
   - Agent availability tracking

4. **Voicemail Transcription**
   - AI-powered transcription
   - Email notification with transcript
   - SMS notification

5. **Call Screening**
   - Caller announces name
   - Recipient chooses to accept/reject
   - Spam detection integration

6. **Multi-Level IVR**
   - Nested menus (up to 5 levels)
   - Speech recognition input
   - Context-aware routing

---

## 14. References & Resources

### 14.1 Cloudonix Documentation

- **Voice Applications**: https://developers.cloudonix.com/Documentation/voiceApplication
- **CXML Verbs Reference**: https://developers.cloudonix.com/Documentation/voiceApplication/Verb
- **Dial Verb**: https://developers.cloudonix.com/Documentation/voiceApplication/Verb/dial
- **Connect Verb**: https://developers.cloudonix.com/Documentation/voiceApplication/Verb/connect
- **Say Verb**: https://developers.cloudonix.com/Documentation/voiceApplication/Verb/say
- **Play Verb**: https://developers.cloudonix.com/Documentation/voiceApplication/Verb/play
- **Gather Verb**: https://developers.cloudonix.com/Documentation/voiceApplication/Verb/gather
- **Hangup Verb**: https://developers.cloudonix.com/Documentation/voiceApplication/Verb/hangup
- **Stream (WebSocket)**: https://developers.cloudonix.com/Documentation/voiceApplication/Verb/connect/stream
- **Warm Transfer Example**: https://developers.cloudonix.com/articles/callRouting/retellAndFreePBX/warmTransfer
- **Cold Transfer Example**: https://developers.cloudonix.com/articles/callRouting/retellAndFreePBX/coldTransfer

### 14.2 Key Concepts

- **DNID (Dialed Number Identification)**: The phone number that was dialed, received as the `To` parameter
- **CXML (Cloudonix Markup Language)**: XML-based language for call flow instructions, compatible with but not identical to TwiML
- **Voice Application**: A webhook endpoint that receives call events and responds with CXML instructions
- **Session**: A unique identifier for each call provided in the `Session` parameter
- **Tenant Isolation**: All routing decisions must be scoped to the organization that owns the DNID

---

## 15. Conclusion

The Core Routing Application is the operational heart of the OPBX system, transforming static configuration into dynamic call routing in real-time. Its design prioritizes:

1. **Performance**: Sub-second response times via aggressive caching
2. **Reliability**: Graceful fallbacks for all failure scenarios
3. **Security**: Strict tenant isolation and webhook authentication
4. **Scalability**: Stateless design supporting 100+ concurrent calls
5. **Maintainability**: Clean service layer architecture with comprehensive tests

By leveraging Cloudonix's powerful CXML language and voice application framework, the Core Routing Application provides enterprise-grade PBX functionality while remaining simple to deploy and operate.

**Success Criteria:**
- ✅ Route 99.9% of inbound calls successfully
- ✅ Respond within 500ms for 95% of requests
- ✅ Support 100+ concurrent calls per tenant
- ✅ Zero cross-tenant data leakage
- ✅ Maintain 99.9% uptime SLA

This specification serves as the authoritative guide for implementing the Core Routing Application and should be referenced throughout the development lifecycle.

---

**Document Version**: 1.0
**Last Updated**: 2025-12-28
**Author**: OPBX Architecture Team
**Status**: Draft for Review
