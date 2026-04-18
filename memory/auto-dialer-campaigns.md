# Auto Dialer Campaigns

## Overview
Outbound calling campaigns that automatically dial phone numbers from distribution lists. Three-tier architecture: Laravel (CRUD + Cloudonix API) -> Go Worker (polling + rate limiting) -> Cloudonix CPaaS (call execution). Supports scheduling, CAC rate limiting, AMD, and retry with exponential backoff.

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `app/Http/Controllers/AutoDialerCampaignController.php` | User-facing CRUD + lifecycle + monitor + caller ID pooling (788 lines) |
| `app/Http/Controllers/DialerWorkerController.php` | Go worker API (1007 lines) |
| `app/Models/AutoDialerCampaign.php` | Campaign model (377 lines) |
| `app/Models/AutoDialerCallSession.php` | Call session tracking |
| `app/Models/AutoDialerCampaignCallerId.php` | Campaign-DID Caller ID pivot |
| `app/Models/AutoDialerCallerIdStat.php` | Per-DID usage statistics |
| `app/Enums/CampaignStatus.php` | DRAFT, ACTIVE, PAUSED, COMPLETED, ARCHIVED |
| `app/Enums/DestinationStatus.php` | PENDING, DIALING, CONNECTED, FAILED, COMPLETED, INVALID |
| `app/Enums/AmdMode.php` | ENABLED, DETECT_MESSAGE_END |
| `app/Services/AutoDialer/CampaignLifecycleManager.php` | State transitions (197 lines) |
| `app/Services/AutoDialer/CampaignProcessor.php` | Batch processing (156 lines) |
| `app/Services/AutoDialer/CampaignStatistics.php` | Stats with caching |
| `app/Services/AutoDialer/DialingScheduler.php` | Schedule checking |
| `app/Services/AutoDialer/AutoDialerCloudonixService.php` | Cloudonix API calls (937 lines) |
| `app/Services/AutoDialer/CallerIdPoolService.php` | Caller ID pool management |
| `app/Services/CxmlBuilder/AutoDialerCxmlBuilder.php` | Campaign CXML generation |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/pages/AutoDialerCampaigns.tsx` | Campaign listing |
| `frontend/src/pages/AutoDialerCampaignDetail.tsx` | Campaign detail view |
| `frontend/src/pages/AutoDialerCampaignForm.tsx` | Create/edit form |
| `frontend/src/services/autoDialerCampaignsApi.ts` | API calls |
| `frontend/src/hooks/useAutoDialerCampaigns.ts` | Campaign queries/mutations |
| `frontend/src/pages/AutoDialerMonitor.tsx` | Real-time monitor (bird's-eye + drill-down) |
| `frontend/src/services/autoDialerMonitorApi.ts` | Monitor API client |
| `frontend/src/hooks/useAutoDialerMonitor.ts` | Monitor queries with configurable refresh |

## Campaign State Machine
```
DRAFT --[start]--> ACTIVE --[pause]--> PAUSED --[resume]--> ACTIVE
                       |                                       |
                       +--[all done]--> COMPLETED              |
                       |                                       |
