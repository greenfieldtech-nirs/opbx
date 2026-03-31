# Auto Dialer Worker - Technical Specification

## 1. Overview

The Auto Dialer Worker is a Go-based service responsible for executing outbound call campaigns using the Cloudonix CPaaS platform. It operates as a separate container in the Docker stack, consuming campaign configurations from the Laravel backend via REST API and initiating calls through the Cloudonix API.

### Key Characteristics
- **Language**: Go (Golang) 1.21+
- **Architecture**: Event-driven with internal scheduler
- **Scaling**: Horizontal scaling via multiple worker instances
- **Database Access**: NONE - Only via Laravel REST API
- **Telephony Provider**: Cloudonix
- **State Storage**: Redis (shared across worker instances)

---

## 2. System Architecture

### 2.1 Component Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                        Docker Stack                             │
│                                                                 │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────────┐  │
│  │   Laravel    │    │     Go       │    │   Cloudonix      │  │
│  │   Backend    │◄──►│   Worker     │◄──►│     API          │  │
│  │  (API/CRD)   │    │  (Dialer)    │    │                  │  │
│  └──────────────┘    └──────┬───────┘    └──────────────────┘  │
│         ▲                   │                                   │
│         │                   │                                   │
│         │            ┌──────▼──────┐                           │
│         └────────────┤    Redis    │                           │
│                      │   (State)   │                           │
│                      └─────────────┘                           │
│                                                                 │
│  CDR Flow: Cloudonix ──► Laravel CDR Endpoint                  │
│            (Async webhook with session token)                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 Worker Components

```
┌──────────────────────────────────────────────────────────────┐
│                    Go Dialer Worker                          │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐   │
│  │   Campaign   │  │    Call      │  │   Retry Queue    │   │
│  │  Scheduler   │  │   Executor   │  │   (Deferred)     │   │
│  └──────────────┘  └──────────────┘  └──────────────────┘   │
│         │                 │                    │             │
│         ▼                 ▼                    ▼             │
│  ┌────────────────────────────────────────────────────────┐ │
│  │              Concurrency Manager (Redis)               │ │
│  │  - Campaign Concurrency Counters                      │ │
│  │  - Active Sessions Lists (per campaign)               │ │
│  │  - Rate Limiting (60/CAC seconds interval)            │ │
│  └────────────────────────────────────────────────────────┘ │
│                              │                               │
│  ┌───────────────────────────┴────────────────────────────┐ │
│  │                 HTTP Client Layer                      │ │
│  │   (Laravel API, Cloudonix API, CDR Webhook)           │ │
│  └────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

---

## 3. Core Concept: Concurrent Active Calls (CAC)

### 3.1 CAC vs CPS

The system uses **Concurrent Active Calls (CAC)** instead of Calls Per Second (CPS):

| Metric | Description | Control |
|--------|-------------|---------|
| **CAC** | Maximum number of active (in-progress) calls per campaign | Hard limit - never exceeded |
| **API Rate** | Time between Cloudonix API requests | Calculated as `60 / CAC` seconds |

### 3.2 CAC Values

Valid CAC values: **2, 3, 4, 6, 10, 15, 20**

| CAC | API Interval | Max Concurrent Calls |
|-----|--------------|---------------------|
| 2 | 30 seconds | 2 |
| 3 | 20 seconds | 3 |
| 4 | 15 seconds | 4 |
| 6 | 10 seconds | 6 |
| 10 | 6 seconds | 10 |
| 15 | 4 seconds | 15 |
| 20 | 3 seconds | 20 |

### 3.3 Concurrency Management

Each campaign maintains:

1. **Concurrency Counter**: Integer tracking current active calls
2. **Active Sessions List**: Set of Cloudonix session tokens for active calls

Stored in Redis with keys:
- `campaign:{campaign_id}:concurrency_counter` → integer
- `campaign:{campaign_id}:active_sessions` → set of session tokens

---

## 4. Go Worker Specification

### 4.1 Technology Stack

| Component | Technology | Purpose |
|-----------|------------|---------|
| Language | Go 1.21+ | Core implementation |
| HTTP Server | Standard `net/http` | Health checks, internal API |
| HTTP Client | `net/http` with custom retry | API calls to Laravel & Cloudonix |
| Redis Client | `github.com/redis/go-redis` | State storage (concurrency, sessions) |
| Scheduling | `github.com/go-co-op/gocron/v2` | Campaign scheduling |
| Metrics | `github.com/prometheus/client_golang` | Observability |
| Logging | `github.com/rs/zerolog` | Structured logging |

### 4.2 Configuration (Environment Variables)

```env
# Worker Identity
WORKER_ID=dialer-worker-1
WORKER_API_PORT=8080

