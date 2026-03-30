# Auto Dialer Worker - Technical Specification

## 1. Overview

The Auto Dialer Worker is a Go-based service responsible for executing outbound call campaigns using the Cloudonix CPaaS platform. It operates as a separate container in the Docker stack, consuming campaign configurations from the Laravel backend via REST API and initiating calls through the Cloudonix API.

### Key Characteristics
- **Language**: Go (Golang) 1.21+
- **Architecture**: Event-driven with internal scheduler
- **Scaling**: Horizontal scaling via multiple worker instances
- **Database Access**: NONE - Only via Laravel REST API
- **Telephony Provider**: Cloudonix

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
│  │  (API Server)│    │  (Dialer)    │    │                  │  │
│  └──────────────┘    └──────────────┘    └──────────────────┘  │
│         ▲                     │                                 │
│         │                     │                                 │
│         └─────────────────────┘                                 │
│            Webhooks (call status)                               │
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
│  │                  State Manager                         │ │
│  │  (Active Campaigns, Call Slots, Metrics, Circuit)     │ │
│  └────────────────────────────────────────────────────────┘ │
│                              │                               │
│  ┌───────────────────────────┴────────────────────────────┐ │
│  │                 HTTP Client Layer                      │ │
│  │   (Laravel API, Cloudonix API, Webhook Receiver)      │ │
│  └────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────┘
```

---

## 3. Go Worker Specification

### 3.1 Technology Stack

| Component | Technology | Purpose |
|-----------|------------|---------|
| Language | Go 1.21+ | Core implementation |
| HTTP Server | Standard `net/http` | Webhook receiver, health checks |
| HTTP Client | `net/http` with custom retry | API calls to Laravel & Cloudonix |
| Scheduling | `github.com/go-co-op/gocron/v2` | Campaign scheduling |
| Circuit Breaker | `github.com/sony/gobreaker` | AI Agent error handling |
| Metrics | `github.com/prometheus/client_golang` | Observability |
| Logging | `github.com/rs/zerolog` | Structured logging |
| Configuration | Environment variables + API polling | Dynamic configuration |

### 3.2 Configuration (Environment Variables)

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
CLOUDONIX_API_KEY=${CLOUDONIX_API_KEY}
CLOUDONIX_DOMAIN=${CLOUDONIX_DOMAIN}

# Worker Behavior
MAX_CONCURRENT_CALLS_GLOBAL=1000
DEFAULT_CALL_TIMEOUT=30s
RETRY_QUEUE_PROCESS_INTERVAL=60s
CIRCUIT_BREAKER_THRESHOLD=5
CIRCUIT_BREAKER_TIMEOUT=60s
CAMPAIGN_ERROR_PAUSE_DURATION=15s

# Observability
METRICS_PORT=9090
LOG_LEVEL=info
```

---

## 4. API Endpoints (Laravel Backend)

The Laravel backend must expose the following REST API endpoints for the worker:

### 4.1 Campaign Management Endpoints

#### `GET /api/v1/dialer/worker/campaigns/active`
Returns all campaigns that should be currently running (respecting schedule).

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Campaign Name",
      "organization_id": 4,
      "status": "active",
      "caller_id": "+1234567890",
      "calls_per_second": 2,
      "concurrent_active_calls": 10,
      "max_dial_attempts": 3,
      "dial_timeout": 30,
      "time_limit": 3600,
      "record_calls": true,
      "amd_enabled": false,
      "destination_type": "ai_assistant",
      "destination_id": 5,
      "timezone": "America/New_York",
      "days_active": ["monday", "tuesday", "wednesday", "thursday", "friday"],
      "start_date": "2026-03-01",
      "end_date": "2026-12-31",
      "start_time": "09:00",
      "end_time": "17:00"
    }
  ]
}
```

#### `GET /api/v1/dialer/worker/campaigns/{campaign}/destinations/pending`
Returns pending destinations for dialing (paginated).

**Query Parameters:**
- `limit` (int): Number of records (default: 50)
- `offset` (int): Pagination offset

**Response:**
```json
{
  "data": [
    {
      "id": 123,
      "phone_number": "+12025551234",
      "description": "John Doe",
      "dial_attempts": 0,
      "last_dialed_at": null
    }
  ],
  "meta": {
    "total": 150,
    "limit": 50,
    "offset": 0
  }
}
```

#### `GET /api/v1/dialer/worker/campaigns/{campaign}/destinations/retry`
Returns destinations that need retry (based on exponential backoff schedule).

**Response:** Same as pending endpoint.

### 4.2 Call Session Management Endpoints

#### `POST /api/v1/dialer/worker/calls/initiate`
Called by worker when initiating a call to create a session record.

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
    "session_id": "sess_abc123",
    "call_id": "call_xyz789"
  }
}
```

#### `PATCH /api/v1/dialer/worker/calls/{session}/status`
Update call status from Cloudonix webhooks.

