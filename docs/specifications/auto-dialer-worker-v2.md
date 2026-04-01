# Auto Dialer Worker - Technical Specification v2.0

## 1. Overview

The Auto Dialer Worker is a Go-based service that executes outbound call campaigns by consuming configurations from the Laravel backend via REST API and initiating calls through the Cloudonix API.

### Key Characteristics
- **Language**: Go (Golang) 1.23+
- **Architecture**: Event-driven worker with polling-based campaign scheduling
- **Scaling**: Horizontal scaling via multiple worker instances (coordinated via Redis)
- **Database Access**: NONE - Only via Laravel REST API
- **Telephony Provider**: Cloudonix
- **State Storage**: Redis (shared across worker instances)

---

## 2. System Architecture

### 2.1 Component Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              Docker Stack                                   │
│                                                                             │
│  ┌────────────────────┐    ┌────────────────────┐    ┌──────────────────┐   │
│  │     Laravel        │    │     Go Worker      │    │    Cloudonix     │   │
│  │    Backend         │◄──►│   (Dialer)         │◄──►│      API         │   │
│  │  (API/MySQL)       │    │                    │    │                  │   │
│  └────────────────────┘    └─────────┬──────────┘    └──────────────────┘   │
│           ▲                          │                                      │
│           │                          │                                      │
│           │                 ┌────────▼────────┐                            │
│           │                 │     Redis       │                            │
│           │                 │   (State/Cache) │                            │
│           │                 └─────────────────┘                            │
│           │                                                                │
│           │        CDR Webhooks                                            │
│           └────────────(POST)──────────────────────────────────────────────┘
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Worker Components

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Go Dialer Worker                                     │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    Campaign Manager                                 │    │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────────┐  │    │
│  │  │   Campaign   │  │  Distribution│  │      Retry Scheduler     │  │    │
│  │  │   Fetcher    │  │    Lists     │  │   (Incremental Backoff)  │  │    │
│  │  └──────────────┘  └──────────────┘  └──────────────────────────┘  │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                        │
│                                    ▼                                        │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    Call Executor                                    │    │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────────┐  │    │
│  │  │   CAC Rate   │  │   Cloudonix  │  │    Destination Update    │  │    │
│  │  │   Limiter    │──►   API Call   │──►     (Status/Retry)       │  │    │
│  │  └──────────────┘  └──────────────┘  └──────────────────────────┘  │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                        │
│                                    ▼                                        │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                    CDR Handler (Webhook Receiver)                   │    │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────────┐  │    │
│  │  │    Parse     │  │   Update     │  │    Update Laravel API    │  │    │
│  │  │    CDR       │──►  Concurrency │──►  (Destination Status)    │  │    │
│  │  └──────────────┘  └──────────────┘  └──────────────────────────┘  │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Core Concepts

### 3.1 Concurrent Active Calls (CAC)

The CAC (Concurrent Active Calls) determines how many calls can be active (ringing or connected) simultaneously for a campaign.

| CAC | API Interval | Max Concurrent Calls |
|-----|--------------|---------------------|
| 2   | 30 seconds   | 2                   |
| 3   | 20 seconds   | 3                   |
| 4   | 15 seconds   | 4                   |
| 6   | 10 seconds   | 6                   |
| 10  | 6 seconds    | 10                  |
| 15  | 4 seconds    | 15                  |
| 20  | 3 seconds    | 20                  |

**API Interval Formula**: `60 / CAC = interval in seconds`

### 3.2 Campaign Lifecycle

```
┌─────────┐     ┌─────────┐     ┌─────────┐     ┌─────────┐
│  DRAFT  │────►│  READY  │────►│ RUNNING │────►│COMPLETED│
└─────────┘     └─────────┘     └────┬────┘     └─────────┘
                                     │
                                     ▼
                                ┌─────────┐
                                │  PAUSED │
                                └─────────┘
```

**Worker Transitions**:
- `READY` → `RUNNING`: When current time is within campaign schedule
- `RUNNING` → `PAUSED`: When HTTP 429 (rate limit) received from Cloudonix
- `RUNNING` → `COMPLETED`: When all distribution lists are fully processed
- `PAUSED` → `RUNNING`: After 300-second cooldown (internal worker decision)

---

## 4. Laravel REST API Endpoints

### 4.1 Worker Authentication

All worker API endpoints require Bearer token authentication:
```
Authorization: Bearer {DIALER_WORKER_API_TOKEN}
```

### 4.2 Campaign Endpoints

#### GET `/api/v1/dialer/worker/campaigns/active`
**Purpose**: Fetch all active campaigns that should be running

