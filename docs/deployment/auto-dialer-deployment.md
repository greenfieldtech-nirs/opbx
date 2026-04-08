# Auto Dialer Deployment Guide

This guide covers deploying the CAC-based auto-dialer system to production.

## Prerequisites

- Docker and Docker Compose installed
- Redis server (version 7+)
- MySQL server (version 8+)
- Cloudonix account with API credentials
- Domain configured with SSL certificate

## Phase 1: Database Migration

### 1.1 Backup Existing Data

```bash
# Create database backup
mysqldump -h localhost -u root -p opbx > backup_$(date +%Y%m%d_%H%M%S).sql
```

### 1.2 Run Migrations

```bash
# Deploy application container
docker compose up -d app

# Run migrations
docker compose exec app php artisan migrate --force

# Verify migrations applied
docker compose exec app php artisan migrate:status
```

### Expected Migrations

- `2026_03_31_185020_update_auto_dialer_campaigns_cps_to_cac.php`
- `2026_03_31_192513_add_pause_reason_to_auto_dialer_campaigns.php`

### 1.3 Verify Data Migration

```sql
-- Check that CAC values are set
SELECT id, name, concurrent_active_calls, pause_reason, resume_at
FROM auto_dialer_campaigns
LIMIT 5;
```

## Phase 2: Redis Configuration

### 2.1 Verify Redis Connectivity

```bash
# Test Redis connection
docker compose exec app php artisan tinker --execute="print_r(Redis::connection()->ping());"
```

### 2.2 Configure Redis Persistence

Ensure Redis has appropriate persistence settings in `redis.conf`:

```conf
# Enable AOF for better durability
appendonly yes
appendfsync everysec

# Keep RDB as backup
save 900 1
save 300 10
save 60 10000
```

### 2.3 Set Key Expiration Policy

The dialer automatically sets 7-day expiration on keys:
- `campaign:{id}:concurrency_counter`
- `campaign:{id}:active_sessions`

## Phase 3: Worker Deployment

### 3.1 Build Worker Image

```bash
# Build dialer worker
docker compose build dialer-worker

# Verify image created
docker images | grep dialer-worker
```

### 3.2 Configure Environment Variables

Required variables in `.env`:

```env
# Worker Identity
WORKER_ID=dialer-worker-1
WORKER_API_PORT=8080

# Laravel API
LARAVEL_API_URL=http://nginx/api/v1
LARAVEL_API_TOKEN=your-worker-token-here

# Redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=your-redis-password
REDIS_DB=0

# Worker Settings
MAX_CONCURRENT_CALLS_GLOBAL=1000
DEFAULT_CALL_TIMEOUT=30
LOG_LEVEL=info

# Circuit Breaker
CIRCUIT_BREAKER_THRESHOLD=5
CIRCUIT_BREAKER_TIMEOUT=5
```

### 3.3 Start Worker

```bash
# Start the worker
docker compose up -d dialer-worker

# Check logs
docker compose logs -f dialer-worker
```

### 3.4 Verify Worker Health

```bash
# Check health endpoint
curl http://localhost:8080/status

# Expected response:
# {
#   "status": "healthy",
#   "active_campaigns": 0,
#   "active_calls": 0,
#   "queue_depth": 0
# }
```

## Phase 4: Monitoring Setup

### 4.1 Prometheus Metrics

The worker exposes metrics at `http://worker:9090/metrics`:

```yaml
# prometheus.yml
scrape_configs:
  - job_name: 'dialer-worker'
    static_configs:
      - targets: ['dialer-worker:9090']
    scrape_interval: 15s
```

### 4.2 Key Metrics to Monitor

| Metric | Description | Alert Threshold |
|--------|-------------|-----------------|
| `dialer_campaign_concurrency` | Current active calls per campaign | > CAC * 1.1 |
| `dialer_api_interval_seconds` | Time between API calls | < 2 seconds |
| `dialer_rate_limit_pauses_total` | HTTP 429 pauses | > 0 in 5 min |
| `dialer_calls_total` | Total calls by disposition | N/A |

### 4.3 Grafana Dashboard

Import dashboard JSON (see `monitoring/grafana-dashboard.json`):

- Concurrency utilization by campaign
- API rate limiting events
- Call disposition breakdown
- Error rates

## Phase 5: Testing

### 5.1 Run Unit Tests

