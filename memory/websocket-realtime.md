# WebSocket / Real-Time

## Overview
Real-time event broadcasting via Soketi (Pusher-compatible WebSocket server). Used for live call presence tracking. Three broadcast channel types: organization presence, user private, extension private.

## Source Files

### Backend
| File | Purpose |
|------|---------|
| `routes/channels.php` | Channel authorization rules |
| `config/broadcasting.php` | Pusher/Soketi configuration |
| `config/websockets.php` | WebSocket server config (reference) |
| `app/Events/CallInitiated.php` | Broadcast event |
| `app/Events/CallAnswered.php` | Broadcast event |
| `app/Events/CallEnded.php` | Broadcast event |

### Frontend
| File | Purpose |
|------|---------|
| `frontend/src/services/echo.service.ts` | Laravel Echo singleton with retry logic |
| `frontend/src/hooks/useEchoConnection.ts` | Global WebSocket connection hook |
| `frontend/src/hooks/useCallPresence.ts` | Real-time call tracking state |

## Broadcast Channels (routes/channels.php)

| Channel | Type | Authorization |
|---------|------|--------------|
| `org.{organizationId}` | Presence | User belongs to organization. Returns `{id, name, email, role}` |
| `user.{userId}` | Private | User ID matches channel |
| `extension.{extensionId}` | Private | User's org matches extension's org |

## Events
| Event | Channel | Data |
|-------|---------|------|
| `.call.initiated` | `org.{orgId}` | call_id, from, to, did, status, timestamp |
| `.call.answered` | `org.{orgId}` | call_id, extension, answered_at |
| `.call.ended` | `org.{orgId}` | call_id, duration |

## Frontend Echo Service (echo.service.ts)
- Creates Pusher-based Echo instance with Bearer auth for `/broadcasting/auth`
- Auto-retry with exponential backoff (initial 1s, max 30s, max 5 attempts)
- 10s connection timeout
- `subscribeToOrganization(id, callbacks)` - joins presence channel, listens for call events
- Tracks online members via presence (here/joining/leaving)

## useCallPresence Hook
- Tracks `activeCalls` array (initiated/answered/ended lifecycle)
- Tracks `onlineMembers` from presence channel
- 1-second interval recalculates all call durations client-side
- Deduplicates calls and members
- Returns: `{ activeCalls, onlineMembers, totalActiveCalls, isConnected, connectionState }`

## useEchoConnection Hook
- Connects Echo when authenticated (reads token from useAuth)
- Does NOT disconnect on component unmount (keeps connection across route changes)
- Used at AppLayout level for persistent connection

## Infrastructure
- Soketi container on port 6001 (internal), proxied via Nginx at `/app/`
- Nginx config: WebSocket upgrade headers, 7-day timeout for persistent connections
- Config: max 10000 connections, rate limits configurable

## Configuration
| Env Variable | Purpose |
|-------------|---------|
| `VITE_PUSHER_APP_KEY` | Pusher/Soketi app key |
| `VITE_WS_HOST` | WebSocket host |
| `VITE_WS_PORT` | WebSocket port |
| `VITE_WS_SCHEME` | ws or wss |
| `BROADCAST_DRIVER` | Laravel driver (redis) |

## Related Modules
- [Live Calls](live-calls.md) - Real-time call monitoring UI
- [Infrastructure](infrastructure-docker.md) - Soketi Docker service
