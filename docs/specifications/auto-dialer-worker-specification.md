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

## 15. Questions for You

Before I finalize this specification, please confirm:

1. **Should the worker be stateless** (all state in Laravel DB) or can it maintain in-memory state (active calls, retry queue)?

2. **What happens to active calls** when a campaign is paused or goes outside schedule? (Let them complete, or force hangup?)

3. **Should the worker expose a REST API** for manual control (pause/resume campaign, check status) or is Laravel API sufficient?

4. **Logging destination**: Should logs go to stdout (Docker handles) or directly to a log service (ELK, etc.)?

5. **Alerting**: When circuit breaker opens, should worker send email/Slack alert or just log?

6. **Cloudonix webhook URL**: What URL should Cloudonix call? `https://worker:8080/webhooks/cloudonix` or through Laravel proxy?

Please review and let me know any changes or clarifications needed!