# Laravel API
LARAVEL_API_URL=http://nginx/api/v1
LARAVEL_API_TOKEN=${DIALER_WORKER_API_TOKEN}
LARAVEL_POLL_INTERVAL=30s

# Cloudonix API
CLOUDONIX_API_URL=https://api.cloudonix.io

# Redis (for shared state)
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_DB=0

# Observability
METRICS_PORT=9090
LOG_LEVEL=info
```

---

## 5. API Endpoints (Laravel Backend)

### 5.1 Campaign Management Endpoints

#### `GET /api/v1/dialer/worker/campaigns/active`
Returns all campaigns that should be currently running (respecting schedule).

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "organization_id": 4,
      "name": "Campaign Name",
      "status": "active",
      "caller_id": "+1234567890",
      "concurrent_active_calls": 5,
      "max_dial_attempts": 3,
      "dial_timeout": 30,
      "time_limit": 3600,
      "record_calls": true,
      "amd_enabled": false,
      "routing_destination_type": "ai_assistant",
      "routing_destination_id": 5,
      "timezone": "America/New_York",
      "start_date": "2026-03-01",
      "end_date": "2026-12-31",
      "schedule": {
        "monday": {
          "enabled": true,
          "time_ranges": [
            {"id": "1", "start_time": "09:00", "end_time": "12:00"},
            {"id": "2", "start_time": "13:00", "end_time": "17:00"}
          ]
        }
      },
      "cloudonix_api_key": "XI...",
      "cloudonix_domain": "uuid-or-name",
      "cloudonix_api_url": "https://api.cloudonix.io"
    }
  ]
}
```

**Note:** `concurrent_active_calls` replaces the old `calls_per_second` field.

#### `GET /api/v1/dialer/worker/campaigns/{campaign}/destinations/pending`
Returns pending destinations for dialing.

**Response:** Same as before.

### 5.2 Call Session Management Endpoints

#### `POST /api/v1/dialer/worker/calls/initiate`
Called by worker when initiating a call.

**Request:**
```json
{
  "campaign_id": 1,
  "destination_id": 123,
  "phone_number": "+12025551234",
  "worker_id": "dialer-worker-1",
  "started_at": "2026-03-29T14:30:00Z"
}
```

**Response:**
```json
{
  "data": {
    "session_id": 12345,
    "callback_url": "https://webhook.example.com/api/webhooks/cloudonix/call-status"
  }
}
```

### 5.3 CXML Generation Endpoint

#### `POST /api/v1/dialer/worker/calls/generate-cxml`
Generates CXML for outbound call routing.

**Request:**
```json
{
  "campaign_id": 1,
  "session_id": 12345,
  "phone_number": "+12025551234",
  "call_sid": "call_1_123_1712345678"
}
```

**Response:**
```json
{
  "data": {
    "cxml": "<?xml version=\"1.0\"?><Response><Connect><Stream url=\"wss://...\"/></Connect></Response>",
    "routing_type": "ai_assistant"
  }
}
```

### 5.4 Campaign Control Endpoints

#### `POST /api/v1/dialer/worker/campaigns/{campaign}/pause`
Pause campaign immediately (used for rate limiting or errors).

**Request:**
```json
{
  "reason": "cloudonix_rate_limit",
  "paused_by": "dialer-worker-1",
  "resume_at": "2026-03-29T14:45:00Z"
}
```

### 5.5 CDR Webhook Endpoint (Laravel Receives)

#### `POST /api/webhooks/cloudonix/cdr`
Cloudonix sends CDR (Call Detail Record) when call completes.

**Request (from Cloudonix):**
```json
{
  "type": "call.completed",
  "call_id": "16a7294c989b11e7b3d32b9edb8660c7",
  "from": "+1234567890",
  "to": "+12025551234",
  "status": "completed",
  "disposition": "answered",
  "duration": 45,
  "billsec": 42,
  "custom_data": {
    "campaign_id": 1,
    "destination_id": 123,
    "session_id": 12345
  }
}
```