**Request:**
```json
{
  "status": "connected",
  "disposition": "answered",
  "duration": 45,
  "billsec": 42,
  "recording_url": "https://...",
  "ended_at": "2026-03-29T14:30:47Z"
}
```

#### `POST /api/v1/dialer/worker/calls/{session}/disposition`
Set final disposition and handle retry logic.

**Request:**
```json
{
  "disposition": "no_answer",
  "should_retry": true,
  "next_retry_at": "2026-03-29T15:00:00Z",
  "attempt_number": 2
}
```

### 4.3 Circuit Breaker / Health Endpoints

#### `POST /api/v1/dialer/worker/campaigns/{campaign}/pause`
Pause campaign (used when AI Agent errors detected).

**Request:**
```json
{
  "reason": "ai_agent_errors",
  "paused_by": "dialer-worker-1",
  "resume_at": "2026-03-29T14:45:00Z"
}
```

#### `GET /api/v1/dialer/worker/health`
Health check endpoint for worker monitoring.

**Response:**
```json
{
  "status": "healthy",
  "active_campaigns": 3,
  "active_calls": 15,
  "queue_depth": 234
}
```

### 4.4 State Persistence Endpoints

#### `POST /api/v1/dialer/worker/state/persist`
Persist worker runtime state to database for failure recovery.

**Request:**
```json
{
  "worker_id": "dialer-worker-1",
  "active_calls": {
    "call_abc123": {
      "id": "call_abc123",
      "campaign_id": 1,
      "destination_id": 123,
      "phone_number": "+12025551234",
      "cloudonix_call_id": "cix_xyz789",
      "status": "connected",
      "started_at": "2026-03-29T14:30:00Z"
    }
  },
  "retry_queue": [
    {
      "destination_id": 456,
      "attempt_number": 2,
      "next_retry_at": "2026-03-29T15:00:00Z",
      "last_disposition": "no_answer"
    }
  ],
  "campaign_states": {
    "1": {
      "active_calls": 5,
      "is_paused": false,
      "last_dial_time": "2026-03-29T14:30:00Z"
    }
  },
  "last_updated": "2026-03-29T14:30:30Z"
}
```

**Response:**
```json
{
  "message": "State persisted successfully"
}
```

#### `GET /api/v1/dialer/worker/state/{worker_id}`
Retrieve persisted worker state for recovery.

**Response:**
```json
{
  "data": {
    "worker_id": "dialer-worker-1",
    "active_calls": { ... },
    "retry_queue": [ ... ],
    "campaign_states": { ... },
    "last_updated": "2026-03-29T14:30:30Z"
  }
}
```

### 4.5 Webhook Proxy Endpoint

#### `POST /api/v1/dialer/webhooks/cloudonix`
Proxy endpoint for Cloudonix webhooks. Laravel validates the signature and forwards to the worker.

**Request (from Cloudonix):**
```json
{
  "type": "call.completed",
  "call_id": "cix_xyz789",
  "from": "+1234567890",
  "to": "+12025551234",
  "status": "completed",
  "disposition": "answered",
  "duration": 45,
  "billsec": 42,
  "custom_data": {
    "campaign_id": 1,
    "destination_id": 123,
    "session_id": "sess_abc123"
  }
}
```

**Processing:**
1. Validate Cloudonix webhook signature
2. Find active worker handling this campaign
3. Forward to worker's internal webhook endpoint
4. Return 200 OK to Cloudonix

---

## 5. Core Workflows

### 5.1 Campaign Execution Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                    Campaign Execution Loop                      │
│                     (Runs every 30 seconds)                     │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
              ┌───────────────────────────────┐
              │  Fetch Active Campaigns       │
              │  (Respecting schedule)        │
              └───────────────────────────────┘
                              │
              ┌───────────────┼───────────────┐
              ▼               ▼               ▼
      ┌───────────┐  ┌───────────┐  ┌───────────────┐
      │  Active   │  │  Outside  │  │   Paused      │
      │  Hours    │  │  Schedule │  │  (Errors)     │
      └─────┬─────┘  └─────┬─────┘  └───────┬───────┘
            │              │                │
            ▼              ▼                ▼
    ┌──────────────┐  ┌────────┐  ┌────────────────┐
    │ Start/Resume │  │  Stop  │  │ Check Resume   │
    │   Dialing    │  │        │  │     Time       │
    └──────────────┘  └────────┘  └────────────────┘
```

### 5.2 Call Initiation Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                       Call Initiation                           │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
              ┌───────────────────────────────┐
              │ Check Concurrent Calls        │
              │ (Active < Campaign Limit?)    │
              └───────────────────────────────┘
                              │
                  ┌───────────┴───────────┐
                  │ Yes                   │ No
                  ▼                       ▼
        ┌──────────────────┐    ┌──────────────────┐
        │ Check CPS Limit  │    │ Wait 15 Seconds  │
        │ (Calls/sec)      │    │ Then Retry       │
        └────────┬─────────┘    └──────────────────┘
                 │
                 ▼
        ┌──────────────────┐
        │ Reserve Slot     │
        │ (Increment       │
        │ Active Calls)    │
        └────────┬─────────┘
                 │
                 ▼
        ┌──────────────────┐
        │ Create Session   │
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
        │ Update Session   │
        │ with Cloudonix   │
        │ Call ID          │
        └──────────────────┘
```

