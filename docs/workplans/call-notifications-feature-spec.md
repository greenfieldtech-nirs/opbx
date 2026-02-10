# Call Notifications Feature Specification

## Overview

The Call Notifications feature provides real-time webhook notifications to organization-defined endpoints whenever a call session transitions between specific states. This enables external systems to track call lifecycle events, monitor call quality, and integrate call data with CRM, analytics, or billing systems.

## Objectives

1. Provide real-time call state change notifications via HTTP POST webhooks
2. Support configurable endpoint URLs per organization
3. Track and report comprehensive call metrics
4. Ensure reliable delivery with retry mechanisms
5. Maintain security through authentication and payload signing

## Functional Requirements

### 1. Monitored Session States

The system SHALL trigger notifications when a session transitions to any of the following states:

| State | Description |
|-------|-------------|
| `new` | Session created, call initiated |
| `ringing` | Destination is ringing |
| `connected` | Call connected, media flowing |
| `answered` | Call answered by destination |
| `busy` | Destination returned busy signal |
| `cancel` | Call cancelled by originator |
| `failed` | Call failed to connect |
| `congestion` | Network congestion prevented connection |

### 2. Notification Payload

Each webhook notification SHALL include the following session properties:

```json
{
  "event_type": "call.status_update",
  "event_id": "uuid-v4",
  "timestamp": "2026-02-10T12:00:00Z",
  "organization_id": "123",
  "session": {
    "call_session_token": "unique-session-id",
    "from": "+12125551234",
    "to": "+12125555678",
    "direction": "inbound|outbound",
    "call_start_time": "2026-02-10T12:00:00Z",
    "call_answer_time": "2026-02-10T12:00:15Z",
    "call_end_time": null,
    "call_duration": 0,
    "call_billable_duration": 0,
    "status": "ringing",
    "previous_status": "new"
  },
  "metadata": {
    "caller_name": "John Doe",
    "extension_id": "456",
    "did_id": "789"
  }
}
```

**Field Definitions:**

| Field | Type | Description |
|-------|------|-------------|
| `event_type` | string | Fixed value: `call.status_update` |
| `event_id` | string | Unique identifier for this event (UUID v4) |
| `timestamp` | ISO8601 | When the event was generated |
| `organization_id` | string | Organization identifier |
| `session.call_session_token` | string | Cloudonix call session ID |
| `session.from` | string | Caller number or identifier |
| `session.to` | string | Called number or destination |
| `session.direction` | string | `inbound` or `outbound` |
| `session.call_start_time` | ISO8601 | When call was initiated |
| `session.call_answer_time` | ISO8601 | When call was answered (null if not answered) |
| `session.call_end_time` | ISO8601 | When call ended (null if still active) |
| `session.call_duration` | integer | Total duration in seconds (0 until ended) |
| `session.call_billable_duration` | integer | Billable duration in seconds (0 until ended) |
| `session.status` | string | Current session status |
| `session.previous_status` | string | Previous session status |
| `metadata.caller_name` | string | Display name of caller |
| `metadata.extension_id` | string | Associated extension ID |
| `metadata.did_id` | string | Associated DID number ID |

### 3. Configuration

Each organization SHALL be able to configure:

- **Webhook URL**: HTTPS endpoint to receive notifications
- **Authentication Method**: 
  - HMAC-SHA256 signature verification
  - Bearer token authentication
  - Basic authentication
  - None (not recommended for production)
- **Retry Policy**: Number of retries and backoff strategy
- **Event Filtering**: Optional whitelist/blacklist of status events
- **Timeout**: Request timeout in seconds (default: 30s)

## Technical Architecture

### 1. System Components

```
┌─────────────────────────────────────────────────────────────┐
│                     Cloudonix Platform                       │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐  │
│  │ Call Session │───▶│ Event Router │───▶│ Webhook      │  │
│  │ Monitor      │    │              │    │ Dispatcher   │  │
│  └──────────────┘    └──────────────┘    └──────────────┘  │
│                                                   │         │
└───────────────────────────────────────────────────┼─────────┘
                                                    │
                    HTTP POST                       ▼
                                            ┌──────────────┐
                                            │ Organization │
                                            │ Endpoint     │
                                            └──────────────┘
```

### 2. Data Flow

1. **Session State Change**: Cloudonix reports session update via `/session-update` webhook
2. **Event Processing**: Application processes and normalizes the event
3. **Configuration Lookup**: Retrieve organization's notification settings
4. **Payload Construction**: Build notification payload with current session data
5. **Authentication**: Generate signature/token for request
6. **Dispatch**: Queue webhook delivery job
7. **Delivery**: HTTP POST to organization endpoint
8. **Retry Handling**: Implement exponential backoff on failures

### 3. Database Schema

#### call_notifications_settings table

