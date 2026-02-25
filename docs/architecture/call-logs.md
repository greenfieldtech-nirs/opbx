# Call Logs Architecture Documentation

## Overview

The OPBX system uses **two separate database tables** to track call information:

1. **`call_logs`** - Real-time call tracking with PBX entity relationships
2. **`call_detail_records`** - Historical CDR storage from Cloudonix webhooks

This document explains why both tables exist, how they correlate, and when to use each.

---

## Table Comparison

| Aspect | `call_logs` | `call_detail_records` |
|--------|-------------|----------------------|
| **Purpose** | Track active/live calls with PBX routing context | Store completed call records from carrier |
| **Created When** | Call starts (inbound webhook) | Call ends (CDR webhook) |
| **Updated** | Throughout call lifecycle (ringing, answered, ended) | Never updated (immutable record) |
| **Primary Use Case** | Live call monitoring, real-time dashboards | Historical reporting, billing, detailed analysis |
| **Data Source** | OPBX internal routing decisions | Cloudonix CDR webhook payload |

---

## Schema Comparison

### Common Fields (Correlated via `call_id`)

| Field | `call_logs` | `call_detail_records` | Notes |
|-------|-------------|----------------------|-------|
| `call_id` | ✅ Unique string | ✅ Indexed string | **Same Cloudonix call ID** |
| `organization_id` | ✅ Foreign key | ✅ Foreign key | Tenant isolation |
| `from_number` | ✅ (20 chars) | ✅ `from` (100 chars) | Caller number |
| `to_number` | ✅ (20 chars) | ✅ `to` (100 chars) | Called number |
| `duration` | ✅ Integer seconds | ✅ Integer seconds | Call duration |
| `raw_cdr` | ✅ JSON | ✅ JSON | Complete webhook payload |
| `created_at` | ✅ Timestamp | ✅ Timestamp | Record creation time |

### Unique to `call_logs`

| Field | Purpose |
|-------|---------|
| `did_id` | Foreign key to DID number (for routing context) |
| `extension_id` | Foreign key to extension (who answered) |
| `ring_group_id` | Foreign key to ring group (if routed there) |
| `direction` | inbound/outbound (enum) |
| `status` | initiated, ringing, answered, completed, busy, no_answer, failed |
| `initiated_at` | When call started |
| `answered_at` | When call was answered |
| `ended_at` | When call ended |
| `recording_url` | URL to call recording |

### Unique to `call_detail_records`

| Field | Purpose |
|-------|---------|
| `disposition` | ANSWERED, BUSY, NO ANSWER, etc. (carrier terminology) |
| `billsec` | Billable seconds (actual talk time) |
| `session_timestamp` | Primary timestamp from Cloudonix |
| `session_token` | Cloudonix session token for API lookups |
| `rated_cost` | Cost per carrier pricing |
| `approx_cost` | Estimated cost |
| `sell_cost` | Retail cost to customer |
| `domain` | Cloudonix domain |
| `cx_trunk_id` | Carrier trunk ID |
| `application` | Which OPBX application handled the call |
| `route` | Routing decision info |

---

## Data Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│                     INBOUND CALL WEBHOOK                             │
│         (call_id: "abc123", from: "+1234", to: "+5678")              │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│              ProcessInboundCallJob (Queue)                           │
│  ┌───────────────────────────────────────────────────────────────┐  │
│  │ Creates: call_logs record                                     │  │
│  │   - call_id: "abc123"                                         │  │
│  │   - status: "initiated"                                       │  │
│  │   - did_id: 123 (linked to DID for routing context)           │  │
│  │   - initiated_at: timestamp                                   │  │
│  └───────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼ (Call progresses through IVR, Ring Groups)
┌─────────────────────────────────────────────────────────────────────┐
│              UpdateCallStatusJob (Queue)                             │
│  ┌───────────────────────────────────────────────────────────────┐  │
│  │ Updates: call_logs.status                                     │  │
│  │   - "ringing" → "answered" → "completed"                      │  │
│  │   - extension_id: set when answered                           │  │
│  └───────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼ (Call ends - CDR webhook from Cloudonix)
┌─────────────────────────────────────────────────────────────────────┐
│                  ProcessCDRJob (Queue)                               │
│  ┌───────────────────────────────────────────────────────────────┐  │
│  │ Creates: call_detail_records record                           │  │
│  │   - call_id: "abc123" (links to call_logs)                    │  │
│  │   - disposition: "ANSWERED"                                   │  │
│  │   - duration: 120, billsec: 115                               │  │
│  │   - rated_cost: 0.0500                                        │  │
│  │   - raw_cdr: {complete Cloudonix payload}                     │  │
│  └───────────────────────────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────────────────────────┐  │
│  │ Updates: call_logs (backwards compatibility)                  │  │
│  │   - cloudonix_cdr: {same raw data}                            │  │
│  │   - duration: 120 (if not already set)                        │  │
│  └───────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