### 5.3 Call Status Handling Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                     Cloudonix Webhook                           │
│                    (Call Status Update)                         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
              ┌───────────────────────────────┐
              │ Receive Webhook               │
              │ (call.completed, etc.)        │
              └───────────────────────────────┘
                              │
                              ▼
              ┌───────────────────────────────┐
              │ Parse Disposition             │
              │ (answered, busy, no_answer,   │
              │ failed, voicemail)            │
              └───────────────────────────────┘
                              │
              ┌───────────────┼───────────────┐
              ▼               ▼               ▼
      ┌───────────┐  ┌───────────┐  ┌───────────────┐
      │ Answered  │  │   Busy    │  │   No Answer   │
      │           │  │           │  │               │
      └─────┬─────┘  └─────┬─────┘  └───────┬───────┘
            │              │                │
            ▼              └────────┬───────┘
    ┌──────────────┐                │
    │ Route to AI  │                ▼
    │ Destination  │    ┌───────────────────────┐
    └──────────────┘    │ Schedule Retry        │
                        │ (Exponential Backoff) │
                        └───────────────────────┘
```

---

## 6. Retry Logic Specification

### 6.1 Exponential Backoff Schedule

| Attempt | Delay | Formula |
|---------|-------|---------|
| 1 | 5 minutes | base (5 min) |
| 2 | 10 minutes | base × 2 |
| 3 | 20 minutes | base × 4 |
| 4 | 40 minutes | base × 8 |
| 5 | 60 minutes | cap at 60 min |

### 6.2 Retry Eligibility

**Retryable Dispositions:**
- `BUSY` - Line was busy
- `NO_ANSWER` - No answer within timeout
- `CANCEL` - Call was cancelled/failed to connect

**Non-Retryable Dispositions:**
- `ANSWERED` - Call connected successfully
- `VOICEMAIL` - AMD detected voicemail
- `INVALID_NUMBER` - Number format invalid
- `BLOCKED` - Call blocked by carrier

### 6.3 Retry State Machine

```go
type RetryState struct {
    DestinationID    int
    AttemptNumber    int
    NextRetryAt      time.Time
    LastDisposition  string
}

// Retry calculation
func calculateNextRetry(attempt int, baseDelay time.Duration) time.Time {
    delay := baseDelay * time.Duration(math.Pow(2, float64(attempt-1)))
    if delay > 60*time.Minute {
        delay = 60 * time.Minute
    }
    return time.Now().Add(delay)
}
```

---

## 7. Circuit Breaker Pattern (AI Agent Error Handling)

### 7.1 Circuit Breaker States

```
┌──────────┐     Threshold     ┌──────────┐
│  CLOSED  │ ────────────────► │   OPEN   │
│ (Normal) │   (5 errors)      │ (Paused) │
└──────────┘                   └─────┬────┘
      ▲                              │
      │                              │ Timeout
      │      Half-Open Test          │ (60s)
      └──────────────────────────────┘
```

### 7.2 Implementation

```go
// Circuit breaker for AI Agent destinations
type AICircuitBreaker struct {
    breaker *gobreaker.CircuitBreaker
}

func NewAICircuitBreaker() *AICircuitBreaker {
    settings := gobreaker.Settings{
        Name:        "ai-agent-circuit",
        MaxRequests: 5,              // Trip after 5 consecutive errors
        Interval:    30 * time.Second,
        Timeout:     60 * time.Second, // Stay open for 60s
        ReadyToTrip: func(counts gobreaker.Counts) bool {
            return counts.ConsecutiveFailures >= 5
        },
        OnStateChange: func(name string, from gobreaker.State, to gobreaker.State) {
            log.Info().Str("circuit", name).Str("from", from.String()).Str("to", to.String()).Msg("Circuit state changed")
        },
    }
    
    return &AICircuitBreaker{
        breaker: gobreaker.NewCircuitBreaker(settings),
    }
}

