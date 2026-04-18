# Live Calls / Session Updates

## Overview
Real-time call monitoring via Cloudonix session-update webhooks. Supports active call listing, statistics, and call disconnection.

## Source Files
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/SessionUpdateController.php` | Active calls + stats + disconnect (553 lines) |
| `app/Models/SessionUpdate.php` | Session event model (159 lines) |
| `app/Services/CallStateManager/CallStateManager.php` | Redis-based state with distributed locking (310 lines) |
| `frontend/src/pages/LiveCalls.tsx` | Real-time monitoring page |
| `frontend/src/services/sessionUpdates.service.ts` | Polling API |
| `frontend/src/components/design-system/ActiveCallCard.tsx` | Call display card |
| `frontend/src/components/design-system/CallStatusBadge.tsx` | Status badges |

## Database: `session_updates` Table
Key columns: organization_id, session_id, session_token (for Cloudonix API), event_id (idempotency), status, direction, caller_id, destination, call_ids (JSON), profile (JSON with QoS), session_created_at, session_modified_at, start_time, answer_time

## Active Calls Query (SessionUpdateController:33)
Complex raw query:
1. Find completed/deleted session IDs in last 24h
2. Get latest update per active session using `MAX(id)` subquery
3. Exclude completed sessions
4. Only consider calls updated in last 30 minutes (stale prevention)
5. Apply status/direction filters
6. Limit to 100 results

## Call Disconnection (SessionUpdateController:368)
Requires admin/owner/pbx_admin. Finds session_token from DB. Calls `CloudonixClient::disconnectSession()`.

## CallStateManager
Redis keys: `lock:call:{callId}`, `call:state:{callId}`
State transitions validated: INITIATED->{RINGING,FAILED,BUSY}, RINGING->{ANSWERED,NO_ANSWER,BUSY,FAILED}, ANSWERED->{COMPLETED,FAILED}

## Related Modules
- [Webhook Processing](webhook-processing.md) - Session updates come from webhooks
- [WebSocket Real-Time](websocket-realtime.md) - Live updates via WebSocket
- [Settings & Cloudonix](settings-cloudonix.md) - CloudonixClient for disconnect