**Response Format**:
```json
{
  "data": [
    {
      "id": 1,
      "organization_id": 4,
      "name": "Campaign Name",
      "status": "active",
      "concurrent_active_calls": 5,
      "max_dial_attempts": 3,
      "dial_timeout": 30,
      "caller_id": "+1234567890",
      "timezone": "America/New_York",
      "start_date": "2026-03-01",
      "end_date": "2026-12-31",
      "schedule": {
        "monday": {
          "enabled": true,
          "time_ranges": [
            {"start_time": "09:00", "end_time": "17:00"}
          ]
        }
      },
      "routing_destination_type": "ai_assistant",
      "routing_destination_id": 5,
      "cloudonix_api_key": "XI...",
      "cloudonix_domain": "uuid-or-name",
      "cloudonix_api_url": "https://api.cloudonix.io"
    }
  ]
}
```

**Filtering Logic** (performed by Laravel):
- Status = 'active'
- Within date range (start_date <= today <= end_date)
- Within current schedule time

#### PATCH `/api/v1/dialer/worker/campaigns/{campaign}/pause`
**Purpose**: Pause campaign due to rate limiting or errors

**Request Body**:
```json
{
  "reason": "cloudonix_rate_limit",
  "paused_by": "dialer-worker-1",
  "resume_at": "2026-03-31T14:45:00Z"
}
```

### 4.3 Distribution Lists Endpoints

#### GET `/api/v1/auto-dialer-campaigns/{campaign}/lists`
**Purpose**: Get distribution lists assigned to a campaign

**Response Format**:
```json
{
  "data": [
    {
      "id": 10,
      "campaign_id": 1,
      "name": "List A",
      "status": "in_use",
      "total_rows": 100,
      "valid_rows": 95,
      "invalid_rows": 5
    }
  ]
}
```

**Note**: Worker should only process lists with status `in_use`.

### 4.4 Destination Endpoints

#### GET `/api/v1/dialer/worker/campaigns/{campaign}/destinations/pending`
**Purpose**: Get pending destinations ready to be dialed

**Query Parameters**:
- `limit` (int, default: 50): Maximum destinations to return
- `page` (int, default: 1): Page number for pagination

**Response Format**:
```json
{
  "data": [
    {
      "id": 100,
      "list_id": 10,
      "phone_number": "+14155551234",
      "description": "John Doe",
      "status": "pending",
      "dial_attempts": 0,
      "priority": 1
    }
  ],
  "meta": {
    "total": 95,
    "limit": 50,
    "offset": 0
  }
}
```

**Filtering** (performed by Laravel):
- Status = 'pending'
- dial_attempts < campaign.max_dial_attempts
- next_retry_at is null OR next_retry_at <= now()

#### GET `/api/v1/dialer/worker/campaigns/{campaign}/destinations/retry`
**Purpose**: Get destinations ready for retry

**Query Parameters**:
- `limit` (int, default: 50)

**Filtering** (performed by Laravel):
- Status = 'failed'
- next_retry_at <= now()
- dial_attempts < campaign.max_dial_attempts

### 4.5 Call Session Endpoints

#### POST `/api/v1/dialer/worker/calls/initiate`
**Purpose**: Create a call session record before dialing

**Request Body**:
```json
{
  "campaign_id": 1,
  "destination_id": 100,
  "phone_number": "+14155551234",
  "worker_id": "dialer-worker-1",
  "initiated_at": "2026-03-31T14:30:00Z"
}
```

**Response**:
```json
{
  "data": {
    "session_id": 12345,
    "callback_url": "https://webhook.example.com/api/webhooks/cloudonix/call-status"
  }
}
```

#### PATCH `/api/v1/dialer/worker/calls/{session}/status`
**Purpose**: Update call status during the call lifecycle

**Request Body**:
```json
{
  "status": "dialing|connected|failed|completed",
  "error": "optional error message"
}
```

#### POST `/api/v1/dialer/worker/calls/{session}/disposition`
**Purpose**: Set final disposition and handle retry logic

**Request Body**:
```json
{
  "disposition": "answered|busy|no-answer|failed",
  "duration": 45,
  "billsec": 42,
  "retry_after_minutes": 5
}
```

**Laravel Logic**:
- If disposition in ["busy", "no-answer", "failed"] AND dial_attempts < max:
  - Status → "pending"
  - next_retry_at = now() + retry_after_minutes
- If disposition = "answered" OR dial_attempts >= max:
  - Status → "completed" or "failed"

---