// Execute call with circuit breaker
func (cb *AICircuitBreaker) Execute(call func() error) error {
    _, err := cb.breaker.Execute(func() (interface{}, error) {
        return nil, call()
    })
    return err
}
```

### 7.3 Circuit Open Action

When circuit opens:
1. Immediately pause the campaign via API
2. Log the error with full context
3. Alert (if alerting configured)
4. Resume after 60 seconds (half-open test)
5. If test succeeds, resume normal dialing
6. If test fails, extend pause by another 60s

---

## 8. Data Structures

### 8.1 Core Domain Types

```go
// Campaign represents an active dialing campaign
type Campaign struct {
    ID                    int       `json:"id"`
    OrganizationID        int       `json:"organization_id"`
    Name                  string    `json:"name"`
    Status                string    `json:"status"`
    CallerID              string    `json:"caller_id"`
    CallsPerSecond        int       `json:"calls_per_second"`
    ConcurrentActiveCalls int       `json:"concurrent_active_calls"`
    MaxDialAttempts       int       `json:"max_dial_attempts"`
    DialTimeout           int       `json:"dial_timeout"`
    TimeLimit             int       `json:"time_limit"`
    RecordCalls           bool      `json:"record_calls"`
    AMDEnabled            bool      `json:"amd_enabled"`
    DestinationType       string    `json:"destination_type"`
    DestinationID         int       `json:"destination_id"`
    Timezone              string    `json:"timezone"`
    DaysActive            []string  `json:"days_active"`
    StartDate             string    `json:"start_date"`
    EndDate               string    `json:"end_date"`
    StartTime             string    `json:"start_time"`
    EndTime               string    `json:"end_time"`
}

// Destination represents a phone number to dial
type Destination struct {
    ID           int       `json:"id"`
    PhoneNumber  string    `json:"phone_number"`
    Description  string    `json:"description"`
    DialAttempts int       `json:"dial_attempts"`
    LastDialedAt *time.Time `json:"last_dialed_at"`
}

// CallSession represents an active or completed call
type CallSession struct {
    ID            string    `json:"id"`
    CampaignID    int       `json:"campaign_id"`
    DestinationID int       `json:"destination_id"`
    PhoneNumber   string    `json:"phone_number"`
    CloudonixCallID string  `json:"cloudonix_call_id"`
    Status        string    `json:"status"`
    Disposition   string    `json:"disposition"`
    Duration      int       `json:"duration"`
    Billsec       int       `json:"billsec"`
    StartedAt     time.Time `json:"started_at"`
    ConnectedAt   *time.Time `json:"connected_at"`
    EndedAt       *time.Time `json:"ended_at"`
}

// CampaignState tracks runtime state per campaign
type CampaignState struct {
    Campaign          Campaign
    ActiveCalls       int
    LastDialTime      time.Time
    CircuitBreaker    *AICircuitBreaker
    RetryQueue        []RetryState
    IsPaused          bool
    PauseReason       string
    ResumeAt          *time.Time
}
```

### 8.2 Worker State

```go
type WorkerState struct {
    mu                sync.RWMutex
    Campaigns         map[int]*CampaignState
    GlobalActiveCalls int
    Metrics           MetricsCollector
    LaravelClient     *LaravelAPIClient
    CloudonixClient   *CloudonixAPIClient
}
```

---

## 9. API Integration Details

### 9.1 Laravel API Client

```go
type LaravelAPIClient struct {
    BaseURL    string
    APIToken   string
    HTTPClient *http.Client
}

