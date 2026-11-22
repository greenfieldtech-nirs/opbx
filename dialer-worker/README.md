# Dialer Worker

A Go-based auto-dialer campaign execution service for the OPBX platform. This worker polls the Laravel backend for active outbound campaigns, rate-limits call initiation, and initiates calls through the Cloudonix CPaaS via a Laravel proxy.

## Overview

The Dialer Worker is a standalone microservice responsible for the outbound call execution layer of the OPBX platform. It operates autonomously by:

- **Polling** the Laravel API every 10 seconds for active dialer campaigns
- **Rate-limiting** outbound calls using Concurrent Active Calls (CAC) and Calls Per Second (CPS) limits configured per campaign
- **Initiating calls** via the Laravel API, which proxies to the Cloudonix CPaaS
- **Dialing retries** that Laravel schedules (Laravel owns retry timing and dispositions; the worker receives no CDRs)
- **Selecting Caller IDs** from configurable pools using round-robin, random, or least-recently-used strategies

## Architecture

```mermaid
graph TB
    subgraph "Dialer Worker"
        MAIN["cmd/worker/main.go<br/>Entry Point & Orchestrator"]
        API["internal/api/client.go<br/>Laravel API Client"]
        EXEC["internal/executor/executor.go<br/>Call Execution Lifecycle"]
        LIM["internal/limiter/cac.go<br/>CAC & CPS Rate Limiter"]
        WH["internal/webhook/handler.go<br/>Health HTTP Server"]
        RDC["internal/redis/client.go<br/>Redis Operations"]
        CFG["internal/config/config.go<br/>Environment Config"]
        MOD["internal/models/models.go<br/>Data Models"]
        CID["internal/callerid/<br/>Caller ID Strategies"]
        ERR["pkg/errors/errors.go<br/>Error Types"]
    end

    subgraph "External Services"
        LARAVEL["Laravel API<br/>(Campaigns, Destinations, Calls)"]
        REDIS["Redis<br/>(CAC counters, Caller ID state)"]
        CLOUDONIX["Cloudonix CPaaS<br/>(Voice Platform)"]
    end

    MAIN --> API
    MAIN --> EXEC
    MAIN --> LIM
    MAIN --> WH
    MAIN --> RDC
    MAIN --> CFG
    EXEC --> API
    EXEC --> RDC
    EXEC --> LIM
    EXEC --> CID
    CID --> RDC
    LIM --> RDC
    API --> LARAVEL
    RDC --> REDIS
    LARAVEL --> CLOUDONIX
```

## Technology Stack

| Component | Technology | Version |
|-----------|-----------|---------|
| Language | Go | 1.27 |
| HTTP Framework | Gin | v1.12.0 |
| Redis Client | go-redis | v8.11.5 |
| Build Tool | Make | - |
| Container | Docker | - |

## Directory Structure

```
dialer-worker/
├── cmd/worker/
│   └── main.go              # Application entry point: poll loop, batching, graceful shutdown
│
├── internal/
│   ├── api/
│   │   └── client.go        # Laravel API HTTP client
│   ├── callerid/
│   │   ├── strategy.go      # Strategy interface & factory
│   │   ├── round_robin.go   # Round-robin Caller ID selection
│   │   ├── random.go        # Random Caller ID selection
│   │   ├── lru.go           # Least-recently-used selection
│   │   └── retry_tracker.go # Tracks tried DIDs for retries
│   ├── config/
│   │   └── config.go        # Environment configuration
│   ├── executor/
│   │   └── executor.go      # Call execution: CAC slot, Caller ID, initiate
│   ├── limiter/
│   │   └── cac.go           # CAC/CPS rate limiter
│   ├── models/
│   │   ├── models.go        # Data models & types
│   │   └── time.go          # Time parsing helpers
│   ├── redis/
│   │   └── client.go        # Redis operations
│   └── webhook/
│       └── handler.go       # Health endpoint (GET /webhooks/health)
│
├── pkg/
│   ├── errors/
│   │   └── errors.go        # Custom error types
│
├── .env.example             # Example environment configuration
├── Dockerfile               # Multi-stage Docker build
├── go.mod                   # Go module definition
├── go.sum                   # Go dependency checksums
├── Makefile                 # Build automation
└── README.md                # This file
```

## Build Instructions

### Prerequisites

- Go 1.27 (older Go rewrites `go.mod`/`go.sum`; use the Docker command below instead)
- Redis server
- Access to the Laravel API

### Local Development

```bash
# Clone and navigate to the project
cd dialer-worker

# Copy example environment file
cp .env.example .env
# Edit .env with your configuration

# Download dependencies
make deps

# Build the binary
make build

# Run the worker
make run
```

Without Go 1.27 locally, build, vet and test in Docker from the repository root:

```bash
docker run --rm -v "$PWD/dialer-worker":/src -w /src golang:1.27-alpine sh -c 'go build ./... && go vet ./... && go test ./...'
```