**Laravel Processing:**
1. Update call session with final status
2. Update destination status in distribution list
3. **Notify worker via Redis** to decrement concurrency counter
4. Remove session token from active sessions list

---

## 6. Core Workflows

### 6.1 Campaign Execution Flow

```
┌─────────────────────────────────────────────────────────────────┐
│              Campaign Execution Loop (60/CAC interval)          │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ▼
               ┌───────────────────────────────┐
               │  Check Campaign Schedule      │
               │  (Within active hours?)       │
               └───────────────────────────────┘
                               │
               ┌───────────────┴───────────────┐
               │ Active        │ Inactive      │
               ▼               ▼               ▼
       ┌───────────┐  ┌──────────────┐  ┌──────────────┐
       │ Check CAC │  │   Stop       │  │   Wait       │
       │ Counter   │  │   Campaign   │  │   60s        │
       │ < CAC?    │  │              │  │              │
       └─────┬─────┘  └──────────────┘  └──────────────┘
             │
     ┌───────┴───────┐
     │ Yes           │ No (at limit)
     ▼               ▼
┌─────────────┐  ┌──────────────────────────┐
│ Get Next    │  │ Wait 60/CAC seconds      │
│ Pending     │  │ (next cycle)             │
│ Destination │  │                          │
└──────┬──────┘  └──────────────────────────┘
       │
       ▼
┌──────────────────┐
│ Wait for API     │
│ Rate Limit       │
│ (60/CAC seconds) │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ Initiate Call    │
│ (Cloudonix API)  │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ Increment CAC    │
│ Add to Active    │
│ Sessions         │
└──────────────────┘
```

### 6.2 Call Initiation Flow (Detailed)

```
┌─────────────────────────────────────────────────────────────────┐
│                       Call Initiation                           │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ▼
               ┌───────────────────────────────┐
               │  Check CAC Counter            │
               │  (Redis: campaign:X:counter)  │
               └───────────────────────────────┘
                               │
                   ┌───────────┴───────────┐
                   │ < CAC                 │ >= CAC
                   ▼                       ▼
         ┌──────────────────┐    ┌──────────────────┐
         │ Wait for API     │    │ Skip This Cycle  │
         │ Interval         │    │ (Wait 60/CAC)    │
         │ (60/CAC seconds) │    │                  │
         └────────┬─────────┘    └──────────────────┘
                  │
                  ▼
         ┌──────────────────┐
         │ Create Laravel   │
         │ Session          │
         └────────┬─────────┘
                  │
                  ▼
         ┌──────────────────┐
         │ Generate CXML    │
         │ (Laravel API)    │
         └────────┬─────────┘
                  │
                  ▼
         ┌──────────────────┐
         │ Call Cloudonix   │
         │ InitiateCall API │
         └────────┬─────────┘
                  │
                  ▼
         ┌──────────────────┐
         │ HTTP 429?        │
         │ (Rate Limited)   │
         └────────┬─────────┘
                  │
       ┌──────────┴──────────┐
       │ Yes                 │ No
       ▼                     ▼
┌──────────────────┐  ┌──────────────────┐
│ PAUSE CAMPAIGN   │  │ Increment CAC    │
│ IMMEDIATELY      │  │ Counter (Redis)  │
│ (300s cooldown)  │  │                  │
└──────────────────┘  └────────┬─────────┘
                               │
                               ▼
                      ┌──────────────────┐
                      │ Add Session      │
                      │ Token to Active  │
                      │ Sessions (Redis) │
                      └──────────────────┘
```