---

## When to Use Each Table

### Use `call_detail_records` (CDR) when:
- Displaying historical call logs to users
- Generating billing reports
- Exporting call data to CSV
- Analyzing call costs and durations
- Viewing raw CDR details with application executions

**Frontend:** `CallLogs.tsx` page uses `cdrService` which queries this table.

### Use `call_logs` when:
- Tracking active/live calls in real-time
- Determining which extension/DID is handling a call
- Checking call status during routing
- Broadcasting call events via WebSocket

**Note:** Currently, the Live Calls page uses `session_updates` table instead, but `call_logs` is still maintained for potential future use.

---

## API Endpoints

### CDR Endpoints (Active)
```
GET /api/v1/call-detail-records          # List CDRs (paginated)
GET /api/v1/call-detail-records/{id}     # Get single CDR with raw_cdr
GET /api/v1/call-detail-records/stats    # CDR statistics
GET /api/v1/call-detail-records/export   # Export to CSV
```

### Call Logs Endpoints (Legacy/Maintenance Mode)
```
GET /api/v1/call-logs                    # List call logs
GET /api/v1/call-logs/{id}               # Get single call log
GET /api/v1/call-logs/active             # Active calls
GET /api/v1/call-logs/statistics         # Call statistics
```

**Important:** The frontend currently uses CDR endpoints exclusively. Call Logs endpoints are maintained for backwards compatibility but may be deprecated in the future.

---

## Frontend Services

### `cdr.service.ts` (Active)
Used by CallLogs.tsx for:
- Fetching CDR list with filters
- Getting single CDR with raw data
- Exporting to CSV

### `callLogs.service.ts` (Legacy)
Not currently used by any page. Contains methods for:
- Call log statistics
- Active calls (legacy endpoint)
- Dashboard stats
- CSV export (legacy)

---

## Known Issues & Considerations

### 1. Relationship Data Gap
`call_detail_records` does NOT store foreign keys to DID, Extension, or Ring Group. It only stores phone numbers as strings.

**Impact:** If a DID number is deleted or changed, the CDR record still shows the original number, but loses the relationship context.

**Future Enhancement:** Consider adding denormalized fields to CDR:
- `did_number` (string, not FK)
- `extension_number` (string)
- `ring_group_name` (string)

### 2. Disposition Value Inconsistency
Cloudonix sends disposition values in different formats:
- `ANSWERED` / `ANSWER`
- `NO ANSWER` / `NOANSWER`
- `CANCEL` / `CANCELLED`

**Current Solution:** Frontend normalizes these in `formatters.ts`:
```typescript
const dispositionColors: Record<string, string> = {
  ANSWERED: 'bg-green-100...',
  ANSWER: 'bg-green-100...',  // Alias
  // ...
};
```

### 3. Type Duplication
`CallLog` interface is defined in both:
- `types/index.ts`
- `types/api.types.ts`

Only the one in `api.types.ts` is actually used by the frontend.

---

## Migration History

| Date | Migration | Purpose |
|------|-----------|---------|
| 2024-01-01 | `create_call_logs_table` | Original call tracking |
| 2025-12-28 | `create_call_detail_records_table` | Dedicated CDR storage from Cloudonix |

The CDR table was created to separate concerns:
- `call_logs` for OPBX routing logic
- `call_detail_records` for carrier billing data

---

## Recommendations

### For Developers

1. **Use CDR for new features** involving historical call data
2. **Reference this document** when confused about which table to query
3. **Link via `call_id`** if you need to correlate between tables

### For Future Maintenance

1. **Consider deprecating** `call_logs` table if Live Calls moves fully to `session_updates`
2. **Add denormalized fields** to CDR for DID/Extension numbers (strings, not FKs)
3. **Consolidate types** by removing duplicate `CallLog` interface

---

## Related Documentation

- `docs/architecture/realtime-websockets.md` - WebSocket call presence
- `docs/SERVICE_INTERFACE.md` - API contracts
- `app/Jobs/ProcessCDRJob.php` - CDR processing logic
- `app/Jobs/ProcessInboundCallJob.php` - Call log creation logic

---

**Last Updated:** 2026-02-25
**Author:** OPBX Development Team
