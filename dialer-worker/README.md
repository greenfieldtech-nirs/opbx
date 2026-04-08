# OPBX Dialer Worker

A Go-based auto-dialer worker for the OPBX platform. This worker manages outbound call campaigns by interfacing with the Laravel API and Cloudonix CPaaS.

## Architecture

The worker follows a clean architecture pattern with the following components:

- **API Client**: Communicates exclusively with the Laravel REST API (no direct MySQL access)
- **Redis Client**: Manages ephemeral state, distributed locks, and idempotency
- **CAC Rate Limiter**: Implements Concurrent Active Calls (CAC) based rate limiting
- **Call Executor**: Handles call initiation and CDR processing
- **Webhook Handler**: Receives CDR events from Laravel

## Features

- **CAC-Based Rate Limiting**: Controls concurrent calls per campaign (2, 3, 4, 6, 10, 15, 20)
- **Retry Logic**: Incremental backoff (5min → 15min → 60min → 24hr) for retryable dispositions
- **HTTP 429 Handling**: Internal 300s pause without API call to Laravel
- **Webhook-Based CDR**: Receives call completion events via webhook from Laravel
- **Distributed Locks**: Prevents race conditions for destination processing
- **Idempotency**: Handles duplicate webhook deliveries safely

## Configuration

Copy `.env.example` to `.env` and configure:

```bash
# Laravel API
LARAVEL_API_URL=http://localhost:8000
LARAVEL_API_TOKEN=your-api-token

# Redis
REDIS_HOST=redis
REDIS_PORT=6379

# Worker
WORKER_ID=worker-1
WEBHOOK_PORT=8081
WEBHOOK_SECRET=your-webhook-secret
```

## Running Locally

```bash
# Setup
cp .env.example .env
# Edit .env with your configuration

# Build and run
make build
make run

# Or with go directly
go run cmd/worker/main.go
```

## Docker

```bash
# Build image
make docker-build

# Run container
make docker-run
```

## API Endpoints

The worker exposes the following webhook endpoints:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/webhooks/cdr` | POST | Receives CDR events from Laravel |
| `/webhooks/health` | GET | Health check endpoint |

## CDR Webhook Format

```json
{
  "session_id": "uuid",
  "call_status": "completed",
  "duration": 120,
  "recording_url": "https://...",
  "hangup_cause": "normal_clearing",
  "disposition": "completed",
  "timestamp": "2024-01-15T10:30:00Z"
}
```

## Retry Logic

Per the Laravel implementation, retryable dispositions are:
- `busy`
- `no-answer`
- `cancelled`

Retry intervals:
1. 5 minutes
2. 15 minutes
3. 60 minutes
4. 24 hours

## CDR Webhook Format

Laravel POSTs CDR events to the worker at `/webhooks/cdr`:

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

## Laravel API Endpoints (Consumed by Worker)

The worker consumes these Laravel API endpoints:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/v1/dialer/worker/campaigns/active` | GET | Get active campaigns |
| `/api/v1/dialer/worker/campaigns/{id}/destinations/pending` | GET | Get pending destinations |
| `/api/v1/dialer/worker/calls/initiate` | POST | Create call session |
| `/api/v1/dialer/worker/calls/{session}/status` | PATCH | Update call status |
| `/api/v1/dialer/worker/calls/{session}/disposition` | POST | Set final disposition |