### 6.3 CDR Processing Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                     CDR Processing (Async)                      │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ▼
               ┌───────────────────────────────┐
               │ Cloudonix Sends CDR           │
               │ to Laravel /api/webhooks/...  │
               └───────────────────────────────┘
                               │
                               ▼
               ┌───────────────────────────────┐
               │ Laravel Validates Webhook     │
               └───────────────────────────────┘
                               │
                               ▼
               ┌───────────────────────────────┐
               │ Update Database               │
               │ - Session status              │
               │ - Destination status          │
               └───────────────────────────────┘
                               │
                               ▼
               ┌───────────────────────────────┐
               │ Publish to Redis Channel      │
               │ channel: "cdr:completed"      │
               │ payload: {session_token, ...} │
               └───────────────────────────────┘
                               │
                               ▼
               ┌───────────────────────────────┐
               │ Worker Receives Redis Event   │
               └───────────────────────────────┘
                               │
                               ▼
               ┌───────────────────────────────┐
               │ Decrement CAC Counter         │
               │ (Redis: campaign:X:counter)   │
               └───────────────────────────────┘
                               │
                               ▼
               ┌───────────────────────────────┐
               │ Remove from Active Sessions   │
               │ (Redis: campaign:X:sessions)  │
               └───────────────────────────────┘
                               │
                               ▼
               ┌───────────────────────────────┐
               │ Trigger Retry if Needed       │
               │ (Based on disposition)        │
               └───────────────────────────────┘
```

---

## 7. Redis State Management

### 7.1 Key Structure

```
# Campaign Concurrency Counter (integer)
campaign:{campaign_id}:concurrency_counter → int

# Campaign Active Sessions (set of session tokens)
campaign:{campaign_id}:active_sessions → set<string>

# Campaign State (hash)
campaign:{campaign_id}:state → {
  "status": "running|paused",
  "paused_at": "2026-03-29T14:30:00Z",
  "pause_reason": "cloudonix_rate_limit",
  "resume_at": "2026-03-29T14:35:00Z"
}

# Global Worker State
worker:{worker_id}:campaigns → set<campaign_ids>
worker:{worker_id}:last_heartbeat → timestamp

# CDR Pub/Sub Channel
cdr:completed → channel for CDR events
```

### 7.2 Concurrency Operations

```go
// Check if we can start a new call
func (cm *ConcurrencyManager) CanStartCall(campaignID int64, cac int) bool {
    counter, err := cm.redis.Get(ctx, fmt.Sprintf("campaign:%d:concurrency_counter", campaignID)).Int()
    if err != nil {
        return false
    }
    return counter < cac
}

// Increment counter and add to active sessions
func (cm *ConcurrencyManager) StartCall(campaignID int64, sessionToken string) error {
    pipe := cm.redis.Pipeline()
    
    // Increment counter
    pipe.Incr(ctx, fmt.Sprintf("campaign:%d:concurrency_counter", campaignID))
    
    // Add to active sessions
    pipe.SAdd(ctx, fmt.Sprintf("campaign:%d:active_sessions", campaignID), sessionToken)
    
    _, err := pipe.Exec(ctx)
    return err
}

// Decrement counter and remove from active sessions (called on CDR)
func (cm *ConcurrencyManager) CompleteCall(campaignID int64, sessionToken string) error {
    pipe := cm.redis.Pipeline()
    
    // Decrement counter (never below 0)
    pipe.Decr(ctx, fmt.Sprintf("campaign:%d:concurrency_counter", campaignID))
    
    // Remove from active sessions
    pipe.SRem(ctx, fmt.Sprintf("campaign:%d:active_sessions", campaignID), sessionToken)
    
    _, err := pipe.Exec(ctx)
    return err
}

// Get current active count
func (cm *ConcurrencyManager) GetActiveCount(campaignID int64) int {
    count, _ := cm.redis.Get(ctx, fmt.Sprintf("campaign:%d:concurrency_counter", campaignID)).Int()
    if count < 0 {
        return 0
    }
    return count
}
```

---

## 8. HTTP 429 Rate Limiting Handler

### 8.1 Detection and Response

```go
// In cloudonix client
if resp.StatusCode == http.StatusTooManyRequests {
    return ErrRateLimited
}

