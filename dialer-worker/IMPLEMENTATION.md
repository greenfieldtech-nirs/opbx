# OPBX Auto Dialer Worker v2.0 - Implementation Summary

## Overview
Complete rewrite of the Go-based auto dialer worker according to the functional specification. The new worker uses a webhook-based architecture for CDR processing and implements proper CAC-based rate limiting.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           OPBX SYSTEM                                    │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌─────────────┐    HTTP API    ┌──────────────────┐                     │
│  │   Laravel   │◄──────────────►│  Dialer Worker   │                     │
│  │   (MySQL)   │                │    (Go v1.21)    │                     │
│  └──────┬──────┘                └────────┬─────────┘                     │
│         │                                │                               │
│         │ Webhook CDR                    │ Redis State                   │
│         │                                │                               │
│  ┌──────▼──────┐                ┌────────▼─────────┐                     │
│  │   Cloudonix │                │      Redis       │                     │
│  │   (CPaaS)   │                │  (ephemeral)     │                     │
│  └─────────────┘                └──────────────────┘                     │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
```

## Key Features Implemented

### 1. Laravel API Client (`internal/api/client.go`)
- Exclusively communicates with Laravel REST API
- No direct MySQL access (as per specification)
- Handles HTTP 429 with automatic retry logic
- Endpoints:
  - `GET /api/v1/dialer/worker/campaigns/active`
  - `GET /api/v1/dialer/worker/campaigns/{id}/destinations/pending`
  - `POST /api/v1/dialer/worker/calls/initiate`
  - `PATCH /api/v1/dialer/worker/calls/{session}/status`
  - `POST /api/v1/dialer/worker/calls/{session}/disposition`

### 2. Redis State Management (`internal/redis/client.go`)
Key prefixes for distributed state:
- `dialer:call:{session_id}` - Call state cache
- `dialer:cac:{campaign_id}:active` - Active call counter
- `dialer:lock:{key}` - Distributed locks
- `dialer:idem:{event_id}` - Idempotency keys
- `dialer:retry:{campaign_id}` - Retry queue (sorted set)
- `dialer:worker:{id}` - Worker registration

### 3. CAC Rate Limiter (`internal/limiter/cac.go`)
- Controls concurrent active calls per campaign
- Formula: `min_interval = 60 / CAC` seconds between calls
- CAC values: 2, 3, 4, 6, 10, 15, 20
- Distributed counter in Redis

### 4. Retry Logic (`pkg/retry/manager.go`)
Retryable dispositions per spec:
- `busy`
- `no-answer`
- `failed`

Retry intervals:
1. 5 minutes
2. 15 minutes
3. 60 minutes
4. 24 hours

### 5. Call Executor (`internal/executor/executor.go`)
- Acquires distributed locks before dialing
- Checks CAC availability
- Initiates calls via Laravel API
- Processes CDR webhooks
- Handles retry scheduling

### 6. Webhook Handler (`internal/webhook/handler.go`)
Endpoints:
- `POST /webhooks/cdr` - Receives CDR events from Laravel
- `GET /webhooks/health` - Health check

CDR Event Format:
```json
{
  "session_id": "123",
  "status": "completed",
  "disposition": "busy",
  "duration": 120,
  "billsec": 115,
  "recording_url": "https://...",
  "completed_at": "2024-01-15T10:30:00Z"
}
```

**Note:** CDR fields match Laravel's `updateCallStatus` endpoint parameters.

### 7. Main Orchestration (`cmd/worker/main.go`)
- Polls Laravel for active campaigns
- Registers worker in Redis
- Processes campaigns concurrently
- Handles graceful shutdown

## Configuration

Environment variables (see `.env.example`):

```bash
# Laravel API
LARAVEL_API_URL=http://nginx
LARAVEL_API_TOKEN=dev-token-change-in-production

# Redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0

