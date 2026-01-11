# Call Flows and State Machine

## Overview

OpBX implements a comprehensive call routing system that handles inbound calls through Cloudonix webhooks, processes them through a finite state machine, and provides real-time updates to the UI. This document outlines the call flow diagrams and state management logic.

## Call Lifecycle State Machine

### Call Status Enum

```php
enum CallStatus: string
{
    case INITIATED = 'initiated';    // Initial webhook received
    case RINGING = 'ringing';        // Call sent to destination(s)
    case ANSWERED = 'answered';      // Call answered by extension
    case COMPLETED = 'completed';    // Call ended successfully
    case BUSY = 'busy';             // Destination busy
    case NO_ANSWER = 'no_answer';    // No answer within timeout
    case FAILED = 'failed';         // Call failed (technical error)
}
```

### State Transition Diagram

```mermaid
stateDiagram-v2
    [*] --> INITIATED : Inbound webhook
    INITIATED --> RINGING : CXML sent to Cloudonix
    INITIATED --> FAILED : Invalid routing
    INITIATED --> BUSY : Immediate busy

    RINGING --> ANSWERED : Extension answers
    RINGING --> NO_ANSWER : Timeout expires
    RINGING --> BUSY : All destinations busy
    RINGING --> FAILED : Technical failure

    ANSWERED --> COMPLETED : Call ends normally
    ANSWERED --> FAILED : Connection lost

    COMPLETED --> [*] : Terminal state
    FAILED --> [*] : Terminal state
    BUSY --> [*] : Terminal state
    NO_ANSWER --> [*] : Terminal state
```

### State Transition Rules

| From State | To State | Conditions | Actions |
|------------|----------|------------|---------|
| INITIATED | RINGING | Valid routing found | Send CXML to Cloudonix |
| INITIATED | FAILED | Invalid DID/extension | Send error CXML |
| INITIATED | BUSY | Immediate busy response | Send busy CXML |
| RINGING | ANSWERED | Status webhook: answered | Update call log |
| RINGING | NO_ANSWER | Timeout reached | Process no-answer logic |
| RINGING | BUSY | All destinations busy | Send busy CXML |
| RINGING | FAILED | Technical error | Log error |
| ANSWERED | COMPLETED | Normal hangup | Calculate duration |
| ANSWERED | FAILED | Connection lost | Mark as failed |

## Inbound Call Flow

### Complete Inbound Call Sequence

```mermaid
sequenceDiagram
    participant Caller
    participant Cloudonix
    participant OpBX
    participant Redis
    participant MySQL
    participant UI

    Note over Caller,UI: Inbound Call Processing

    Caller->>Cloudonix: Phone Call
    Cloudonix->>OpBX: POST /webhooks/cloudonix/call-initiated
    OpBX->>OpBX: VerifyWebhookAuth (HMAC-SHA256)

    OpBX->>Redis: Lock call:{call_id}
    OpBX->>Redis: Check idempotency key

    OpBX->>MySQL: INSERT call_logs (INITIATED)
    OpBX->>Redis: Cache call state

    OpBX->>OpBX: Determine routing (DID → extension/ring_group/business_hours)
    OpBX->>Redis: Cache routing decision

    OpBX->>Cloudonix: POST /voice/route (CXML response)
    Cloudonix->>OpBX: 200 OK

    OpBX->>Redis: Update state to RINGING
    OpBX->>Redis: Broadcast call.initiated event
    Redis->>UI: Real-time notification

    Cloudonix->>OpBX: POST /webhooks/cloudonix/call-status (ringing)
    OpBX->>Redis: Update state
    OpBX->>Redis: Broadcast call.status event

    Cloudonix->>OpBX: POST /webhooks/cloudonix/call-status (answered)
    OpBX->>MySQL: UPDATE call_logs (answered_at)
    OpBX->>Redis: Update state to ANSWERED
    OpBX->>Redis: Broadcast call.answered event
    Redis->>UI: Update UI

    Cloudonix->>OpBX: POST /webhooks/cloudonix/cdr
    OpBX->>MySQL: UPDATE call_logs (COMPLETED, duration)
    OpBX->>Redis: Update final state
    OpBX->>Redis: Broadcast call.ended event
    Redis->>UI: Final update
```

