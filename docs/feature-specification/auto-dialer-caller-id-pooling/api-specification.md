# Caller ID Pooling - API Specification

## Overview
API changes and additions for the Caller ID Pooling feature.

---

## 1. Modified Endpoints

### 1.1 Create Campaign (POST /v1/auto-dialer-campaigns)

**Changes:** Accepts new `caller_id_pool` and `caller_id_strategy` fields.

**Request Body:**
```json
{
  "name": "Marketing Campaign Q2",
  "routing_destination_type": "ai_assistant",
  "routing_destination_id": 123,
  "caller_id_pool": [
    {"did_id": 1, "weight": 1},
    {"did_id": 2, "weight": 2},
    {"did_id": 3, "weight": 1}
  ],
  "caller_id_strategy": "round_robin",
  "concurrent_active_calls": 10,
  "calls_per_second": 2,
  "max_dial_attempts": 3
}
```

**Validation:**
- `caller_id_pool`: required array, 1-100 items
- `caller_id_pool.*.did_id`: must exist in `did_numbers`, belong to same org, status = 'active'
- `caller_id_pool.*.weight`: integer, min 1, max 100
- `caller_id_strategy`: enum ['round_robin', 'random', 'least_recently_used']

**Response (201):**
```json
{
  "id": 456,
  "name": "Marketing Campaign Q2",
  "caller_id_pool": [
    {"did_id": 1, "phone_number": "+1234567890", "weight": 1},
    {"did_id": 2, "phone_number": "+1234567891", "weight": 2},
    {"did_id": 3, "phone_number": "+1234567892", "weight": 1}
  ],
  "caller_id_strategy": "round_robin",
  "caller_id_pool_enabled": true,
  ...
}
```

---

### 1.2 Update Campaign (PUT /v1/auto-dialer-campaigns/{campaign})

**Changes:** Caller ID pool can be modified when campaign is in DRAFT or PAUSED state.

**Constraints:**
- Cannot modify pool when campaign is ACTIVE
- Returns 409 Conflict if attempted on active campaign

**Request Body:**
```json
{
  "name": "Updated Campaign Name",
  "caller_id_pool": [
    {"did_id": 1, "weight": 1},
    {"did_id": 4, "weight": 1}
  ],
  "caller_id_strategy": "random"
}
```

---

### 1.3 Get Campaign (GET /v1/auto-dialer-campaigns/{campaign})

**Response includes:**
```json
{
  "id": 456,
  "name": "Marketing Campaign Q2",
  "caller_id_pool": [
    {
      "did_id": 1,
      "phone_number": "+1234567890",
      "friendly_name": "Sales Line 1",
      "weight": 1
    },
    {
      "did_id": 2,
      "phone_number": "+1234567891",
      "friendly_name": "Sales Line 2",
      "weight": 2
    }
  ],
  "caller_id_strategy": "round_robin",
  "caller_id_pool_enabled": true,
  "caller_id_stats": {
    "total_calls": 150,
    "by_did": [
      {"did_id": 1, "total_calls": 50, "completed": 45, "failed": 5},
      {"did_id": 2, "total_calls": 100, "completed": 92, "failed": 8}
    ]
  },
  ...
}
```

---

## 2. New Endpoints

### 2.1 Get Available DIDs for Pool

**GET** `/v1/auto-dialer-campaigns/available-caller-ids`

Returns active DIDs that can be added to a campaign's Caller ID pool.

**Query Parameters:**
- `exclude_campaign_id` (optional): Exclude DIDs already in this campaign's pool

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "phone_number": "+1234567890",
      "friendly_name": "Sales Line 1",
      "status": "active"
    },
    {
      "id": 2,
      "phone_number": "+1234567891",
      "friendly_name": "Sales Line 2",
      "status": "active"
    }
  ]
}
```

---

### 2.2 Get Caller ID Statistics

**GET** `/v1/auto-dialer-campaigns/{campaign}/caller-id-stats`

Returns detailed statistics per Caller ID for the campaign.

**Response (200):**
```json
{
  "campaign_id": 456,
  "total_calls": 150,
  "strategy": "round_robin",
  "stats": [
    {
      "did_id": 1,
      "phone_number": "+1234567890",
      "friendly_name": "Sales Line 1",
      "total_calls": 50,
      "completed_calls": 45,
      "failed_calls": 5,
      "success_rate": 90.0,
      "last_used_at": "2026-04-10T14:30:00Z"
    },
    {
      "did_id": 2,
      "phone_number": "+1234567891",
      "friendly_name": "Sales Line 2",
      "total_calls": 100,
      "completed_calls": 92,
      "failed_calls": 8,
      "success_rate": 92.0,
      "last_used_at": "2026-04-10T14:35:00Z"
    }
  ]
}
```

---

### 2.3 Reset Caller ID Cycle

**POST** `/v1/auto-dialer-campaigns/{campaign}/reset-caller-id-cycle`

Resets the cycle position for a campaign (Round Robin only). Requires campaign to be PAUSED.

**Request Body:** None

**Response (200):**
```json
{
  "message": "Caller ID cycle reset successfully",
  "campaign_id": 456,
  "strategy": "round_robin",
  "next_index": 0
}
```

**Error Responses:**
- `409 Conflict`: Campaign is not PAUSED

---

## 3. Dialer Worker API Changes

### 3.1 Get Active Campaigns (GET /v1/dialer/worker/campaigns/active)

**Modified Response:**
```json
{
  "data": [
    {
      "id": 456,
      "name": "Marketing Campaign Q2",
      "caller_id_pool_enabled": true,
      "caller_id_pool": [
        {"did_id": 1, "phone_number": "+1234567890", "weight": 1},
        {"did_id": 2, "phone_number": "+1234567891", "weight": 2}
      ],
      "caller_id_strategy": "round_robin",
      "cac": 10,
      "cps": 2,
      ...
    }
  ]
}
```

---

### 3.2 Initiate Call (POST /v1/dialer/worker/calls/initiate)

**Request (unchanged):**
```json
{
  "campaign_id": 456,
  "destination_id": 789,
  "phone_number": "+15551234567"
}
```

**Response (200):**
```json
{
  "session_token": "abc-123-def",
  "call_id": "call-xyz-789",
  "caller_id": "+1234567890",
  "caller_did_id": 1,
  "status": "initiated"
}
```

**Note:** Laravel selects the appropriate Caller ID based on strategy and returns it in the response.

---

## 4. Webhook Integration

### 4.1 CDR Webhook Processing

When processing CDR webhooks, the system updates Caller ID statistics:

```php
// In CloudonixWebhookController or DialerWebhookProxyController
$callerDidId = $session->caller_did_id;
if ($callerDidId) {
    AutoDialerCallerIdStat::updateStats(
        campaignId: $campaign->id,
        didId: $callerDidId,
        status: $disposition
    );
}
```

---

## 5. Error Codes

| Code | Endpoint | Description |
|------|----------|-------------|
| 400 | All | Invalid Caller ID pool format |
| 409 | PUT /campaigns/{id} | Cannot modify pool on active campaign |
| 422 | POST /campaigns | DID not found or not active |
| 422 | POST /campaigns | Maximum 100 Caller IDs exceeded |
| 422 | POST /campaigns | DID belongs to different organization |
| 409 | POST /reset-caller-id-cycle | Campaign not PAUSED |

---

## 6. Rate Limiting

All Caller ID pool endpoints use the existing rate limiting:
- `rate_limit_org:api` for user-facing endpoints
- `throttle:dialer-worker` for worker endpoints