```bash
# PHP tests
docker compose exec app php artisan test --filter=AutoDialer

# Go tests
docker compose exec dialer-worker go test ./...
```

### 5.2 Integration Testing

```bash
# Create test campaign
curl -X POST http://localhost/api/v1/auto-dialer-campaigns \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Campaign",
    "concurrent_active_calls": 5,
    "routing_destination_type": "ai_assistant",
    "routing_destination_id": "1",
    "caller_id": "+1234567890",
    "schedule": {...}
  }'

# Start campaign
curl -X PATCH http://localhost/api/v1/auto-dialer-campaigns/1/start \
  -H "Authorization: Bearer $TOKEN"

# Check concurrency status
curl http://localhost/api/v1/auto-dialer-campaigns/1/concurrency \
  -H "Authorization: Bearer $TOKEN"
```

### 5.3 Load Testing

```bash
# Run load test (requires bash and redis-cli locally)
./dialer-worker/tests/load/load_test.sh 10 3

# Expected output:
# - CAC utilization should stay at or below 100%
# - Counter should return to 0 after test
# - No errors in worker logs
```

## Phase 6: Production Checklist

### Before Go-Live

- [ ] Database migrations applied successfully
- [ ] All existing campaigns have CAC values set
- [ ] Redis persistence configured
- [ ] Worker health checks passing
- [ ] Monitoring dashboards configured
- [ ] Alert rules tested
- [ ] Rollback plan documented

### Go-Live Steps

1. **Pre-deployment**
   ```bash
   # Set maintenance mode
   docker compose exec app php artisan down --message="Deploying auto-dialer updates"
   ```

2. **Deploy**
   ```bash
   # Pull latest images
   docker compose pull
   
   # Start services
   docker compose up -d
   
   # Run migrations
   docker compose exec app php artisan migrate --force
   ```

3. **Post-deployment**
   ```bash
   # Verify health
   curl http://localhost/api/v1/health
   
   # Check worker status
   curl http://localhost:8080/status
   
   # Bring out of maintenance
   docker compose exec app php artisan up
   ```

4. **Validation**
   - Create test campaign with CAC=2
   - Verify API interval is 30 seconds
   - Start campaign and verify concurrency counter increments
   - Verify CDR decrements counter

## Rollback Procedure

If issues occur:

```bash
# 1. Stop campaigns
docker compose exec app php artisan auto-dialer:pause-all

# 2. Rollback migrations
docker compose exec app php artisan migrate:rollback \
  --path=database/migrations/2026_03_31_185020_update_auto_dialer_campaigns_cps_to_cac.php

# 3. Restore previous code
git checkout previous-tag
docker compose up -d --build

# 4. Restore database (if needed)
mysql -h localhost -u root -p opbx < backup_YYYYMMDD_HHMMSS.sql
```

## Troubleshooting

### Issue: Counter Not Decrementing

**Symptoms**: Active calls count stays high after CDR received

**Check**:
```bash
# Check Redis keys
redis-cli keys "campaign:*"

# Check CDR consumer is running
docker compose logs dialer-worker | grep -i "cdr"
```

**Resolution**:
- Restart CDR consumer: `docker compose restart dialer-worker`
- Manually reset counter: `redis-cli del campaign:{id}:concurrency_counter`

### Issue: HTTP 429 Errors

**Symptoms**: Campaign paused due to rate limiting

**Check**:
```bash
# Check if CAC is too high for organization limit
curl http://localhost/api/v1/auto-dialer-campaigns/{id}/concurrency
```

**Resolution**:
- Reduce CAC value
- Wait for 300-second cooldown
- Resume campaign manually

### Issue: Worker Not Processing Campaigns

**Symptoms**: Campaigns active but no calls being made

**Check**:
```bash
# Verify worker can reach Laravel API
docker compose exec dialer-worker wget -O- http://nginx/api/v1/health

# Check worker logs
docker compose logs --tail=100 dialer-worker
```

**Resolution**:
- Verify LARAVEL_API_TOKEN is valid
- Check network connectivity between containers
- Restart worker: `docker compose restart dialer-worker`

## Support

For issues or questions:
- Check logs: `docker compose logs -f [service]`
- Review documentation: `/docs/specifications/auto-dialer-worker-specification.md`
- Open issue in repository with logs and configuration
