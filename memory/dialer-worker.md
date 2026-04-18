# Dialer Worker (Go)

## Overview
Go-based service that polls Laravel for active campaigns, rate-limits outbound calls via Redis CAC counters, initiates calls through Laravel's Cloudonix integration, and handles CDR webhooks. Runs as a Docker container alongside the Laravel app.

## Source Files (dialer-worker/)
| File | Purpose |
|------|---------|
| `cmd/worker/main.go` | Entry point, main loop (221 lines) |
| `internal/executor/executor.go` | Call execution lifecycle (226 lines) |
| `internal/api/client.go` | Laravel API HTTP client (222 lines) |
| `internal/limiter/cac.go` | CAC rate limiter (149 lines) |
| `internal/callerid/strategy.go` | Caller ID strategy interface + factory |
| `internal/callerid/round_robin.go` | Weighted round-robin with Redis counter |
| `internal/callerid/random.go` | Weighted random selection |
| `internal/callerid/lru.go` | LRU with Redis timestamps hash |
| `internal/callerid/retry_tracker.go` | Tracks tried DIDs per destination |
| `internal/webhook/handler.go` | CDR webhook handler (114 lines) |
| `internal/models/models.go` | Data models (160 lines) |
| `internal/models/time.go` | FlexTime type with multi-format JSON unmarshaling |
| `internal/redis/client.go` | Redis operations (252 lines) |
| `internal/config/config.go` | Environment config (84 lines) |
| `pkg/errors/errors.go` | Error types |
| `pkg/retry/manager.go` | Retry/backoff logic |

## Architecture
```
[Main Loop (10s poll)] -> GET /campaigns/active -> for each campaign:
  -> sync.Map guard (skip if campaign already processing from prior cycle)
  -> RegisterCampaign(CAC, CPS) in rate limiter
  -> GET /destinations/pending?limit=min(CPS*poll_interval, CAC)
  -> for each destination (in goroutine, flag cleared on completion):
     -> AcquireLock(dest:{id}) [Redis SETNX]
     -> CanDial() [active < CAC && interval OK]
     -> POST /calls/initiate [Laravel -> Cloudonix API]
     -> IncrementActive() [Redis INCR]
     -> SetCallState() [Redis HSET, 60s TTL]
```

## Concurrency Guard
`processingCampaigns sync.Map` prevents concurrent goroutines for the same campaign.
If a previous poll cycle's goroutine is still running, the next cycle skips that campaign.
Prevents duplicate calls when goroutines outlast the 10-second poll interval.

## CDR Handling / CAC Decrement
The Go worker increments `dialer:cac:{id}:active` when initiating calls but does NOT
subscribe to any Redis Pub/Sub channel for CDR events. **Laravel handles the decrement
directly** in both `DialerWebhookProxyController` and `CloudonixWebhookController` when
processing call completion/failure webhooks from Cloudonix.

```
Cloudonix -> webhook -> Laravel (DialerWebhookProxyController / CloudonixWebhookController)
  -> Redis DECR dialer:cac:{campaignId}:active  (floor at 0)
  -> Redis DECR campaign:{campaignId}:concurrency_counter (legacy, if exists)
  -> DB updates (session, destination, campaign stats)
```

The worker's webhook handler at `:8081/webhooks/cdr` exists but is NOT the primary CDR
path — Laravel processes Cloudonix webhooks directly and decrements the counter.

## Redis Reconciliation
`ReconcileActiveCalls()` in `redis/client.go` compares CAC counter against actual
`dialer:call:*` state keys. If counter > live calls, resets to actual count (self-healing).

## Redis Key Patterns
| Prefix | Purpose |
|--------|---------|
| `dialer:cac:{id}:active` | Active call counter per campaign |
| `dialer:call:{session}` | Call state hash (60s TTL) |
| `dialer:lock:{key}` | Distributed locks |
| `dialer:retry:{campaign}` | Retry sorted sets (ZADD/ZRANGEBYSCORE) |
| `dialer:worker:{id}` | Worker registration (30s TTL heartbeat) |
| `dialer:idem:{key}` | Webhook idempotency |

## Rate Limiting
- **CAC-based**: active count < campaign.concurrent_active_calls (Redis counter, 1-50)
- **CPS-based**: min interval = `1000/CPS` milliseconds between calls (in-memory, CPS 1-30, clamped by limiter)
- **Batch size**: `min(CPS × poll_interval_seconds, CAC)` destinations per cycle
- HTTP 429: RetryableError with Retry-After (default 5min)
- Spec: `docs/specifications/auto-dialer-cps-parameter.md`

## Configuration (Environment Variables)
| Variable | Default | Purpose |
|----------|---------|---------|
| LARAVEL_API_URL | http://localhost:8000 | Backend URL |
| LARAVEL_API_TOKEN | | Bearer auth token |
| REDIS_HOST/PORT/PASSWORD/DB | localhost:6379 | Redis connection |
| WORKER_ID | worker-1 | Unique worker ID |
| POLL_INTERVAL | 10s | Campaign polling interval |
| WEBHOOK_PORT | 8081 | CDR webhook listener |
| WEBHOOK_SECRET | | HMAC-SHA256 secret |

## Laravel Redis Connection
Laravel uses a `dialer` Redis connection (`config/database.php`) with **no key prefix** for
all Go worker shared keys. The default Laravel connection adds `opbx-...-database-` prefix,
which would make Laravel and the Go worker read/write different keys.

Controllers that use this: `AutoDialerCampaignController` (monitor reads),
`CloudonixWebhookController` (CDR decrements), `DialerWebhookProxyController` (CDR decrements).

## HTTP Client
Uses `DisableKeepAlives: true` to prevent stale connections when nginx restarts.

## Related Modules
- [Auto Dialer Campaigns](auto-dialer-campaigns.md) - Campaign/destination management
- [Distribution Lists](distribution-lists.md) - Contact sources
- [Infrastructure](infrastructure-docker.md) - Docker container configuration