// In executor
if errors.Is(err, cloudonix.ErrRateLimited) {
    log.Error().
        Int64("campaign_id", campaign.ID).
        Int64("organization_id", campaign.OrganizationID).
        Msg("Cloudonix rate limit exceeded (HTTP 429) - PAUSING CAMPAIGN IMMEDIATELY")

    // Update call status
    updateCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
    e.laravelClient.UpdateCallStatus(updateCtx, initResp.SessionID, &models.UpdateCallStatusRequest{
        Status: "failed",
        Error:  "rate_limited_by_cloudonix",
    })
    cancel()

    // PAUSE CAMPAIGN IMMEDIATELY
    pauseCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
    e.pauseCampaign(pauseCtx, campaign.ID, "cloudonix_rate_limit")
    cancel()
    
    // Set resume time to 300 seconds from now
    resumeAt := time.Now().Add(300 * time.Second)
    cm.redis.Set(ctx, 
        fmt.Sprintf("campaign:%d:resume_at", campaign.ID),
        resumeAt.Format(time.RFC3339),
        0)
    
    return
}
```

### 8.2 Pause Duration

- **Immediate pause**: Campaign stops accepting new calls right away
- **Cooldown period**: 300 seconds (5 minutes)
- **Resume check**: Before each cycle, check if `resume_at` time has passed

---

## 9. Data Structures

### 9.1 Campaign Model (Go)

```go
type Campaign struct {
    ID                     int64                  `json:"id"`
    OrganizationID         int64                  `json:"organization_id"`
    Name                   string                 `json:"name"`
    Status                 string                 `json:"status"`
    CallerID               string                 `json:"caller_id"`
    
    // CAC Configuration (replaces calls_per_second)
    ConcurrentActiveCalls  int                    `json:"concurrent_active_calls"`
    
    MaxDialAttempts        int                    `json:"max_dial_attempts"`
    DialTimeout            int                    `json:"dial_timeout"`
    TimeLimit              int                    `json:"time_limit"`
    RecordCalls            bool                   `json:"record_calls"`
    AMDEnabled             bool                   `json:"amd_enabled"`
    
    RoutingDestinationType string                 `json:"routing_destination_type"`
    RoutingDestinationID   int64                  `json:"routing_destination_id"`
    
    Timezone               string                 `json:"timezone"`
    StartDate              string                 `json:"start_date"`
    EndDate                string                 `json:"end_date"`
    Schedule               map[string]DaySchedule `json:"schedule"`
    
    // Cloudonix Credentials (per-organization)
    CloudonixAPIKey        string                 `json:"cloudonix_api_key"`
    CloudonixDomain        string                 `json:"cloudonix_domain"`
    CloudonixAPIURL        string                 `json:"cloudonix_api_url"`
}
```

### 9.2 Active Session Tracking

```go
type ActiveSession struct {
    CampaignID      int64     `json:"campaign_id"`
    DestinationID   int64     `json:"destination_id"`
    SessionID       int64     `json:"session_id"`
    SessionToken    string    `json:"session_token"`  // Cloudonix token
    PhoneNumber     string    `json:"phone_number"`
    StartedAt       time.Time `json:"started_at"`
    WorkerID        string    `json:"worker_id"`
}
```

### 9.3 CDR Event (from Laravel via Redis)

```go
type CDREvent struct {
    Type          string    `json:"type"`           // "call.completed"
    SessionToken  string    `json:"session_token"`  // Cloudonix token
    CampaignID    int64     `json:"campaign_id"`
    DestinationID int64     `json:"destination_id"`
    SessionID     int64     `json:"session_id"`
    Disposition   string    `json:"disposition"`    // answered, busy, no-answer, failed
    Duration      int       `json:"duration"`
    Billsec       int       `json:"billsec"`
    Timestamp     time.Time `json:"timestamp"`
}
```

---

## 10. Retry Logic Specification

### 10.1 Exponential Backoff Schedule

| Attempt | Delay | Formula |
|---------|-------|---------|
| 1 | 5 minutes | base |
| 2 | 10 minutes | base × 2 |
| 3 | 20 minutes | base × 4 |
| 4 | 40 minutes | base × 8 |
| 5 | 60 minutes | cap at 60 min |

### 10.2 Retry Eligibility

**Retryable Dispositions:**
- `busy`
- `no-answer`
- `cancelled`

**Non-Retryable Dispositions:**
- `answered`
- `completed`
- `failed`
- `congestion`

---

## 11. Monitoring & Observability

### 11.1 Key Metrics

```go
var (
    // Concurrency metrics
    campaignConcurrencyGauge = prometheus.NewGaugeVec(prometheus.GaugeOpts{
        Name: "dialer_campaign_concurrency",
        Help: "Current active calls per campaign",
    }, []string{"campaign_id"})
    
    campaignCACLimitGauge = prometheus.NewGaugeVec(prometheus.GaugeOpts{
        Name: "dialer_campaign_cac_limit",
        Help: "CAC limit for campaign",
    }, []string{"campaign_id"})
    
    // API rate metrics
    apiIntervalGauge = prometheus.NewGaugeVec(prometheus.GaugeOpts{
        Name: "dialer_api_interval_seconds",
        Help: "Seconds between API calls (60/CAC)",
    }, []string{"campaign_id"})
    
    // Rate limiting events
    rateLimitPauseCounter = prometheus.NewCounterVec(prometheus.CounterOpts{
        Name: "dialer_rate_limit_pauses_total",
        Help: "Number of times campaign was paused due to HTTP 429",
    }, []string{"campaign_id", "organization_id"})
    
    // Standard call metrics
    callsTotalCounter = prometheus.NewCounterVec(prometheus.CounterOpts{
        Name: "dialer_calls_total",
        Help: "Total calls initiated",
    }, []string{"campaign_id", "disposition"})
)
```

### 11.2 Log Events

```go
// CAC check
log.Debug().
    Int64("campaign_id", campaign.ID).
    Int("active_calls", activeCount).
    Int("cac_limit", campaign.ConcurrentActiveCalls).
    Bool("can_start", canStart).
    Msg("Checked campaign concurrency")