func (c *LaravelAPIClient) GetActiveCampaigns(ctx context.Context) ([]Campaign, error) {
    req, err := http.NewRequestWithContext(ctx, "GET", 
        fmt.Sprintf("%s/dialer/worker/campaigns/active", c.BaseURL), nil)
    if err != nil {
        return nil, err
    }
    
    req.Header.Set("Authorization", "Bearer "+c.APIToken)
    req.Header.Set("Accept", "application/json")
    
    resp, err := c.HTTPClient.Do(req)
    if err != nil {
        return nil, err
    }
    defer resp.Body.Close()
    
    // Parse response...
}
```

### 9.2 Cloudonix API Integration

Using the Cloudonix API endpoint: `POST /v1/calls`

```go
func (c *CloudonixAPIClient) InitiateCall(ctx context.Context, req InitiateCallRequest) (*InitiateCallResponse, error) {
    payload := map[string]interface{}{
        "domain":       c.Domain,
        "from":         req.CallerID,
        "to":           req.PhoneNumber,
        "timeout":      req.Timeout,
        "call_id":      req.InternalCallID,
        "webhook_url":  req.WebhookURL,
    }
    
    if req.Record {
        payload["record"] = true
    }
    
    if req.AMDEnabled {
        payload["amd"] = true
    }
    
    // Add destination routing based on type
    switch req.DestinationType {
    case "ai_assistant":
        payload["application"] = fmt.Sprintf("ai:%d", req.DestinationID)
    case "extension":
        payload["extension"] = req.DestinationID
    case "ring_group":
        payload["ring_group"] = req.DestinationID
    case "conference_room":
        payload["conference"] = req.DestinationID
    }
    
    // Send request...
}
```

### 9.3 Webhook Handler

```go
func (w *Worker) HandleCloudonixWebhook(wr http.ResponseWriter, r *http.Request) {
    var event CloudonixEvent
    if err := json.NewDecoder(r.Body).Decode(&event); err != nil {
        http.Error(wr, "Invalid JSON", http.StatusBadRequest)
        return
    }
    
    // Route to appropriate handler
    switch event.Type {
    case "call.completed":
        w.handleCallCompleted(event)
    case "call.connected":
        w.handleCallConnected(event)
    case "call.failed":
        w.handleCallFailed(event)
    case "call.voicemail":
        w.handleCallVoicemail(event)
    }
    
    wr.WriteHeader(http.StatusOK)
}
```

---

## 10. Monitoring & Observability

### 10.1 Prometheus Metrics

```go
var (
    // Campaign metrics
    activeCampaignsGauge = prometheus.NewGauge(prometheus.GaugeOpts{
        Name: "dialer_active_campaigns",
        Help: "Number of active campaigns",
    })
    
    activeCallsGauge = prometheus.NewGaugeVec(prometheus.GaugeOpts{
        Name: "dialer_active_calls",
        Help: "Number of active calls",
    }, []string{"campaign_id", "organization_id"})
    
    callsTotalCounter = prometheus.NewCounterVec(prometheus.CounterOpts{
        Name: "dialer_calls_total",
        Help: "Total calls initiated",
    }, []string{"campaign_id", "disposition"})
    
    callDurationHistogram = prometheus.NewHistogramVec(prometheus.HistogramOpts{
        Name:    "dialer_call_duration_seconds",
        Help:    "Call duration in seconds",
        Buckets: prometheus.DefBuckets,
    }, []string{"campaign_id"})
    
    retryQueueGauge = prometheus.NewGauge(prometheus.GaugeOpts{
        Name: "dialer_retry_queue_depth",
        Help: "Number of destinations waiting for retry",
    })
    
    circuitBreakerStateGauge = prometheus.NewGaugeVec(prometheus.GaugeOpts{
        Name: "dialer_circuit_breaker_state",
        Help: "Circuit breaker state (0=closed, 1=half-open, 2=open)",
    }, []string{"campaign_id"})
    
    // API metrics
    laravelAPIRequestDuration = prometheus.NewHistogramVec(prometheus.HistogramOpts{
        Name:    "dialer_laravel_api_request_duration_seconds",
        Help:    "Laravel API request duration",
        Buckets: prometheus.DefBuckets,
    }, []string{"endpoint", "status"})
    
    cloudonixAPIRequestDuration = prometheus.NewHistogramVec(prometheus.HistogramOpts{
        Name:    "dialer_cloudonix_api_request_duration_seconds",
        Help:    "Cloudonix API request duration",
        Buckets: prometheus.DefBuckets,
    }, []string{"endpoint", "status"})
)
```

### 10.2 Health Check Endpoint

```go
func (w *Worker) HealthHandler(wr http.ResponseWriter, r *http.Request) {
    health := struct {
        Status          string            `json:"status"`
        Version         string            `json:"version"`
        Uptime          time.Duration     `json:"uptime"`
        ActiveCampaigns int               `json:"active_campaigns"`
        ActiveCalls     int               `json:"active_calls"`
        QueueDepth      int               `json:"queue_depth"`
        CircuitBreakers map[string]string `json:"circuit_breakers"`
    }{
        Status:          "healthy",
        Version:         version,
        Uptime:          time.Since(startTime),
        ActiveCampaigns: len(w.state.Campaigns),
        ActiveCalls:     w.state.GlobalActiveCalls,
        QueueDepth:      w.getTotalRetryQueueDepth(),
        CircuitBreakers: w.getCircuitBreakerStates(),
    }
    
    json.NewEncoder(wr).Encode(health)
}
```

### 10.3 Structured Logging

```go
// Example log entries
log.Info().
    Str("component", "campaign_scheduler").
    Int("campaign_id", campaign.ID).
    Int("organization_id", campaign.OrganizationID).
    Str("campaign_name", campaign.Name).
    Int("pending_destinations", len(pending)).
    Msg("Starting campaign dialing")

log.Info().
    Str("component", "call_executor").
    Int("campaign_id", campaign.ID).
    Int("destination_id", dest.ID).
    Str("phone_number", dest.PhoneNumber).
    Str("cloudonix_call_id", callID).
    Msg("Call initiated successfully")

log.Error().
    Str("component", "circuit_breaker").
    Int("campaign_id", campaign.ID).
    Str("destination_type", campaign.DestinationType).
    Int("consecutive_errors", counts.ConsecutiveFailures).
    Msg("Circuit breaker opened - pausing campaign")
```

---

## 11. Docker Configuration

### 11.1 Dockerfile

```dockerfile
# Build stage
FROM golang:1.21-alpine AS builder

WORKDIR /app
COPY go.mod go.sum ./
RUN go mod download

COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -a -installsuffix cgo -o dialer-worker ./cmd/worker

# Runtime stage
FROM alpine:latest

RUN apk --no-cache add ca-certificates
WORKDIR /root/

COPY --from=builder /app/dialer-worker .

EXPOSE 8080 9090