### Routing Decision Flow

```mermaid
flowchart TD
    A[Inbound Call Webhook] --> B{DID exists?}
    B -->|No| C[Error: Invalid DID]
    B -->|Yes| D[Get DID routing_config]

    D --> E{Routing Type?}
    E -->|extension| F[Direct Extension]
    E -->|ring_group| G[Ring Group Logic]
    E -->|business_hours| H[Business Hours Check]
    E -->|ivr| I[IVR Menu]
    E -->|voicemail| J[Voicemail]

    F --> K[Generate CXML: Dial Extension]
    G --> L[Ring Group Strategy]
    H --> M[Time-based Routing]
    I --> N[IVR Gather]
    J --> O[Voicemail CXML]

    L --> P{Strategy?}
    P -->|simultaneous| Q[Dial all members]
    P -->|round_robin| R[Next member in sequence]
    P -->|sequential| S[Try members in priority order]

    M --> T{Current time in business hours?}
    T -->|Yes| U[Business hours destination]
    T -->|No| V[After hours destination]

    K --> W[Send CXML Response]
    Q --> W
    R --> W
    S --> W
    U --> W
    V --> W
    N --> W
    O --> W
```

## Routing Strategy Details

### Direct Extension Routing

```mermaid
flowchart TD
    A[Direct Extension] --> B{Extension active?}
    B -->|No| C[Error: Extension inactive]
    B -->|Yes| D{User assigned?}
    D -->|No| E[Conference/IVR/Custom logic]
    D -->|Yes| F{User available?}
    F -->|No| G[Send to voicemail]
    F -->|Yes| H[Generate SIP URI]
    H --> I[Dial sip:{ext}@{domain}]
```

### Ring Group Routing

#### Simultaneous Strategy
```mermaid
flowchart TD
    A[Simultaneous Ring] --> B[Get all active members]
    B --> C{Timeout configured?}
    C -->|Yes| D[Use configured timeout]
    C -->|No| E[Default 30 seconds]
    E --> F[Generate CXML with all SIP URIs]
    D --> F
    F --> G[Send to Cloudonix]
```

#### Round Robin Strategy
```mermaid
flowchart TD
    A[Round Robin] --> B[Get last successful member]
    B --> C[Find next member in sequence]
    C --> D{Member active?}
    D -->|No| E[Skip to next]
    D -->|Yes| F[Use this member]
    E --> C
    F --> G[Update last_successful_member]
    G --> H[Generate single SIP dial]
```

#### Sequential Strategy
```mermaid
flowchart TD
    A[Sequential Ring] --> B[Sort members by priority]
    B --> C[Start with highest priority]
    C --> D{Dial member}
    D --> E{Answered?}
    E -->|Yes| F[Success]
    E -->|No| G{More members?}
    G -->|Yes| H[Try next member]
    G -->|No| I{Fallback configured?}
    I -->|Yes| J[Use fallback extension]
    I -->|No| K[No answer]
    H --> D
    J --> D
```

### Business Hours Routing

```mermaid
flowchart TD
    A[Business Hours Check] --> B[Get current time]
    B --> C[Convert to schedule timezone]
    C --> D{Is holiday?}
    D -->|Yes| E[Holiday routing]
    D -->|No| F{Within business hours?}
    F -->|Yes| G[Business hours routing]
    F -->|No| H[After hours routing]

    G --> I{Destination type?}
    H --> I
    E --> I

    I -->|extension| J[Dial extension]
    I -->|ring_group| K[Ring group logic]
    I -->|ivr| L[IVR menu]
    I -->|voicemail| M[Voicemail]
```

### IVR Menu Processing

```mermaid
flowchart TD
    A[IVR Input Received] --> B{Valid digit?}
    B -->|No| C[Play invalid input message]
    B -->|Yes| D[Find matching option]
    D --> E{Option exists?}
    E -->|No| F[Play invalid option message]
    E -->|Yes| G{Action type?}
    G -->|extension| H[Dial extension]
    G -->|ring_group| I[Ring group logic]
    G -->|submenu| J[Load submenu]
    G -->|external| K[Dial external number]
    F --> L[Retry or timeout]
    C --> L
    L --> M{Max attempts reached?}
    M -->|No| N[Replay menu]
    M -->|Yes| O[Use timeout action]
```

