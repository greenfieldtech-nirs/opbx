# Live Calls / Session Updates

## Overview
Real-time call monitoring via Cloudonix session-update webhooks. Supports active call listing, statistics, and call disconnection.

## Source Files
| File | Purpose |
|------|---------|
| `app/Http/Controllers/Api/SessionUpdateController.php` | Active calls + stats + disconnect (553 lines) |
| `app/Models/SessionUpdate.php` | Session event model (159 lines) |
| `app/Services/CallStateManager/CallStateManager.php` | Redis-based state with distributed locking (310 lines) |
| `frontend/src/pages/LiveCalls.tsx` | Real-time monitoring page with WebSocket + HTTP polling merge, disconnect all, stale cleanup, refresh timer |
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

### Disconnect All Calls
- Confirmation dialog with danger styling
- Disconnects calls sequentially with 200ms delay
- Marks all call IDs as `recentlyDisconnected` with a 10-second grace period
- Clears local state, WebSocket state, and TanStack Query cache immediately for instant feedback
- Both HTTP polling and WebSocket merge effects filter out `recentlyDisconnected` calls
- After the grace period expires, call IDs are removed from the filter set so genuinely new calls can appear
- Progress overlay shows disconnected count
- "Clear Stale" button for WebSocket-only records that cannot be API-disconnected

### Single Disconnect
- Also marks the individual call as `recentlyDisconnected` and optimistically removes it from local state
- Prevents the call from reappearing while Cloudonix processes the disconnection

## CallStateManager
Redis keys: `lock:call:{callId}`, `call:state:{callId}`
State transitions validated: INITIATED->{RINGING,FAILED,BUSY}, RINGING->{ANSWERED,NO_ANSWER,BUSY,FAILED}, ANSWERED->{COMPLETED,FAILED}

## Real-Time Updates
- WebSocket (Soketi) provides live call events via `useCallPresence()`
- HTTP polling fallback with configurable interval (0/1s/5s/15s/30s/60s)
- WebSocket and HTTP data merged: HTTP provides initial state, WebSocket provides incremental updates
- `RefreshTimer` component shows progress bar + countdown to next refresh

## Related Modules
- [Webhook Processing](webhook-processing.md) - Session updates come from webhooks
- [WebSocket Real-Time](websocket-realtime.md) - Live updates via WebSocket
- [Settings & Cloudonix](settings-cloudonix.md) - CloudonixClient for disconnect