CMD ["./dialer-worker"]
```

### 11.2 docker-compose.yml Addition

```yaml
  dialer-worker:
    build:
      context: ./dialer-worker
      dockerfile: Dockerfile
    container_name: opbx-dialer-worker
    environment:
      - WORKER_ID=dialer-worker-1
      - WORKER_API_PORT=8080
      - LARAVEL_API_URL=http://nginx/api/v1
      - LARAVEL_API_TOKEN=${DIALER_WORKER_API_TOKEN}
      - CLOUDONIX_API_URL=https://api.cloudonix.io
      - CLOUDONIX_API_KEY=${CLOUDONIX_API_KEY}
      - CLOUDONIX_DOMAIN=${CLOUDONIX_DOMAIN}
      - MAX_CONCURRENT_CALLS_GLOBAL=1000
      - LOG_LEVEL=info
    ports:
      - "8080:8080"   # API / Webhooks
      - "9090:9090"   # Metrics
    networks:
      - opbx-network
    restart: unless-stopped
    depends_on:
      - app
      - nginx
```

---

## 12. Deployment & Scaling

### 12.1 Single Worker
- One worker instance handles all campaigns
- Simple deployment, single point of failure
- Good for small scale (< 100 concurrent calls)

### 12.2 Multiple Workers (Horizontal Scaling)

For high volume, deploy multiple workers:

```yaml
  dialer-worker-1:
    # ... config ...
    environment:
      - WORKER_ID=dialer-worker-1
      
  dialer-worker-2:
    # ... config ...
    environment:
      - WORKER_ID=dialer-worker-2
```

**Campaign Partitioning:**
- Workers coordinate via Laravel API
- Each worker claims campaigns (distributed locking)
- Or: Static partitioning (Worker 1 = Campaigns 1-10, Worker 2 = 11-20)

### 12.3 Graceful Shutdown

```go
func (w *Worker) Shutdown(ctx context.Context) error {
    log.Info().Msg("Starting graceful shutdown...")
    
    // Stop accepting new calls
    w.state.mu.Lock()
    for _, campaign := range w.state.Campaigns {
        campaign.IsPaused = true
    }
    w.state.mu.Unlock()
    
    // Wait for active calls to complete (with timeout)
    ticker := time.NewTicker(5 * time.Second)
    defer ticker.Stop()
    
    for {
        select {
        case <-ctx.Done():
            return fmt.Errorf("shutdown timeout exceeded")
        case <-ticker.C:
            if w.state.GlobalActiveCalls == 0 {
                log.Info().Msg("All calls completed, shutting down")
                return nil
            }
            log.Info().Int("active_calls", w.state.GlobalActiveCalls).Msg("Waiting for calls to complete...")
        }
    }
}
```

---

## 13. Security Considerations

1. **API Token Security**: Store `DIALER_WORKER_API_TOKEN` in Docker secrets or environment, never in code
2. **Webhook Validation**: Verify Cloudonix webhook signatures
3. **Rate Limiting**: Respect Cloudonix API rate limits
4. **No Direct DB Access**: Worker never connects to MySQL directly
5. **Network Isolation**: Worker runs in isolated Docker network, only API access
6. **Audit Logging**: All call actions logged via Laravel API

---

## 14. Testing Strategy

### 14.1 Unit Tests
- Circuit breaker logic
- Retry calculation
- Campaign schedule validation
- Rate limiting

### 14.2 Integration Tests
- Laravel API client
- Cloudonix API mocking
- Webhook handling

### 14.3 Load Tests
- Simulate 1000 concurrent calls
- Measure CPS (calls per second)
- Verify no race conditions

---

## 15. Design Decisions Confirmed

The following design decisions have been confirmed:

| Decision | Status | Implementation |
|----------|--------|----------------|
| **State Management** | ✅ Confirmed | In-memory state with periodic DB persistence for failure recovery |
| **Active Calls on Pause** | ✅ Confirmed | Let active calls complete naturally |
| **Worker API Exposure** | ✅ Confirmed | Laravel API only (no direct worker REST API) |
| **Logging Destination** | ✅ Confirmed | Local rsyslog (not stdout or external service) |
| **Alerting** | ✅ Confirmed | Log alerts only (no email/Slack) |
| **Webhook Routing** | ✅ Confirmed | Through Laravel proxy (not direct to worker) |

---

## 16. State Management & Persistence

### 16.1 In-Memory State with DB Persistence

The worker maintains in-memory state for performance, but periodically persists critical state to the database for failure recovery:

```go
type PersistentState struct {
    ActiveCalls       map[string]*CallSession    `json:"active_calls"`
    RetryQueue        []RetryState               `json:"retry_queue"`
    CampaignStates    map[int]*CampaignRuntimeState `json:"campaign_states"`
    LastUpdated       time.Time                  `json:"last_updated"`
    WorkerID          string                     `json:"worker_id"`
}

// PersistState saves critical state to Laravel API
type StatePersister struct {
    client *LaravelAPIClient
    ticker *time.Ticker
}

func (sp *StatePersister) Start(ctx context.Context, state *WorkerState) {
    sp.ticker = time.NewTicker(30 * time.Second) // Persist every 30s
    
    go func() {
        for {
            select {
            case <-ctx.Done():
                return
            case <-sp.ticker.C:
                if err := sp.persist(state); err != nil {
                    log.Error().Err(err).Msg("Failed to persist state")
                }
            }
        }
    }()
}