### Available Make Targets

| Target | Description |
|--------|-------------|
| `make build` | Build the `worker` binary |
| `make run` | Run the worker directly with `go run` |
| `make test` | Run all tests |
| `make test-coverage` | Run tests with HTML coverage report |
| `make clean` | Remove build artifacts and coverage files |
| `make fmt` | Format all Go code |
| `make lint` | Run `golangci-lint` |
| `make deps` | Download and tidy Go modules |
| `make docker-build` | Build Docker image |
| `make docker-run` | Run Docker container with `.env` |
| `make dev-setup` | Copy `.env.example` to `.env` |
| `make health` | Check the health endpoint of a locally run worker (`localhost:8081`) |

### Docker

```bash
# Build the Docker image
make docker-build

# Run the container
make docker-run
```

The Dockerfile uses a multi-stage build:
1. **Builder stage**: `golang:1.27-alpine` - compiles a static binary
2. **Final stage**: `alpine:3.24` - runs the binary as the non-root user `app` (uid 10001)

## Configuration

Configuration is loaded entirely from environment variables. Copy `.env.example` to `.env` and set the required values.

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `LARAVEL_API_URL` | Yes | `http://localhost:8000` | Base URL of the Laravel API (`http://nginx` in the compose stack) |
| `LARAVEL_API_TOKEN` | Yes | - | Bearer token for Laravel API authentication (`DIALER_WORKER_API_TOKEN` in the root `.env`) |
| `REDIS_HOST` | Yes | `localhost` | Redis server hostname |
| `REDIS_PORT` | Yes | `6379` | Redis server port |
| `REDIS_PASSWORD` | No | *(empty)* | Redis password |
| `REDIS_DB` | No | `0` | Redis database number |
| `WORKER_ID` | Yes | `worker-1` | Unique identifier for this worker instance |
| `POLL_INTERVAL` | No | `10s` | Interval between campaign polls (`DIALER_POLL_INTERVAL` in the root `.env`) |
| `WEBHOOK_PORT` | No | `8081` | Port for the health-check HTTP server |

### Retries

Laravel owns retry timing (`next_retry_at`, backoff, and failing a destination after 3 consecutive initiate errors). The worker only dials what `destinations/pending` returns and backs off for `Retry-After` on HTTP 429.