// API rate limiting
log.Info().
    Int64("campaign_id", campaign.ID).
    Float64("interval_seconds", interval.Seconds()).
    Int("cac", campaign.ConcurrentActiveCalls).
    Msg("Waiting for API rate limit interval")

// CDR received
log.Info().
    Int64("campaign_id", cdr.CampaignID).
    Str("session_token", cdr.SessionToken).
    Str("disposition", cdr.Disposition).
    Msg("CDR received - decrementing concurrency")

// HTTP 429
log.Error().
    Int64("campaign_id", campaign.ID).
    Int64("organization_id", campaign.OrganizationID).
    Msg("Cloudonix rate limit exceeded (HTTP 429) - PAUSING CAMPAIGN")
```

---

## 12. Implementation Phases

### Phase 1: Core CAC Implementation
1. Remove CPS configuration, add CAC to Campaign model
2. Implement Redis concurrency counter and active sessions
3. Implement API rate limiting (60/CAC interval)
4. Update call initiation flow with CAC check

### Phase 2: CDR Processing
1. Laravel CDR endpoint integration
2. Redis pub/sub for CDR events
3. Worker CDR handler (decrement counter, remove from sessions)
4. Update destination status from CDR

### Phase 3: HTTP 429 Handling
1. Detect HTTP 429 in Cloudonix client
2. Implement immediate campaign pause
3. 300-second cooldown period
4. Resume logic with timestamp check

### Phase 4: Testing & Deployment
1. Unit tests for concurrency manager
2. Integration tests with mocked CDRs
3. Load testing at various CAC levels
4. Production deployment

---

## 13. Migration Notes

### From CPS to CAC

**Database Migration:**
```sql
-- Remove CPS field
ALTER TABLE auto_dialer_campaigns DROP COLUMN calls_per_second;

-- Add CAC field with default value
ALTER TABLE auto_dialer_campaigns ADD COLUMN concurrent_active_calls INT DEFAULT 5;

-- Map existing values (if any migration needed)
-- CPS=2 → CAC=2
-- CPS=5 → CAC=5
```

**API Response Changes:**
- Remove `calls_per_second` from campaign response
- Add `concurrent_active_calls` to campaign response

**Worker Configuration:**
- Remove CPS-based configuration
- Read CAC from campaign API response
- Calculate API interval as `60 / CAC`

---

## 14. Final Notes

### Success Criteria

- ✅ CAC never exceeded for any campaign
- ✅ API calls spaced at exactly `60/CAC` second intervals
- ✅ CDR processing decrements counter within 1 second
- ✅ HTTP 429 triggers immediate pause with 300s cooldown
- ✅ Retry logic works with CAC-based scheduling
- ✅ Multiple workers coordinate via Redis without conflicts

### Key Design Decisions

1. **Redis for State**: Enables horizontal scaling across multiple workers
2. **Laravel CDR Endpoint**: Centralized CDR processing, worker notified via Redis
3. **CAC over CPS**: More predictable resource usage and call patterns
4. **Strict API Interval**: Enforced wait between Cloudonix API calls
5. **Immediate Pause on 429**: Prevents further rate limit violations