## Error Handling Flows

### Authentication Failure

```mermaid
flowchart TD
    A[Webhook Received] --> B{Valid signature/token?}
    B -->|No| C[Log security event]
    C --> D{Webhook type?}
    D -->|Voice| E[Return error CXML]
    D -->|Status| F[Return JSON error]
    E --> G[Hangup call]
    F --> H[Webhook retry]
```

### Routing Failure

```mermaid
flowchart TD
    A[Routing Request] --> B{DID exists?}
    B -->|No| C[Log invalid DID]
    C --> D[Return error CXML]

    B -->|Yes| E{Routing config valid?}
    E -->|No| F[Log configuration error]
    F --> D

    E -->|Yes| G{Target exists & active?}
    G -->|No| H[Log target unavailable]
    H --> I{Use fallback?}
    I -->|Yes| J[Try fallback routing]
    I -->|No| K[Return unavailable CXML]
    J --> G

    G -->|Yes| L[Generate successful CXML]
```

## Real-Time Update Flow

### WebSocket Event Broadcasting

```mermaid
sequenceDiagram
    participant Webhook
    participant Job
    participant Redis
    participant Soketi
    participant React

    Webhook->>Job: Process webhook
    Job->>Redis: Update call state
    Job->>Redis: PUBLISH call.event
    Redis->>Soketi: Forward event
    Soketi->>React: WebSocket message
    React->>React: Update UI state
```

### Event Types and Payloads

```typescript
// Call initiated
{
    event: 'call.initiated',
    data: {
        call_id: string;
        from_number: string;
        to_number: string;
        did_id: number | null;
        status: 'initiated';
        initiated_at: string;
    }
}

// Call answered
{
    event: 'call.answered',
    data: {
        call_id: string;
        status: 'answered';
        answered_at: string;
        extension_id: number;
    }
}

// Call ended
{
    event: 'call.ended',
    data: {
        call_id: string;
        status: 'completed' | 'failed' | 'busy' | 'no_answer';
        ended_at: string;
        duration: number;
    }
}
```

## Performance Optimization

### Caching Strategy

```mermaid
flowchart TD
    A[Inbound Call] --> B{Cache hit?}
    B -->|Yes| C[Use cached routing]
    B -->|No| D[Query database]
    D --> E[Generate routing decision]
    E --> F[Cache result with TTL]
    F --> C
    C --> G[Generate CXML]
    G --> H[Update cache stats]
```

### Cache Invalidation

- **Model observers** automatically clear routing cache when configurations change
- **Time-based expiration** ensures cache freshness
- **Broadcast events** trigger UI cache invalidation

## Failure Scenarios

### Webhook Retry Handling

```mermaid
flowchart TD
    A[Webhook Failed] --> B{Retry count < max?}
    B -->|Yes| C[Wait exponential backoff]
    C --> D[Retry webhook]
    D --> E{Success?}
    E -->|Yes| F[Process normally]
    E -->|No| B
    B -->|No| G[Log permanent failure]
    G --> H{Webhook type?}
    H -->|Status| I[Call may continue]
    H -->|Voice| J[Call likely failed]
```

### Circuit Breaker Pattern

```mermaid
stateDiagram-v2
    [*] --> CLOSED : Normal operation
    CLOSED --> OPEN : Failure threshold reached
    OPEN --> HALF_OPEN : Timeout period passed
    HALF_OPEN --> CLOSED : Success
    HALF_OPEN --> OPEN : Failure
```

## Monitoring and Observability

### Key Metrics

- **Call completion rate**: Successful calls / total calls
- **Average answer time**: Time from ring to answer
- **Routing cache hit rate**: Cache hits / total lookups
- **Webhook processing latency**: Time to process webhooks
- **State transition errors**: Failed state changes

### Logging

- **Call correlation**: All logs include `call_id` for tracing
- **Security events**: Authentication failures, invalid signatures
- **Performance metrics**: Cache hits, database query times
- **Error tracking**: Failed routing attempts, webhook errors

This comprehensive call flow system ensures reliable, high-performance call processing with real-time updates, proper error handling, and extensive monitoring capabilities.