ANY --[archive]--> ARCHIVED  (pauses first if ACTIVE)
```

## Database Tables
### `auto_dialer_campaigns`
Key columns: organization_id, name, status, routing_destination_type, routing_destination_id, dial_timeout, caller_id, max_dial_attempts, concurrent_active_calls (CAC, 1-50), calls_per_second (CPS, 1-5), days_active (JSON), start_time, end_time, start_date, end_date, timezone, schedule (JSON weekly), amd_enabled, amd_mode, total_destinations, completed_calls, failed_calls, pending_calls

### `auto_dialer_call_sessions`
organization_id, campaign_id, destination_id, phone_number, worker_id, session_token, call_id, status, disposition, duration, billsec, recording_url, amd_result, amd_confidence, initiated_at, answered_at, completed_at

## Rate Limiting Parameters

### CAC (Concurrent Active Calls)
- Integer 1–50, default 1
- Maximum number of simultaneous active calls per campaign
- Go worker won't initiate new calls if active count >= CAC
- Counter in Redis: `dialer:cac:{id}:active`

### CPS (Calls Per Second) — NEW
- Integer 1–5 in UI, 1–30 clamped in Go worker, default 1
- Controls call initiation rate: `1000/CPS` ms between calls
- Batch size per poll cycle: `min(CPS × poll_interval_seconds, CAC)`
- Column: `calls_per_second` in `auto_dialer_campaigns` (Laravel validation allows 1-5)
- Spec: `docs/specifications/auto-dialer-cps-parameter.md`

### CAC Counter Lifecycle
- **Increment**: Go worker calls `Redis INCR dialer:cac:{id}:active` after successful call initiation
- **Decrement**: Laravel webhook controllers call `Redis DECR dialer:cac:{id}:active` when processing call completion/failure events from Cloudonix (floor at 0 to prevent negative drift)
- **Important**: The Go worker does NOT subscribe to any Redis Pub/Sub channel. The `CDRPublisher` publishes to `cdr:completed` but has no subscriber — Laravel handles the decrement directly.

## Routing Destination Types (RoutingDestinationType enum)
AI_ASSISTANT, AI_LOAD_BALANCER, HANGUP — campaigns connect answered calls to these destinations

## Campaign Scheduling (isRunnable, line 223)
A campaign is runnable when: status is ACTIVE, current date within start_date/end_date range, current day/time within the `schedule` JSON's enabled time ranges for today.

## Dialer Worker API Routes (routes/api.php:63-94)
Prefix: `/v1/dialer/worker`, middleware: `dialer.worker.auth`
| Method | URI | Purpose |
|--------|-----|---------|
| GET | `/campaigns/active` | Active runnable campaigns |
| GET | `/campaigns/{id}/destinations/pending` | Pending destinations |
| GET | `/campaigns/{id}/destinations/retry` | Retryable destinations |
| POST | `/calls/initiate` | Initiate call via Cloudonix |
| PATCH | `/calls/{session}/status` | Update call status |
| POST | `/calls/{session}/disposition` | Set final disposition |
| POST | `/campaigns/{id}/pause` | Worker-initiated pause |
| POST | `/calls/generate-cxml` | Generate routing CXML |

## User-Facing API Routes (routes/api.php:276-295)
| Method | URI | Purpose |
|--------|-----|---------|
| Standard CRUD | `/v1/auto-dialer-campaigns[/{campaign}]` | apiResource |
| PATCH | `/{campaign}/start\|pause\|resume\|archive` | Lifecycle actions |
| POST/GET/DELETE | `/{campaign}/list` | Campaign list management |
| GET | `/{campaign}/destinations` | List destinations |
| GET | `/{campaign}/concurrency` | Real-time CAC monitoring |
| GET | `/monitor/summary` | Bird's-eye monitor (all active/paused campaigns) |
| GET | `/{campaign}/monitor/detail` | Campaign drill-down (dispositions, avg duration) |
| GET | `/available-caller-ids` | List available DIDs for Caller ID pooling |
| GET | `/{campaign}/caller-id-stats` | Per-DID usage statistics |
| POST | `/{campaign}/reset-caller-id-cycle` | Reset round-robin position |

## Retry Strategy
Exponential backoff: `5 * 2^(attempt-1)` minutes, capped at 60 minutes. Retryable dispositions: busy, no-answer, cancelled.

### Tests
| File | Purpose |
|------|---------|
| `tests/Feature/AutoDialer/AutoDialerMonitorTest.php` | Monitor endpoint tests (19 tests) |

## Real-Time Monitor
- **Bird's-eye view**: Card-row layout. Each campaign is a full-width Card with:
  - Header: name, status badge, routing label, Pause/Resume button (red=Pause, green=Resume, no confirmation dialog)
  - 5 metric cards: Destinations (computed live from lists), Active Calls, Completed, Failed, Pending
  - Footer: progress bar + rate limit indicator
- **Drill-down** (click campaign card): Campaign Progress at top, 6 KPI cards, Active Calls table (phone/status/duration, last 5min only), Disposition pie chart (SVG)
- Paused campaigns: force 0 active calls, clean up stale sessions on view, empty active calls table
- `total_destinations` computed live from distribution lists (not model column)
- `active_sessions` query outside cache block (always fresh)
- Refresh intervals: Manual, 1s, 5s, 10s (default), 20s-60s. "Refresh Now" button always visible. Persisted in localStorage.
- Worker health proxied from Go worker via `config('services.dialer_worker.health_url')`
- Summary cached 5s, detail statistics cached 10s in Redis

## CXML Generation
- WebSocket URLs: all placeholders ({session}, {from}, {to}, {bot_id}, {auth_token}) resolved before sending to Cloudonix
- CXML Provider: AutoDialerCloudonixService simulates a POST request to the `endpoint_url` as if Cloudonix issued a Voice Application Request, with `From` and `To` swapped because the dialer flow is reversed (`From` = destination phone number, `To` = campaign caller_id). The returned CXML is then passed inline to Cloudonix as `cxml` (not as a `url` parameter)
- AMD mode mapping: `AmdMode::ENABLED` → `Enable` (not `Enabled`), `AmdMode::DETECT_MESSAGE_END` → `DetectMessageEnd`
- CxmlBuilder: `connectStream` does NOT use htmlspecialchars (DOMDocument handles escaping)
- Uses `dialer` Redis connection (prefix-free) for all Go worker shared keys

## Campaign Manager UI
- Status badge is clickable: toggles Active↔Paused. Uses `resume()` for paused→active (not `start()`)
- Edit button: disabled when campaign is Active, enabled for Draft/Paused
- Campaign Detail page: Edit button in header (left of Pause/Resume), 5 stat cards (Total, Completed, Failed, Pending, Progress%), 10s auto-refresh via `useAutoDialerCampaign(id, 10000)`

## Campaign Form
- CAC: text input 1–50 (was fixed-value select)
- CPS: select box 1–5
- Timezone: full list (65+ timezones grouped by region, from `@/utils/timezones`)
- Schedule tab: Timezone 40% width, dates 30% each. WeeklyCalendarView with `expandHeight` prop

## Pause Action Cleanup
- Marks all in-flight sessions (initiated/ringing/answered) as failed/cancelled
- Resets Redis CAC counter to 0
- Uses `dialer` Redis connection (prefix-free)

## Documentation
- User guide: `docs/opbx-userguide/applications/auto-dialer/` (8 pages)
- OpenAPI: `docs/opbx-openapi/paths/auto-dialer-campaigns/` + `paths/distribution-lists/`
- CPS spec: `docs/specifications/auto-dialer-cps-parameter.md`
- Monitor spec: `docs/specifications/auto-dialer-realtime-monitor.md`

## Related Modules
- [Distribution Lists](distribution-lists.md) - Contact lists for campaigns
- [Dialer Worker](dialer-worker.md) - Go worker that executes calls
- [AI Assistants](ai-assistants.md) - Campaign routing destinations
- [Webhook Processing](webhook-processing.md) - CDR webhooks update campaign stats
- [Caller ID Pooling](auto-dialer-caller-id-pooling.md) - Multi-DID Caller ID pools
