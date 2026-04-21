# Call Detail Records (CDR)

## Overview
Primary call records created from Cloudonix CDR webhooks. Supports listing, filtering, CSV export, statistics, and **AMD (Answering Machine Detection) result display**.

## Source Files
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/CallDetailRecordController.php` | CRUD + export + stats + CXML events |
| `app/Models/CallDetailRecord.php` | CDR model with `amd_status` accessor |
| `app/Models/CallNotificationLog.php` | CXML proxy event logs |
| `app/Jobs/ProcessCDRJob.php` | Async CDR processing from webhooks |
| `app/Http/Resources/CallDetailRecordResource.php` | API response transformer (includes `amd_status`) |

## Database: `call_detail_records` Table
Key columns: organization_id, call_id, session_id, from, to, disposition, duration, billsec, session_timestamp, rated_cost, approx_cost, sell_cost (decimal:4), raw_cdr (JSON - full Cloudonix payload)

**Note**: NOW USES `#[ScopedBy([OrganizationScope::class])]` global scope. Also retains explicit `forOrganization()` scope method.

## AMD Status (New)

The `amd_status` accessor reads from `raw_cdr.session.profile.amd`:

```php
public function getAmdStatusAttribute(): string
{
    $rawCdr = $this->raw_cdr ?? [];
    $session = $rawCdr['session'] ?? [];
    $profile = $session['profile'] ?? [];
    $amd = $profile['amd'] ?? null;

    if ($amd && isset($amd['result'])) {
        return 'Enabled::' . $amd['result'];  // e.g. "Enabled::voicemail"
    }

    return 'Disabled';
}
```

The AMD result is written to the Cloudonix session profile **before** the action is executed. When the call ends, Cloudonix includes the full profile in the CDR webhook, so the result is permanently stored.

### Profile Data Format
```json
{
  "amd": {
    "result": "voicemail",
    "confidence": 0.9,
    "detectionTimeMs": 13487,
    "reason": "Tone detected in 300-1000Hz for 400ms (cv=0.214 ratio=81.4)",
    "timestamp": "2026-04-18T11:19:25Z"
  }
}
```

## CDR Creation (CallDetailRecord::createFromWebhook, line 127)
Maps Cloudonix payload: `session.callStartTime`/`callEndTime`/`callAnswerTime` from milliseconds to Carbon. Stores full payload in `raw_cdr`.

## API Routes
| Method | URI | Purpose |
|--------|-----|---------|
| Standard CRUD | `/v1/cdr[/{cdr}]` | apiResource (index/show only) |
| GET | `/v1/cdr/export` | CSV export (streams, chunks of 1000) |
| GET | `/v1/cdr/statistics` | Aggregate stats |
| GET | `/v1/cdr/{cdr}/cxml-events` | CXML proxy events for the CDR session |

## API Response (CallDetailRecordResource)

```json
{
  "id": 123,
  "session_timestamp": "2026-04-18T12:01:45Z",
  "from": "1004",
  "to": "99999",
  "disposition": "ANSWER",
  "duration": 15,
  "duration_formatted": "00:15",
  "billsec": 14,
  "billsec_formatted": "00:14",
  "call_id": "Btf0Br0tUDSfYb4UGEWJTA..",
  "amd_status": "Enabled::voicemail",
  "raw_cdr": { /* full Cloudonix payload */ }
}
```

## Filters
from (LIKE), to (LIKE), disposition (exact), from_date/to_date (session_timestamp range)

## Statistics Response
total_calls, total_duration, total_billsec, average_duration, total_cost (sell_cost), by_disposition (grouped counts)

## Related Modules
- [Webhook Processing](webhook-processing.md) - CDR webhooks create records
- [Call Logs](call-logs.md) - Frontend display (uses CDR API)
- [AMD Worker](amd-worker.md) - Writes AMD result to session profile