## 5. CDR Processing

### 5.1 CDR Flow

```
Cloudonix ──► Laravel CDR Endpoint ──► Update MySQL ──► Webhook to Worker
                        │                                    │
                        ▼                                    ▼
                 Store CDR Record                    Update Destination
```

### 5.2 CDR Webhook Endpoint

#### POST `/webhooks/auto-dialer/cdr`
**Purpose**: Receive CDR from Laravel after Cloudonix sends it

**Authentication**: Bearer token (same as dialer worker)

**Request Body**:
```json
{
  "call_id": "abc-123",
  "session_token": "xyz-789",
  "campaign_id": 1,
  "destination_id": 100,
  "session_id": 12345,
  "disposition": "answered",
  "duration": 45,
  "billsec": 42
}
```

**Worker Actions**:
1. Decrement Redis concurrency counter
2. Remove session from active sessions
3. Update destination status via Laravel API (if not already done)

---

## 6. Redis State Management

### 6.1 Key Structure

| Key Pattern | Type | Description |
|-------------|------|-------------|
| `campaign:{campaign_id}:concurrency_counter` | String (int) | Current active calls |
| `campaign:{campaign_id}:active_sessions` | Hash | Map of session_token → destination_id |
| `campaign:{campaign_id}:paused_until` | String (timestamp) | When to resume after 429 |
| `campaign:{campaign_id}:last_api_call` | String (timestamp) | Last Cloudonix API call time |

### 6.2 Concurrency Operations

#### Increment Counter (After successful API call)
```
INCR campaign:{id}:concurrency_counter
HSET campaign:{id}:active_sessions {session_token} {destination_id}
EXPIRE campaign:{id}:concurrency_counter 86400
EXPIRE campaign:{id}:active_sessions 86400
```

#### Decrement Counter (After CDR received)
```
DECR campaign:{id}:concurrency_counter
HDEL campaign:{id}:active_sessions {session_token}
```

#### Check Can Start Call
```
GET campaign:{id}:concurrency_counter → current_count
If current_count < CAC: return true
Else: return false
```

---

## 7. Call Execution Flow

### 7.1 Main Execution Loop

```
For each ACTIVE campaign:
    1. Check if within schedule (use Laravel's isRunnable logic)
    2. Check if campaign is internally paused (check Redis paused_until)
    3. Get CAC value and calculate API interval (60/CAC)
    4. Check Redis concurrency counter < CAC
    
    If all checks pass:
        a. Fetch pending destinations (paged, limit=CAC-current_count)
        b. For each destination:
            i. Wait for API interval since last call
            ii. Create session via Laravel API
            iii. Call Cloudonix API to initiate call
            iv. If success: Increment Redis counter, store session
            v. If HTTP 429: Pause campaign internally for 300s
            vi. If other error: Mark for retry
```

### 7.2 Retry Logic

**Retryable Dispositions**: busy, no-answer, failed

**Retry Intervals** (incremental backoff):
| Attempt | Interval |
|---------|----------|
| 1       | 5 minutes |
| 2       | 15 minutes |
| 3       | 60 minutes |
| 4+      | 24 hours |

**Implementation**: Store `next_retry_at` timestamp in Laravel (via API call), worker only fetches destinations where `next_retry_at <= now()`.

### 7.3 HTTP 429 Handling

When Cloudonix returns HTTP 429:
1. Log the error
2. Store `paused_until = now() + 300 seconds` in Redis
3. Stop making API calls for this campaign
4. After 300 seconds, resume normal operation

---

## 8. Environment Configuration

### 8.1 Required Environment Variables

```bash
# Worker Identity
WORKER_ID=dialer-worker-1
WORKER_API_PORT=8080

# Laravel API
LARAVEL_API_URL=http://nginx/api/v1
LARAVEL_API_TOKEN=your-worker-token-here

# Redis
REDIS_ADDR=redis:6379
REDIS_PASSWORD=optional-password
REDIS_DB=0

# Cloudonix
CLOUDONIX_API_URL=https://api.cloudonix.io

# Worker Settings
MAX_CONCURRENT_CALLS_GLOBAL=1000      # Global limit across all campaigns
DEFAULT_CALL_TIMEOUT=30
LOG_LEVEL=info

# Retry Configuration
RETRY_INTERVALS=5,15,60,1440          # Minutes: 5min, 15min, 60min, 24hr

# Rate Limiting
RATE_LIMIT_COOLDOWN=300               # Seconds to pause after HTTP 429
```

### 8.2 Per-Campaign Settings (from Laravel API)