On retries the worker picks a Caller ID it has not tried yet for that destination (see [Caller ID Pool Strategies](#caller-id-pool-strategies)).

## Call Flow

```mermaid
sequenceDiagram
    participant W as Dialer Worker
    participant L as Laravel API
    participant R as Redis
    participant C as Cloudonix

    loop Every POLL_INTERVAL (10s)
        W->>L: GET /api/v1/dialer/worker/campaigns/active
        L-->>W: Active campaigns list

        loop For each active campaign
            W->>L: GET /campaigns/{id}/destinations/pending?limit={batchSize}
            L-->>W: Pending destinations

            loop For each destination
                W->>W: Wait for the CPS interval
                W->>R: Reserve a CAC slot (Lua IncrementIfBelow)
                W->>R: Select Caller ID (pool strategy state)
                W->>L: POST /calls/initiate (Idempotency-Key)
                L->>C: Initiate call via Cloudonix API
                C-->>L: Call initiated
                L-->>W: Session ID + Call ID
                alt Initiate failed
                    W->>R: Release the CAC slot
                end
            end
        end
    end

    C->>L: CDR event (call completed)
    Note over L: Laravel handles the CDR: updates the session, releases the CAC slot, schedules retries
```

## Deployment: single replica

Run exactly one worker (`deploy.replicas: 1` in `docker-compose.yml.example`). CPS pacing is in-process, so a second replica would double the dial rate. CAC is enforced in Redis, and Laravel claims each destination atomically, so a duplicate can never place the same call twice.

## Rate Limiting

The worker implements two complementary rate-limiting mechanisms per campaign:

### Concurrent Active Calls (CAC)

- **Definition**: Maximum number of simultaneous outbound calls allowed for a campaign
- **Range**: 1-50 concurrent calls
- **Implementation**: Atomic Redis Lua script (`IncrementIfBelow`) ensures safe counter increments across multiple worker instances
- **Self-healing**: Laravel owns the count. The worker only reserves a slot atomically (and rolls it back if the call is never placed); Laravel releases it on CDR and `dialer:reconcile-cac` (every minute) resets it to the number of live call sessions

### Calls Per Second (CPS)

- **Definition**: Maximum rate at which new calls can be initiated
- **Range**: 1-30 calls per second
- **Implementation**: Minimum interval between calls is calculated as `1000 / CPS` milliseconds. The worker sleeps for the remaining interval before initiating the next call

### Batch Size Calculation

The number of destinations fetched per poll cycle is calculated as:

```
batchSize = min(CPS × poll_interval_seconds, CAC)
```

With safety clamps:
- CPS is clamped to [1, 30]
- Poll interval defaults to 10 seconds if invalid
- Batch size is at least 1

## Redis Key Patterns

| Key Pattern | Type | Purpose | TTL |
|-------------|------|---------|-----|
| `dialer:cac:{campaign_id}:active` | String | Active call counter for CAC limit | No TTL |
| `dialer:rr:{campaign_id}:position` | String | Round-robin Caller ID position | 24h |
| `dialer:lru:{campaign_id}:timestamps` | Hash | Last-used time per Caller ID | 24h |
| `dialer:retry:{campaign_id}:{destination_id}` | Set | Caller ID DIDs already tried for a destination | 1h |

### Key Operations

- **CAC Counter**: Atomically incremented with a Lua script (`IncrementIfBelow`) and decremented when an initiate fails; Laravel decrements it on CDR and reconciles it every minute

## Webhook Endpoints

The worker exposes an HTTP server on `WEBHOOK_PORT` (default 8081) with the following endpoints:

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/webhooks/health` | GET | None | Health check returning `{"status": "healthy", "timestamp": "..."}` |

The worker does not receive CDRs: Laravel handles call events, decrements the CAC counter and owns retry timing.

In the compose stack the port is not published; with `-f docker-compose.ports.yml` it is on `127.0.0.1:8181` (`DIALER_WEBHOOK_HOST_PORT`).

## Caller ID Pool Strategies

When `caller_id_pool_enabled` is true on a campaign, the worker selects a Caller ID from the pool using one of three strategies:

| Strategy | Description | Redis State |
|----------|-------------|-------------|
| `round_robin` | Cycles through pool items in order | Redis counter per campaign |
| `random` | Selects a random item from the pool | None |
| `least_recently_used` | Selects the item used longest ago | Redis hash with last-used timestamps |

On retry attempts, the worker excludes already-tried DIDs to ensure a different Caller ID is used for each retry.

## API Client (Laravel)

The worker communicates exclusively with the Laravel API (no direct database access). Key endpoints:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/dialer/worker/campaigns/active` | GET | Fetch active campaigns |
| `/api/v1/dialer/worker/campaigns/{id}/destinations/pending` | GET | Fetch pending destinations |
| `/api/v1/dialer/worker/calls/initiate` | POST | Initiate a new call (with an `Idempotency-Key` header) |

A 409 from `calls/initiate` means another claim owns the destination, or a ring group campaign has no available agent; the worker skips it.
### HTTP Client Configuration

The API client uses `DisableKeepAlives: true` on the HTTP transport to prevent DNS caching issues when the Laravel API is behind nginx or a load balancer.

## Error Handling

The worker defines specific error types for different failure modes:

| Error | Description |
|-------|-------------|
| `ErrCampaignNotFound` | Campaign does not exist in Laravel |
| `ErrDestinationNotFound` | Destination not found |
| `ErrSessionNotFound` | Call session not found |
| `ErrRateLimitExceeded` | Laravel returned HTTP 429 |
| `ErrCACLimitReached` | Maximum concurrent calls reached |
| `ErrCampaignNotRunnable` | Campaign status is not "active" |
| `ErrMaxRetriesExceeded` | Destination has exceeded max retry attempts |
| `ErrWebhookInvalid` | Invalid webhook payload |
| `ErrWebhookUnauthorized` | Webhook signature verification failed |
| `ErrRedisConnection` | Redis connection error |
| `ErrLaravelAPIError` | Generic Laravel API error |
| `ErrDestinationConflict` | Laravel answered 409: destination already claimed (or no available agent) |

### HTTP 429 Handling

When the Laravel API returns HTTP 429 (Too Many Requests), the worker:
1. Reads the `Retry-After` header (defaults to 300 seconds)
2. Returns a `RetryableError` with the retry duration
3. The poll loop backs off for that duration and aborts the rest of the batch

## Graceful Shutdown

The worker handles `SIGINT` and `SIGTERM` signals gracefully:

1. Stops the campaign polling loop (an in-flight batch stops between destinations)
2. Shuts down the health HTTP server with a 5-second timeout
3. Waits up to 45 seconds for in-flight call initiations to finish (`stop_grace_period: 60s` in compose)
4. Closes the Redis connection

## Logging

The worker uses Go's structured logging (`log/slog`) with JSON output. All log entries include:

- `worker_id`: The configured worker identifier
- `campaign_id` / `campaign_name`: When processing campaigns
- `destination_id` / `phone`: When processing calls (the phone number is masked to its last 4 digits)
- `caller_id` / `caller_did_id`: The selected Caller ID
- `error`: On failure

## Development

```bash
# Format code
make fmt

# Run linter
make lint

# Run tests
make test

# Run tests with coverage
make test-coverage

# Health check
make health
```

## License

This project is part of the OPBX open-source business PBX platform.