func (sp *StatePersister) persist(state *WorkerState) error {
    state.mu.RLock()
    defer state.mu.RUnlock()
    
    persistent := PersistentState{
        ActiveCalls:    make(map[string]*CallSession),
        RetryQueue:     []RetryState{},
        CampaignStates: make(map[int]*CampaignRuntimeState),
        LastUpdated:    time.Now().UTC(),
        WorkerID:       state.WorkerID,
    }
    
    // Copy active calls
    for id, call := range state.ActiveCalls {
        persistent.ActiveCalls[id] = call
    }
    
    // Copy retry queue
    for _, retry := range state.RetryQueue {
        persistent.RetryQueue = append(persistent.RetryQueue, retry)
    }
    
    // Copy campaign runtime state
    for id, cs := range state.Campaigns {
        persistent.CampaignStates[id] = &CampaignRuntimeState{
            ActiveCalls:    cs.ActiveCalls,
            IsPaused:       cs.IsPaused,
            PauseReason:    cs.PauseReason,
            LastDialTime:   cs.LastDialTime,
        }
    }
    
    // Send to Laravel API
    return sp.client.PersistWorkerState(persistent)
}
```

### 16.2 Recovery on Startup

When worker starts, it checks for persisted state:

```go
func (w *Worker) RecoverState() error {
    persisted, err := w.laravelClient.GetWorkerState(w.WorkerID)
    if err != nil {
        log.Warn().Err(err).Msg("No persisted state found, starting fresh")
        return nil
    }
    
    // Check if state is stale (> 5 minutes old)
    if time.Since(persisted.LastUpdated) > 5*time.Minute {
        log.Warn().Time("last_updated", persisted.LastUpdated).Msg("Persisted state is stale, not recovering active calls")
        // Only recover retry queue
        w.state.RetryQueue = persisted.RetryQueue
        return nil
    }
    
    // Recover active calls (validate with Cloudonix)
    for callID, call := range persisted.ActiveCalls {
        // Check call status with Cloudonix
        status, err := w.cloudonixClient.GetCallStatus(call.CloudonixCallID)
        if err != nil || status == "completed" {
            // Call already ended or error checking - update disposition
            w.updateCallDisposition(call, "unknown", 0)
            continue
        }
        
        // Call still active - add to tracking
        w.state.ActiveCalls[callID] = call
        w.state.GlobalActiveCalls++
    }
    
    log.Info().
        Int("recovered_calls", len(w.state.ActiveCalls)).
        Int("recovered_retries", len(persisted.RetryQueue)).
        Msg("State recovered successfully")
    
    return nil
}
```

### 16.3 Laravel API State Endpoints

**`POST /api/v1/dialer/worker/state/persist`**

Persist worker state to database.

**Request:**
```json
{
  "worker_id": "dialer-worker-1",
  "active_calls": {
    "call_abc123": {
      "id": "call_abc123",
      "campaign_id": 1,
      "destination_id": 123,
      "phone_number": "+12025551234",
      "cloudonix_call_id": "cix_xyz789",
      "status": "connected",
      "started_at": "2026-03-29T14:30:00Z"
    }
  },
  "retry_queue": [
    {
      "destination_id": 456,
      "attempt_number": 2,
      "next_retry_at": "2026-03-29T15:00:00Z",
      "last_disposition": "no_answer"
    }
  ],
  "campaign_states": {
    "1": {
      "active_calls": 5,
      "is_paused": false,
      "last_dial_time": "2026-03-29T14:30:00Z"
    }
  },
  "last_updated": "2026-03-29T14:30:30Z"
}
```

**`GET /api/v1/dialer/worker/state/{worker_id}`**

Retrieve persisted worker state.

---

## 17. Active Calls on Campaign Pause

When a campaign is paused (circuit breaker, outside schedule, or manual):

```go
func (w *Worker) pauseCampaign(campaignID int, reason string) {
    state := w.state.Campaigns[campaignID]
    state.IsPaused = true
    state.PauseReason = reason
    
    log.Info().
        Int("campaign_id", campaignID).
        Str("reason", reason).
        Int("active_calls", state.ActiveCalls).
        Msg("Campaign paused - letting active calls complete")
    
    // Note: We do NOT hangup active calls
    // They will complete naturally and be dispositioned normally
}
```

**Behavior:**
- Campaign stops accepting new destinations
- Active calls continue until natural completion
- Call dispositions are still processed and recorded
- Once all active calls complete, campaign is fully paused

---

## 18. Logging Configuration (rsyslog)

### 18.1 Go ZeroLog to rsyslog

```go
import (
    "github.com/rs/zerolog"
    "github.com/rs/zerolog/log"
    "gopkg.in/mcuadros/go-syslog.v2"
)

