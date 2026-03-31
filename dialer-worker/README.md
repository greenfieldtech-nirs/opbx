# OpBX Dialer Worker

A Go-based worker service for executing outbound call campaigns using the Cloudonix CPaaS platform.

## Overview

The Dialer Worker is responsible for:
- Fetching active campaigns from the Laravel backend
- Scheduling calls based on business hours
- Initiating outbound calls via Cloudonix API
- Handling webhook callbacks for call events
- Managing retry queues with exponential backoff
- Circuit breaker pattern for AI agent error handling
- Simple JSON status endpoint for monitoring

## Architecture

```
┌─────────────────┐
│   Laravel API   │
│   (Backend)     │
└────────┬────────┘
         │
         │ REST API
         ▼
┌─────────────────┐
│  Dialer Worker  │
│   (Go Service)  │
│                 │
│ ┌─────────────┐ │
│ │  Scheduler  │ │
│ └─────────────┘ │
│ ┌─────────────┐ │
│ │  Executor   │ │
│ └─────────────┘ │
│ ┌─────────────┐ │
│ │ Retry Queue │ │
│ └─────────────┘ │
│ ┌─────────────┐ │
│ │   Circuit   │ │
│ │   Breaker   │ │
│ └─────────────┘ │
└────────┬────────┘
         │
         │ Voice API
         ▼
┌─────────────────┐
│   Cloudonix     │
│   (CPaaS)       │
└─────────────────┘
```

## Project Structure

```
dialer-worker/
├── cmd/
│   └── worker/
│       ├── main.go           # Entry point
│       └── main_test.go      # Main tests
├── internal/
│   ├── api/                  # Laravel API client
│   │   └── client.go
│   ├── circuitbreaker/       # Circuit breaker pattern
│   │   ├── breaker.go
│   │   └── breaker_test.go
│   ├── cloudonix/            # Cloudonix API client
│   │   └── client.go
│   ├── config/               # Configuration
│   │   └── config.go
│   ├── executor/             # Call execution
│   │   ├── executor.go
│   │   └── executor_test.go
│   ├── metrics/              # Simple metrics collection
│   │   └── metrics.go
│   ├── retry/                # Retry queue
│   │   ├── queue.go
│   │   └── queue_test.go
│   ├── scheduler/            # Campaign scheduling
│   │   └── scheduler.go
│   ├── state/                # State persistence
│   │   └── persister.go
│   └── webhook/              # Webhook handler
│       └── handler.go
├── pkg/
│   └── models/               # Shared data models
│       └── models.go
├── Dockerfile
├── go.mod
├── go.sum
└── README.md
```

## Configuration

The worker is configured via environment variables:

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `WORKER_ID` | Yes | `dialer-worker-1` | Unique worker identifier |
| `WORKER_API_PORT` | No | `8080` | HTTP API port (webhooks + status) |
| `LARAVEL_API_URL` | Yes | - | Laravel API base URL |
| `LARAVEL_API_TOKEN` | Yes | - | Laravel API token |
| `MAX_CONCURRENT_CALLS_GLOBAL` | No | `10` | Max concurrent calls |
| `DEFAULT_CALL_TIMEOUT` | No | `30` | Default call timeout (seconds) |
| `STATE_DIR` | No | `/app/state` | State persistence directory |
| `LOG_LEVEL` | No | `info` | Log level (debug, info, warn, error) |
| `CIRCUIT_BREAKER_THRESHOLD` | No | `5` | Failures before opening circuit |
| `CIRCUIT_BREAKER_TIMEOUT` | No | `5` | Circuit breaker timeout (minutes) |

## Building

```bash
# Build the binary
go build -o worker ./cmd/worker

# Run tests
go test ./...

# Run with coverage
go test -cover ./...
```

## Docker

```bash
# Build image
docker build -t opbx-dialer-worker .

# Run container
docker run -d \
  -e WORKER_ID=worker-1 \
  -e LARAVEL_API_URL=http://api:8000 \
  -e LARAVEL_API_TOKEN=token \
  -e CLOUDONIX_API_URL=https://api.cloudonix.io \
  -e CLOUDONIX_API_KEY=key \
  -e CLOUDONIX_DOMAIN=domain.cloudonix.io \
  -p 8080:8080 \
  -p 9090:9090 \
  opbx-dialer-worker
```

## API Endpoints

### Health Check
```
GET /health
```

### Webhooks (from Cloudonix)
```
POST /webhooks/cloudonix
```

### Status
```
GET /status
```

Returns JSON with current metrics:
```json
{
  "status": "healthy",
  "uptime": "2h15m30s",
  "started_at": "2026-03-31T09:00:00Z",
  "metrics": {
    "calls_initiated": 150,
    "calls_completed": 145,
    "calls_failed": 5,
    "active_calls": 3,
    "active_campaigns": 2,
    "circuit_breaker_state": "closed",
    "circuit_breaker_trips": 0,
    "retry_attempts": 10,
    "retry_failures": 2
  }
}
```

## Development

```bash
# Run locally
go run ./cmd/worker

# Run with specific env
WORKER_ID=test LARAVEL_API_URL=http://localhost:8000 go run ./cmd/worker

# Run specific tests
go test ./internal/retry -v
go test ./internal/circuitbreaker -v
```

## Integration with Laravel

The worker communicates with the Laravel backend via REST API:

- `GET /api/v1/dialer/worker/campaigns/active` - Fetch active campaigns
- `GET /api/v1/dialer/worker/campaigns/{id}/destinations/pending` - Get pending destinations
- `POST /api/v1/dialer/worker/calls/initiate` - Create call session
- `PATCH /api/v1/dialer/worker/calls/{session}/status` - Update call status
- `POST /api/v1/dialer/worker/calls/{session}/disposition` - Set final disposition
- `POST /api/v1/dialer/worker/campaigns/{id}/pause` - Pause campaign
- `POST /api/v1/dialer/worker/state/persist` - Persist worker state

## License

MIT