```sql
CREATE TABLE call_notifications_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    webhook_url VARCHAR(2048) NOT NULL,
    auth_method ENUM('hmac_sha256', 'bearer_token', 'basic_auth', 'none') DEFAULT 'hmac_sha256',
    auth_secret VARCHAR(512) NULL,
    auth_username VARCHAR(255) NULL,
    retry_attempts TINYINT UNSIGNED DEFAULT 3,
    retry_backoff_seconds SMALLINT UNSIGNED DEFAULT 60,
    request_timeout_seconds SMALLINT UNSIGNED DEFAULT 30,
    enabled_events JSON NOT NULL, -- Array of status values to monitor
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_organization (organization_id),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### call_notification_logs table

```sql
CREATE TABLE call_notification_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    call_session_token VARCHAR(255) NOT NULL,
    event_id CHAR(36) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    status VARCHAR(50) NOT NULL,
    webhook_url VARCHAR(2048) NOT NULL,
    request_payload JSON NOT NULL,
    response_status_code SMALLINT UNSIGNED NULL,
    response_body TEXT NULL,
    response_time_ms INT UNSIGNED NULL,
    attempt_number TINYINT UNSIGNED DEFAULT 1,
    is_success BOOLEAN DEFAULT FALSE,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_session_token (call_session_token),
    INDEX idx_organization_time (organization_id, created_at),
    INDEX idx_event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci PARTITION BY RANGE (YEAR(created_at));
```

### 4. Security Requirements

#### HMAC-SHA256 Signature

When using HMAC authentication:

1. Generate signature using request payload + timestamp + secret
2. Include signature in `X-Cloudonix-Signature` header
3. Include timestamp in `X-Cloudonix-Timestamp` header
4. Receiving endpoint SHOULD verify signature

```php
$timestamp = time();
$payload = json_encode($data);
$signedPayload = $timestamp . '.' . $payload;
$signature = hash_hmac('sha256', $signedPayload, $secret);

// Headers:
// X-Cloudonix-Signature: sha256=<signature>
// X-Cloudonix-Timestamp: <timestamp>
```

#### Bearer Token

Include token in Authorization header:

```
Authorization: Bearer <token>
```

#### Basic Authentication

Include Base64-encoded credentials:

```
Authorization: Basic base64(username:password)
```

### 5. Retry Policy

Failed deliveries SHALL be retried with exponential backoff:

| Attempt | Delay | Total Time |
|---------|-------|------------|
| 1 | 0s | 0s |
| 2 | 60s | 60s |
| 3 | 120s | 3m |
| 4 | 240s | 7m |

Maximum retry attempts: 5 (configurable)

Retry triggered on:
- HTTP 5xx errors
- Network timeouts
- Connection failures

No retry on:
- HTTP 4xx errors (client error)
- HTTP 2xx success

### 6. Rate Limiting

- Maximum 100 notifications per minute per organization
- Excess events queued and processed at rate limit
- Organizations can upgrade limits based on plan

## Implementation Phases

### Phase 1: Core Infrastructure

1. Database migrations for settings and logs tables
2. Configuration management service
3. Basic webhook dispatcher with HTTP client
4. Event listener for session updates

### Phase 2: Security & Reliability

1. HMAC signature implementation
2. Retry mechanism with exponential backoff
3. Dead letter queue for failed deliveries
4. Payload validation and sanitization

### Phase 3: Monitoring & Management

1. Admin UI for notification settings
2. Delivery logs viewer
3. Test webhook functionality
4. Metrics and alerting

### Phase 4: Advanced Features

1. Event filtering (include/exclude specific statuses)
2. Custom payload templates
3. Multiple webhook endpoints per organization
4. Webhook batching for high-volume scenarios

## API Endpoints

### Admin Configuration Endpoints

```
GET    /api/v1/call-notifications/settings
PUT    /api/v1/call-notifications/settings
POST   /api/v1/call-notifications/settings/test
GET    /api/v1/call-notifications/logs
GET    /api/v1/call-notifications/logs/{event_id}
```

## Testing Strategy

### Unit Tests
- Payload generation
- Signature calculation
- Retry logic
- Event filtering

### Integration Tests
- End-to-end webhook delivery
- Failure scenarios
- Rate limiting behavior

### Load Tests
- 1000 concurrent webhooks
- Burst handling
- Queue backpressure

## Success Metrics

1. **Delivery Rate**: >99.5% of notifications delivered successfully
2. **Latency**: P95 < 5 seconds from event to delivery
3. **Retry Success**: >80% of retried deliveries succeed
4. **Error Rate**: <0.1% of deliveries result in unrecoverable errors

## Documentation Requirements

1. API reference for configuration endpoints
2. Webhook payload schema documentation
3. Security implementation guide
4. Troubleshooting guide for failed deliveries
5. Sample code for signature verification

## Future Enhancements

1. **Batch Notifications**: Group multiple events into single payload
2. **Custom Headers**: Allow organizations to specify custom HTTP headers
3. **Event Filtering UI**: Visual filter builder in admin panel
4. **Metrics Dashboard**: Real-time delivery statistics
5. **Webhook Analytics**: Success rates, latency trends, error categorization