func setupLogging() {
    // Configure zerolog to write to rsyslog via UDP
    syslogWriter, err := syslog.Dial("udp", "localhost:514", syslog.LOG_INFO, "dialer-worker")
    if err != nil {
        // Fallback to stdout
        log.Logger = zerolog.New(zerolog.ConsoleWriter{Out: os.Stderr}).With().Timestamp().Logger()
        return
    }
    
    log.Logger = zerolog.New(syslogWriter).With().Timestamp().Logger()
}
```

### 18.2 Docker Compose rsyslog Configuration

```yaml
  dialer-worker:
    # ... existing config ...
    logging:
      driver: syslog
      options:
        syslog-address: "udp://localhost:514"
        tag: "dialer-worker"
```

### 18.3 Log Format

```
Mar 29 14:30:15 dialer-worker[1]: {"level":"info","component":"campaign_scheduler","campaign_id":1,"campaign_name":"Test Campaign","pending_destinations":150,"message":"Starting campaign dialing"}
Mar 29 14:30:16 dialer-worker[1]: {"level":"info","component":"call_executor","campaign_id":1,"destination_id":123,"phone_number":"+12025551234","cloudonix_call_id":"cix_xyz789","message":"Call initiated successfully"}
Mar 29 14:30:45 dialer-worker[1]: {"level":"error","component":"circuit_breaker","campaign_id":1,"destination_type":"ai_assistant","consecutive_errors":5,"message":"Circuit breaker opened - pausing campaign"}
```

---

## 19. Webhook Routing via Laravel Proxy

Cloudonix webhooks go through Laravel, which validates and forwards to the worker:

```
Cloudonix ──► Laravel ──► Worker
              (validate)  (process)
```

### 19.1 Laravel Webhook Endpoint

**`POST /api/v1/dialer/webhooks/cloudonix`**

Laravel validates the webhook signature, then forwards to the active worker(s).

```php
// Laravel controller
public function handleCloudonixWebhook(Request $request) {
    // Validate Cloudonix signature
    if (!$this->validateSignature($request)) {
        return response()->json(['error' => 'Invalid signature'], 401);
    }
    
    // Find which worker is handling this campaign
    $campaignId = $request->input('custom_data.campaign_id');
    $worker = $this->getActiveWorkerForCampaign($campaignId);
    
    // Forward to worker
    $response = Http::post($worker->url . '/internal/webhooks/cloudonix', $request->all());
    
    return response()->json(['status' => 'ok']);
}
```

### 19.2 Worker Internal Webhook Handler

The worker exposes an internal endpoint (not publicly accessible) for Laravel to forward webhooks:

```go
// Internal handler - only accepts from Laravel
func (w *Worker) InternalWebhookHandler(wr http.ResponseWriter, r *http.Request) {
    // Verify request is from Laravel (internal network or shared secret)
    if !w.verifyLaravelOrigin(r) {
        http.Error(wr, "Unauthorized", http.StatusUnauthorized)
        return
    }
    
    var event CloudonixEvent
    if err := json.NewDecoder(r.Body).Decode(&event); err != nil {
        http.Error(wr, "Invalid JSON", http.StatusBadRequest)
        return
    }
    
    w.processCloudonixEvent(event)
    wr.WriteHeader(http.StatusOK)
}
```

---

## 20. Complete Implementation Summary

### Phase 1: Core Worker
1. Go project setup with dependency management
2. HTTP client for Laravel API
3. HTTP client for Cloudonix API
4. Webhook receiver (internal)
5. Basic campaign scheduler
6. Call executor with rate limiting

### Phase 2: Advanced Features
1. Circuit breaker for AI Agent monitoring
2. Exponential backoff retry queue
3. State persistence to Laravel API
4. Recovery on startup
5. rsyslog integration

### Phase 3: Laravel Backend
1. Implement all `/api/v1/dialer/worker/*` endpoints
2. Webhook proxy endpoint
3. State persistence endpoints
4. Metrics aggregation

### Phase 4: Testing & Deployment
1. Unit tests for core logic
2. Integration tests with mocked APIs
3. Load testing
4. Docker deployment
5. Production monitoring

---

## 21. Final Notes

### Success Criteria
- ✅ Handles multiple concurrent campaigns
- ✅ Respects campaign schedules and limits
- ✅ Circuit breaker pauses campaigns on AI errors
- ✅ Exponential backoff for retries
- ✅ No direct database access
- ✅ State persistence for failure recovery
- ✅ Graceful shutdown (lets calls complete)
- ✅ Laravel API only (no direct worker API)
- ✅ rsyslog for logging
- ✅ Alerts go to logs only
- ✅ Webhooks through Laravel proxy

### Performance Targets
- 100+ concurrent calls per worker instance
- < 100ms latency for call initiation
- 99.9% uptime (excluding planned maintenance)
- State persistence every 30 seconds
- Recovery time < 60 seconds after crash

---

**Specification Version:** 1.0  
**Last Updated:** 2026-03-29  
**Status:** Ready for Implementation
