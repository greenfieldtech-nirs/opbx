# Auto Dialer Monitoring and Alerting

This document describes the monitoring and alerting setup for the CAC-based auto-dialer.

## Prometheus Metrics

The dialer worker exposes the following metrics at `:9090/metrics`:

### Concurrency Metrics

```
# Current active calls per campaign
dialer_campaign_concurrency{campaign_id="123"} 4

# CAC limit for campaign (constant gauge)
dialer_campaign_cac_limit{campaign_id="123"} 5

# API interval in seconds (60/CAC)
dialer_api_interval_seconds{campaign_id="123"} 12

# Concurrency utilization percentage
dialer_concurrency_utilization_percentage{campaign_id="123"} 80
```

### Call Metrics

```
# Total calls initiated (counter)
dialer_calls_total{campaign_id="123",disposition="answered"} 150
dialer_calls_total{campaign_id="123",disposition="busy"} 10
dialer_calls_total{campaign_id="123",disposition="no_answer"} 5

# Call duration histogram
dialer_call_duration_seconds_bucket{campaign_id="123",le="30"} 100
dialer_call_duration_seconds_bucket{campaign_id="123",le="60"} 145
dialer_call_duration_seconds_count{campaign_id="123"} 150
dialer_call_duration_seconds_sum{campaign_id="123"} 4500
```

### Rate Limiting Metrics

```
# Rate limit pause events (counter)
dialer_rate_limit_pauses_total{campaign_id="123",organization_id="456"} 2

# Circuit breaker state (0=closed, 1=half-open, 2=open)
dialer_circuit_breaker_state{campaign_id="123"} 0

# Circuit breaker trips (counter)
dialer_circuit_breaker_trips_total{campaign_id="123",reason="ai_agent_errors"} 1
```

### API Metrics

```
# Laravel API request duration
# TYPE dialer_laravel_api_request_duration_seconds histogram
dialer_laravel_api_request_duration_seconds_bucket{endpoint="campaigns",status="200",le="0.1"} 95

# Cloudonix API request duration
# TYPE dialer_cloudonix_api_request_duration_seconds histogram
dialer_cloudonix_api_request_duration_seconds_bucket{status="200",le="1.0"} 145
```

## Prometheus Configuration

### prometheus.yml

```yaml
global:
  scrape_interval: 15s
  evaluation_interval: 15s

alerting:
  alertmanagers:
    - static_configs:
        - targets: ['alertmanager:9093']

rule_files:
  - /etc/prometheus/rules/dialer-alerts.yml

scrape_configs:
  - job_name: 'dialer-worker'
    static_configs:
      - targets: ['dialer-worker:9090']
    scrape_interval: 15s
    metrics_path: /metrics
    
  - job_name: 'laravel-app'
    static_configs:
      - targets: ['app:80']
    scrape_interval: 30s
    metrics_path: /metrics
```

### Alert Rules (dialer-alerts.yml)

```yaml
groups:
  - name: dialer-concurrency
    rules:
      # Alert when CAC is exceeded
      - alert: DialerCacExceeded
        expr: dialer_campaign_concurrency > dialer_campaign_cac_limit
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "CAC exceeded for campaign {{ $labels.campaign_id }}"
          description: "Campaign {{ $labels.campaign_id }} has {{ $value }} active calls but CAC is {{ $labels.cac_limit }}"
      
      # Alert when approaching CAC limit
      - alert: DialerCacHigh
        expr: dialer_concurrency_utilization_percentage > 90
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High CAC utilization for campaign {{ $labels.campaign_id }}"
          description: "Campaign {{ $labels.campaign_id }} is at {{ $value }}% of CAC limit"
      
      # Alert on rate limiting
      - alert: DialerRateLimited
        expr: increase(dialer_rate_limit_pauses_total[5m]) > 0
        labels:
          severity: critical
        annotations:
          summary: "Campaign {{ $labels.campaign_id }} rate limited by Cloudonix"
          description: "Campaign was paused due to HTTP 429 errors"
      
      # Alert on circuit breaker open
      - alert: DialerCircuitBreakerOpen
        expr: dialer_circuit_breaker_state == 2
        for: 1m
        labels:
          severity: warning
        annotations:
          summary: "Circuit breaker open for campaign {{ $labels.campaign_id }}"
          description: "Campaign dialing paused due to consecutive errors"
  
  - name: dialer-api
    rules:
      # Alert on slow Cloudonix API calls
      - alert: DialerCloudonixApiSlow
        expr: histogram_quantile(0.95, rate(dialer_cloudonix_api_request_duration_seconds_bucket[5m])) > 5
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "Slow Cloudonix API responses"
          description: "95th percentile response time is {{ $value }}s"
      
      # Alert on failed calls
      - alert: DialerHighFailureRate
        expr: |
          (
            sum(rate(dialer_calls_total{disposition=~"failed|busy|no_answer"}[5m])) 
            /
            sum(rate(dialer_calls_total[5m]))
          ) > 0.3
        for: 10m
        labels:
          severity: warning
        annotations:
          summary: "High call failure rate"
          description: "{{ $value | humanizePercentage }} of calls are failing"
  
  - name: dialer-health
    rules:
      # Alert when worker is down
      - alert: DialerWorkerDown
        expr: up{job="dialer-worker"} == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "Dialer worker is down"
          description: "No metrics received from worker for over 1 minute"
      
      # Alert on no call activity
      - alert: DialerNoActivity
        expr: |
          sum by (campaign_id) (rate(dialer_calls_total[15m])) == 0
          and on(campaign_id)
          dialer_campaign_concurrency > 0
        for: 15m
        labels:
          severity: warning
        annotations:
          summary: "No call activity for active campaign {{ $labels.campaign_id }}"
          description: "Campaign has active calls but no new calls initiated in 15m"
```

