# Auto Dialer Horizontal Scaling Guide

## Overview

The auto dialer worker supports horizontal scaling to handle high call volumes. Multiple worker instances can run concurrently, distributing the workload across multiple containers.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           LARAVEL BACKEND                                │
│  ┌─────────────────────────────────────────────────────────────────┐   │
│  │                    Destination Queue Manager                     │   │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐  │   │
│  │  │  Campaigns   │  │  Destination │  │  Queue Distribution   │  │   │
│  │  │   (Active)   │  │   Queue      │  │  (Redis/Laravel)     │  │   │
│  │  └──────┬───────┘  └──────┬───────┘  └──────────┬───────────┘  │   │
│  └─────────┼────────────────┼─────────────────────┼──────────────┘   │
└────────────┼────────────────┼─────────────────────┼──────────────────┘
             │                │                     │
             │  Pull Active   │  Claim Destination  │
             │  Campaigns     │  (Competing Consumers)│
             ▼                ▼                     ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        WORKER POOL (Multiple Instances)                  │
│                                                                          │
│  ┌────────────────┐  ┌────────────────┐  ┌────────────────┐             │
│  │   Worker #1    │  │   Worker #2    │  │   Worker #3    │  ...        │
│  │                │  │                │  │                │             │
│  │ - Claims dest  │  │ - Claims dest  │  │ - Claims dest  │             │
│  │ - Makes call   │  │ - Makes call   │  │ - Makes call   │             │
│  │ - Updates API  │  │ - Updates API  │  │ - Updates API  │             │
│  └────────────────┘  └────────────────┘  └────────────────┘             │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                         CLOUDONIX CPaaS                                  │
│                    (Outbound calls for all workers)                      │
└─────────────────────────────────────────────────────────────────────────┘
```

## Quick Start: Running Multiple Workers

### Option 1: Using Docker Compose with Multiple Services

The default docker-compose.yml uses a fixed port. For multiple workers, create an override file:

```yaml
# docker-compose.dialer-scale.yml
services:
  dialer-worker-2:
    build:
      context: ./dialer-worker
      dockerfile: Dockerfile
    environment:
      - WORKER_ID=dialer-worker-2
      - WORKER_API_PORT=8080
      - LARAVEL_API_URL=${DIALER_LARAVEL_API_URL:-http://nginx/api/v1}
      - LARAVEL_API_TOKEN=${DIALER_WORKER_API_TOKEN}
      - REDIS_ADDR=redis:6379
      - REDIS_PASSWORD=${REDIS_PASSWORD}
      - MAX_CONCURRENT_CALLS_GLOBAL=${DIALER_MAX_CONCURRENT:-100}
      - DEFAULT_CALL_TIMEOUT=${DIALER_CALL_TIMEOUT:-30}
      - LOG_LEVEL=${DIALER_LOG_LEVEL:-info}
    # No port mapping - internal only
    networks:
      - opbx
    depends_on:
      - app
      - nginx
      - redis

  dialer-worker-3:
    build:
      context: ./dialer-worker
      dockerfile: Dockerfile
    environment:
      - WORKER_ID=dialer-worker-3
      - WORKER_API_PORT=8080
      - LARAVEL_API_URL=${DIALER_LARAVEL_API_URL:-http://nginx/api/v1}
      - LARAVEL_API_TOKEN=${DIALER_WORKER_API_TOKEN}
      - REDIS_ADDR=redis:6379
      - REDIS_PASSWORD=${REDIS_PASSWORD}
      - MAX_CONCURRENT_CALLS_GLOBAL=${DIALER_MAX_CONCURRENT:-100}
      - DEFAULT_CALL_TIMEOUT=${DIALER_CALL_TIMEOUT:-30}
      - LOG_LEVEL=${DIALER_LOG_LEVEL:-info}
    networks:
      - opbx
    depends_on:
      - app
      - nginx
      - redis
```

```bash
# Start multiple workers
docker compose -f docker-compose.yml -f docker-compose.dialer-scale.yml up -d

# Or use Docker Swarm for production scaling
```

### Option 2: Using Docker Swarm (Production)

```yaml
# docker-compose.prod.yml
version: '3.8'
services:
  dialer-worker:
    image: opbx/dialer-worker:latest
    deploy:
      replicas: 5
      update_config:
        parallelism: 1
        delay: 10s
      restart_policy:
        condition: on-failure
    environment:
      - WORKER_ID={{.Task.Name}}  # Unique per replica
      - LARAVEL_API_URL=http://nginx/api/v1
      - REDIS_ADDR=redis:6379
```

```bash
# Deploy to swarm
docker swarm init
docker stack deploy -c docker-compose.prod.yml opbx

# Scale workers
docker service scale opbx_dialer-worker=10
```

## Work Distribution Strategies

### Strategy 1: Redis Work Queue (Recommended)

Uses Redis as a centralized queue with competing consumers pattern.

**How it works:**
1. Laravel pushes destinations to Redis list `dialer:destinations:pending`
2. Each worker calls `BRPopLPush` to atomically claim a destination
3. Destination moves to worker-specific processing list
4. Worker completes call and removes from processing list
5. If worker dies, processing items can be reclaimed

**Benefits:**
- Automatic load balancing
- No duplicate work (atomic claim)
- Automatic failover (unprocessed items reclaimed)
- Works across multiple Docker hosts

**Configuration:**
```env
# Worker environment
REDIS_ADDR=redis:6379
REDIS_PASSWORD=your_password
```

**Laravel Integration:**
```php
// When campaign is activated
$destinations = $campaign->destinations()->pending()->get();
foreach ($destinations as $dest) {
    Redis::lpush('dialer:destinations:pending', json_encode([
        'destination_id' => $dest->id,
        'campaign_id' => $campaign->id,
        'phone_number' => $dest->phone_number,
        'cloudonix_creds' => [
            'api_key' => $campaign->organization->cloudonix_api_key,
            'domain' => $campaign->organization->cloudonix_domain,
        ],
    ]));
}
```

### Strategy 2: Laravel Assignment API

Laravel maintains which worker owns which destination.

**How it works:**
1. Workers periodically call `POST /api/dialer/worker/claim-destinations`
2. Laravel assigns up to N unassigned destinations to that worker
3. Worker processes assigned destinations
4. Worker reports completion/failure to Laravel

**API Contract:**
```http
POST /api/v1/dialer/worker/claim-destinations
Authorization: Bearer {worker_token}
Content-Type: application/json

{
  "worker_id": "dialer-worker-1",
  "max_count": 10
}

Response:
{
  "data": [
    {
      "destination_id": 123,
      "campaign_id": 456,
      "phone_number": "+15551234567",
      "cloudonix_creds": {
        "api_key": "XI...",
        "domain": "org.cloudonix.net"
      }
    }
  ]
}
```

### Strategy 3: Campaign Sharding

Each worker is assigned specific campaigns.

**How it works:**
1. Configure worker with `CAMPAIGN_IDS=1,2,3` or `CAMPAIGN_SHARD=0/3`
2. Worker only fetches campaigns matching its shard
3. No coordination needed between workers

**Configuration:**
```yaml
services:
  dialer-worker-1:
    environment:
      - WORKER_ID=worker-1
      - CAMPAIGN_SHARD=0/3  # Shard 0 of 3
  
  dialer-worker-2:
    environment:
      - WORKER_ID=worker-2
      - CAMPAIGN_SHARD=1/3  # Shard 1 of 3
  
  dialer-worker-3:
    environment:
      - WORKER_ID=worker-3
      - CAMPAIGN_SHARD=2/3  # Shard 2 of 3
```

## Webhook Routing

### Problem
Cloudonix sends webhooks to a URL. Which worker should receive them?

### Solutions

**Option A: Shared Webhook Processing (Recommended)**
- All webhooks go to Laravel
- Laravel publishes to Redis pub/sub or internal queue
- All workers subscribe and filter for their active calls

**Option B: Sticky Sessions**
- Use Redis to track `call_id -> worker_id` mapping
- Load balancer routes webhook to correct worker
- Requires external load balancer (nginx, traefik)

**Option C: Any Worker Can Handle**
- All workers share call state in Redis
- Any worker can process any webhook
- More complex but most resilient

## Monitoring Multiple Workers

### Status Endpoint

Each worker exposes a simple JSON status endpoint:

```bash
# Check worker status
curl http://localhost:8080/status
```

Response:
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

### Health Checks

```bash
# Check all workers
for i in {0..2}; do
  port=$((8080 + i))
  curl -s http://localhost:$port/health | jq .
done
```

## Capacity Planning

### Call Volume per Worker

| Resource | Light Load | Medium Load | Heavy Load |
|----------|-----------|-------------|------------|
| CPU | 0.5 cores | 1 core | 2 cores |
| Memory | 256MB | 512MB | 1GB |
| Concurrent Calls | 10 | 50 | 100 |
| Calls/Second | 2 | 10 | 20 |

### Scaling Formula

```
workers_needed = ceil(
    max_concurrent_calls / calls_per_worker,
    total_destinations / (calls_per_second * time_available)
)

Example:
- 10,000 destinations
- 4 hour window
- Need ~42 calls/minute = 0.7 calls/second
- Each worker handles 10 calls/second
- Need 1 worker (but run 2 for redundancy)
```

## High Availability Configuration

### Docker Compose (Single Host)

```yaml
services:
  dialer-worker:
    deploy:
      replicas: 3
      restart_policy:
        condition: on-failure
        delay: 5s
        max_attempts: 3
    healthcheck:
      test: ["CMD", "wget", "-q", "-O", "-", "http://localhost:8080/health"]
      interval: 10s
      timeout: 5s
      retries: 3
      start_period: 30s
```

### Kubernetes (Multi-Host)

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: dialer-worker
spec:
  replicas: 5
  selector:
    matchLabels:
      app: dialer-worker
  template:
    metadata:
      labels:
        app: dialer-worker
    spec:
      containers:
      - name: worker
        image: opbx/dialer-worker:latest
        env:
        - name: WORKER_ID
          valueFrom:
            fieldRef:
              fieldPath: metadata.name
        - name: LARAVEL_API_URL
          value: "http://nginx/api/v1"
        - name: REDIS_ADDR
          value: "redis:6379"
        resources:
          requests:
            memory: "256Mi"
            cpu: "250m"
          limits:
            memory: "512Mi"
            cpu: "500m"
        livenessProbe:
          httpGet:
            path: /health
            port: 8080
          initialDelaySeconds: 10
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /ready
            port: 8080
          initialDelaySeconds: 5
          periodSeconds: 5
---
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: dialer-worker-hpa
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: dialer-worker
  minReplicas: 2
  maxReplicas: 20
  metrics:
  - type: Pods
    pods:
      metric:
        name: dialer_queue_depth
      target:
        type: AverageValue
        averageValue: "10"
```

## Troubleshooting

### Workers Competing for Same Destinations

**Symptom:** Duplicate calls to same number
**Cause:** Multiple workers polling same Laravel endpoint without coordination
**Fix:** Implement Redis work queue or Laravel assignment API

### Worker Crashes with Unprocessed Destinations

**Symptom:** Destinations stuck in "processing" state
**Fix:** Implement heartbeat timeout and destination reclamation

```go
// Reclaim destinations from dead workers
func (w *WorkQueue) ReclaimDeadWorkerDestinations(ctx context.Context, deadWorkerID string) error {
    processingKey := fmt.Sprintf("dialer:destinations:processing:%s", deadWorkerID)
    
    // Move all from dead worker's processing list back to pending
    for {
        result, err := w.redis.RPopLPush(ctx, processingKey, w.queueKey).Result()
        if err == redis.Nil {
            break
        }
        if err != nil {
            return err
        }
        log.Info().Str("task", result).Msg("Reclaimed destination from dead worker")
    }
    return nil
}
```

### Uneven Load Distribution

**Symptom:** One worker busy, others idle
**Cause:** Simple round-robin doesn't account for call duration
**Fix:** Use Redis BRPOP for natural load balancing

## Migration Path

### From Single Worker to Multiple Workers

1. **Phase 1:** Add Redis queue support (backward compatible)
2. **Phase 2:** Deploy 2nd worker with `DISABLE_POLLING=true`
3. **Phase 3:** Verify both workers process from queue
4. **Phase 4:** Update 1st worker to use queue
5. **Phase 5:** Scale to N workers

### Backward Compatibility

```go
// Worker can operate in both modes
if os.Getenv("USE_REDIS_QUEUE") == "true" {
    // Use new queue-based execution
    worker.RunQueueMode()
} else {
    // Use legacy polling mode
    worker.RunPollingMode()
}
```

## Summary

| Approach | Complexity | Scalability | Fault Tolerance | Best For |
|----------|-----------|-------------|-----------------|----------|
| Single Worker | Low | 1 node | Low | Development, low volume |
| Redis Queue | Medium | 100+ nodes | High | Production, high volume |
| Laravel Assignment | Medium | 10+ nodes | Medium | Simple deployments |
| Campaign Sharding | Low | 10 nodes | Medium | Few large campaigns |

**Recommendation:** Use Redis Work Queue for production deployments with 3+ workers.