# Worker
WORKER_ID=worker-1
POLL_INTERVAL=10s
HEALTH_CHECK_PORT=8080
WEBHOOK_PORT=8081
WEBHOOK_SECRET=generate-strong-secret
```

## Docker Compose Integration

The worker is configured in `docker-compose.yml`:

```yaml
dialer-worker:
  build:
    context: ./dialer-worker
    dockerfile: Dockerfile
  ports:
    - "8081:8081"  # Webhook endpoint
  environment:
    - WORKER_ID=${DIALER_WORKER_ID:-worker-1}
    - LARAVEL_API_URL=${DIALER_LARAVEL_API_URL:-http://nginx}
    - LARAVEL_API_TOKEN=${DIALER_WORKER_API_TOKEN}
    - WEBHOOK_PORT=8081
    - WEBHOOK_SECRET=${DIALER_WEBHOOK_SECRET}
```

## File Structure

```
dialer-worker/
├── cmd/worker/
│   └── main.go              # Entry point
├── internal/
│   ├── api/
│   │   └── client.go        # Laravel API client
│   ├── config/
│   │   └── config.go        # Configuration
│   ├── executor/
│   │   └── executor.go      # Call execution logic
│   ├── limiter/
│   │   └── cac.go           # CAC rate limiter
│   ├── models/
│   │   └── models.go        # Data models
│   ├── redis/
│   │   └── client.go        # Redis client
│   └── webhook/
│       └── handler.go       # Webhook handlers
├── pkg/
│   ├── errors/
│   │   └── errors.go        # Custom errors
│   └── retry/
│       └── manager.go       # Retry logic
├── Dockerfile               # Container image
├── Makefile                 # Build automation
├── README.md                # Documentation
├── .env.example             # Configuration template
├── go.mod                   # Go module definition
└── go.sum                   # Dependency checksums
```

## Important Notes

### CDR Flow (Critical)
1. Cloudonix sends CDR to Laravel webhook
2. Laravel stores CDR in MySQL (UNCHANGED from current behavior)
3. Laravel POSTs CDR to worker's `/webhooks/cdr` endpoint
4. Worker processes CDR and updates call state in Redis
5. Worker decrements CAC counter
6. Worker schedules retry if disposition is retryable

### MySQL Access
- **Worker does NOT access MySQL directly**
- All data access is via Laravel REST API
- CDR is stored in MySQL by Laravel, not the worker

### Horizontal Scaling
- Multiple workers can run concurrently
- Each worker polls Laravel independently
- Redis distributed locks prevent duplicate dialing
- CAC is tracked per-campaign in Redis

## Build & Run

```bash
# Build
cd dialer-worker
make build

# Run locally
make run

# Docker
docker compose up -d dialer-worker

# Scale to 3 workers
docker compose up -d --scale dialer-worker=3
```

## Testing

```bash
# Health check
curl http://localhost:8081/webhooks/health

# Test CDR webhook
curl -X POST http://localhost:8081/webhooks/cdr \
  -H "Content-Type: application/json" \
  -d '{"session_id":"123","status":"completed","disposition":"busy","duration":120,"billsec":115}'
```

## Known Limitations

1. **Go Version**: The local system has Go 1.12.7 which is too old. Build requires Go 1.21+.
   - Use Docker for building: `docker compose build dialer-worker`
   
2. **Laravel Endpoints**: The worker expects specific Laravel API endpoints. Ensure these are implemented:
   - `GET /api/v1/dialer/worker/campaigns/active`
   - `GET /api/v1/dialer/worker/campaigns/{id}/destinations/pending`
   - `POST /api/v1/dialer/worker/calls/initiate`
   - `PATCH /api/v1/dialer/worker/calls/{session}/status`
   - `POST /api/v1/dialer/worker/calls/{session}/disposition`

3. **CDR Webhook Configuration**: Laravel must be configured to POST CDR events to the worker's webhook endpoint.

## Next Steps

1. Implement the Laravel API endpoints if not already present
2. Configure Laravel to send CDR webhooks to the worker
3. Test with a sample campaign
4. Monitor Redis state and CAC limits
5. Scale horizontally as needed