- `concurrent_active_calls` (CAC)
- `max_dial_attempts`
- `dial_timeout`
- `caller_id`
- `routing_destination_type`
- `routing_destination_id`
- `cloudonix_api_key`
- `cloudonix_domain`

---

## 9. Worker Startup Sequence

```
1. Load environment variables
2. Connect to Redis
3. Test Laravel API connectivity (health check)
4. Start CDR webhook receiver (HTTP server)
5. Start campaign manager loop:
   a. Every 60 seconds: Fetch active campaigns from Laravel
   b. For each campaign: Spawn goroutine for execution
6. Each campaign goroutine:
   a. Check schedule (isRunnable logic)
   b. Check internal pause state (Redis)
   c. Manage CAC rate limiting
   d. Fetch and dial destinations
   e. Handle responses and retries
```

---

## 10. Error Handling & Logging

### 10.1 Structured Logging Format

All logs must include:
- `worker_id`: Worker identifier
- `campaign_id`: Campaign being processed
- `destination_id`: Destination being called
- `session_token`: Cloudonix session identifier
- `error`: Error message if applicable

### 10.2 Critical Errors

| Error | Action |
|-------|--------|
| Laravel API unreachable | Retry with backoff, log error |
| Redis unreachable | Exit with error (required for operation) |
| Cloudonix HTTP 429 | Pause campaign for 300s |
| Cloudonix HTTP 5xx | Retry destination with backoff |
| Invalid campaign config | Skip campaign, log error |

---

## 11. Implementation Checklist

### Phase 1: Foundation
- [ ] HTTP client for Laravel API
- [ ] HTTP client for Cloudonix API
- [ ] Redis client and connection management
- [ ] Configuration loading

### Phase 2: Campaign Management
- [ ] Fetch active campaigns endpoint
- [ ] Schedule checking (isRunnable logic)
- [ ] CAC rate limiting
- [ ] Campaign state tracking in Redis

### Phase 3: Call Execution
- [ ] Fetch pending destinations
- [ ] Create call sessions
- [ ] Call Cloudonix API
- [ ] Handle responses (success/failure/429)
- [ ] Update Redis concurrency counters

### Phase 4: CDR Processing
- [ ] Webhook receiver for CDRs
- [ ] Parse CDR and update counters
- [ ] Update destination status via Laravel API

### Phase 5: Retry Logic
- [ ] Retryable disposition detection
- [ ] Incremental backoff calculation
- [ ] Retry destination fetching

### Phase 6: Observability
- [ ] Structured logging
- [ ] Health check endpoint
- [ ] Metrics (Prometheus)

---

## 12. API Endpoint Summary

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/v1/dialer/worker/campaigns/active` | GET | Fetch active campaigns |
| `/api/v1/dialer/worker/campaigns/{id}/pause` | PATCH | Pause campaign |
| `/api/v1/auto-dialer-campaigns/{id}/lists` | GET | Get distribution lists |
| `/api/v1/dialer/worker/campaigns/{id}/destinations/pending` | GET | Get pending destinations |
| `/api/v1/dialer/worker/campaigns/{id}/destinations/retry` | GET | Get retry destinations |
| `/api/v1/dialer/worker/calls/initiate` | POST | Create call session |
| `/api/v1/dialer/worker/calls/{session}/status` | PATCH | Update call status |
| `/api/v1/dialer/worker/calls/{session}/disposition` | POST | Set final disposition |
| `/webhooks/auto-dialer/cdr` | POST | Receive CDR webhook |

---

## 13. Go Package Structure

```
dialer-worker/
├── cmd/
│   └── worker/
│       └── main.go              # Entry point
├── internal/
│   ├── api/
│   │   └── client.go            # Laravel API client
│   ├── cloudonix/
│   │   └── client.go            # Cloudonix API client
│   ├── campaign/
│   │   ├── manager.go           # Campaign lifecycle management
│   │   └── scheduler.go         # Schedule checking
│   ├── executor/
│   │   └── executor.go          # Call execution logic
│   ├── cdr/
│   │   └── handler.go           # CDR webhook handler
│   ├── concurrency/
│   │   └── manager.go           # Redis CAC management
│   ├── retry/
│   │   └── scheduler.go         # Retry logic
│   └── config/
│       └── config.go            # Configuration
├── pkg/
│   └── models/
│       └── models.go            # Data structures
└── tests/
    └── integration/
        └── worker_test.go       # Integration tests
```

---

**Version**: 2.0  
**Last Updated**: 2026-04-01  
**Author**: AI Assistant