## Grafana Dashboard

### Key Panels

1. **Concurrency Overview**
   - Time series: Active calls per campaign
   - Gauge: CAC utilization percentage
   - Table: Campaign status summary

2. **Call Performance**
   - Graph: Calls per minute by disposition
   - Histogram: Call duration distribution
   - Stat: Total calls completed today

3. **API Health**
   - Graph: Cloudonix API response times
   - Stat: Rate limit events (5m)
   - Graph: Laravel API errors

4. **System Health**
   - Status: Worker connectivity
   - Graph: Memory and CPU usage
   - Log stream: Recent errors

## Alert Channels

### Slack Integration

```yaml
# alertmanager.yml
receivers:
  - name: 'dialer-alerts'
    slack_configs:
      - api_url: 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL'
        channel: '#dialer-alerts'
        title: '{{ range .Alerts }}{{ .Annotations.summary }}{{ end }}'
        text: '{{ range .Alerts }}{{ .Annotations.description }}{{ end }}'
        send_resolved: true
```

### Email Integration

```yaml
receivers:
  - name: 'dialer-team'
    email_configs:
      - to: 'dialer-ops@example.com'
        from: 'alerts@example.com'
        smarthost: 'smtp.example.com:587'
        auth_username: 'alerts@example.com'
        auth_password: 'your-password'
        headers:
          Subject: 'Auto Dialer Alert: {{ .GroupLabels.alertname }}'
```

## Runbooks

### Alert: DialerCacExceeded

**Impact**: Campaign is exceeding concurrent active calls limit

**Investigation**:
```bash
# Check current state
redis-cli get campaign:{id}:concurrency_counter
redis-cli smembers campaign:{id}:active_sessions

# Check worker logs
docker logs dialer-worker | grep "campaign_id":"{id}"
```

**Resolution**:
1. If counter is stuck: Reset with `redis-cli del campaign:{id}:concurrency_counter`
2. If CDRs not processing: Restart CDR consumer
3. If legitimate overflow: Increase CAC or wait for calls to complete

### Alert: DialerRateLimited

**Impact**: Campaign paused due to Cloudonix HTTP 429

**Investigation**:
```bash
# Check campaign status
curl /api/v1/auto-dialer-campaigns/{id}/concurrency

# View recent CDRs
docker logs dialer-worker | grep -i "rate limit"
```

**Resolution**:
1. Wait for 300-second cooldown to expire
2. Verify campaign CAC is appropriate
3. Resume campaign manually if needed
4. If persistent: Contact Cloudonix support

### Alert: DialerWorkerDown

**Impact**: No campaigns are being processed

**Investigation**:
```bash
# Check container status
docker ps | grep dialer-worker

# Check logs
docker logs --tail=100 dialer-worker

# Test connectivity
docker exec dialer-worker wget -O- http://nginx/api/v1/health
```

**Resolution**:
1. Restart worker: `docker restart dialer-worker`
2. Check Redis connectivity
3. Verify Laravel API token is valid
4. Check for resource exhaustion (memory, CPU)

## Log Aggregation

### Structured Logging

Worker logs use structured JSON format:

```json
{
  "level": "info",
  "component": "executor",
  "campaign_id": 123,
  "session_id": 456,
  "call_id": "call_abc123",
  "message": "Outbound call initiated successfully",
  "timestamp": "2026-03-31T14:30:00Z"
}
```

### LogQL Queries (Loki)

```
# Find rate limiting events
{job="dialer-worker"} |= "rate limit" |= "429"

# Find failed calls for a campaign
{job="dialer-worker"} |= "campaign_id":"123" |= "failed"

# Find slow API calls
{job="dialer-worker"} |= "duration_ms" > 5000

# Find circuit breaker events
{job="dialer-worker"} |= "circuit breaker"
```

## Health Check Endpoint

The worker provides a health endpoint at `GET /status`:

```json
{
  "status": "healthy",
  "version": "1.0.0",
  "uptime": "2h45m",
  "active_campaigns": 3,
  "active_calls": 12,
  "queue_depth": 0,
  "circuit_breakers": {
    "123": "closed",
    "456": "closed"
  },
  "redis_connected": true,
  "laravel_api_reachable": true
}
```

### Kubernetes Liveness/Readiness Probes

```yaml
livenessProbe:
  httpGet:
    path: /status
    port: 8080
  initialDelaySeconds: 30
  periodSeconds: 10
  timeoutSeconds: 5
  failureThreshold: 3

readinessProbe:
  httpGet:
    path: /status
    port: 8080
  initialDelaySeconds: 5
  periodSeconds: 5
  timeoutSeconds: 3
  failureThreshold: 2
```
