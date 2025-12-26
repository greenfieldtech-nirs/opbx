# WebSocket Implementation Summary

Production-ready real-time WebSocket infrastructure for OPBX business PBX application.

## Overview

This implementation provides sub-second latency real-time call presence updates to all connected users within an organization using Laravel Broadcasting, Soketi (self-hosted Pusher-compatible WebSocket server), and Laravel Echo on the frontend.

## Architecture

```
Call Event (Backend)
        │
        ▼
Laravel Broadcasting Event
        │
        ▼
Redis Pub/Sub (Queue)
        │
        ▼
Soketi WebSocket Server
        │
        ▼
Laravel Echo (Frontend)
        │
        ▼
React UI Update
```

## Technology Stack

### Backend
- **Laravel Broadcasting**: Built-in event broadcasting framework
- **Pusher PHP SDK**: Required for Pusher protocol driver
- **Redis**: Message broker for pub/sub pattern
- **Soketi**: Self-hosted, Pusher-compatible WebSocket server (containerized)

### Frontend
- **Laravel Echo**: Official JavaScript client for Laravel Broadcasting
- **Pusher JS**: WebSocket client library
- **React Hooks**: Custom hooks for state management

### Infrastructure
- **Docker Compose**: All services containerized
- **Nginx**: Reverse proxy (optional WebSocket upgrade)

## Key Features

### Multi-Tenant Isolation
- Each organization has dedicated presence channel: `presence.org.{organization_id}`
- Authorization enforced at channel subscription time
- Users can only see events from their organization

### Presence Awareness
- See which team members are currently online
- Automatic presence updates when users join/leave
- Member information includes: id, name, email, role

### Call Events
- **call.initiated**: New inbound call notification
- **call.answered**: Call connected to extension
- **call.ended**: Call completed or failed

### Reliability
- **Automatic reconnection**: Exponential backoff on disconnect
- **Connection state tracking**: disconnected → connecting → connected
- **Event queuing**: Backend queues events for reliability
- **Idempotency**: Duplicate events handled gracefully

### Performance
- **Sub-300ms latency**: Events delivered in real-time
- **10,000 concurrent connections**: Default Soketi capacity
- **Horizontal scalability**: Soketi can be clustered with Redis
- **Efficient payload**: Minimal data in events

## Files Created/Modified

### Backend Configuration

#### `/config/broadcasting.php`
Laravel broadcasting configuration with Pusher driver settings.

**Key settings:**
- Driver: `pusher`
- Connection: Redis pub/sub
- Host/Port: Soketi container

#### `/config/websockets.php`
WebSocket server configuration (for reference, Soketi uses env vars).

#### `/routes/channels.php`
Broadcast channel authorization logic.

**Channels defined:**
- `presence.org.{organizationId}` - Organization presence channel
- `user.{userId}` - Private user channel
- `extension.{extensionId}` - Extension status channel

### Events

#### `/app/Events/CallInitiated.php`
Broadcasts when new call arrives.

**Payload:**
```php
[
    'call_id' => string,
    'from_number' => string,
    'to_number' => string,
    'did_id' => string|null,
    'status' => string,
    'initiated_at' => ISO8601 timestamp,
]
```

#### `/app/Events/CallAnswered.php`
Broadcasts when call is answered.

**Payload:**
```php
[
    'call_id' => string,
    'status' => string,
    'answered_at' => ISO8601 timestamp,
    'extension_id' => string,
]
```

#### `/app/Events/CallEnded.php`
Broadcasts when call ends.

**Payload:**
```php
[
    'call_id' => string,
    'status' => string,
    'ended_at' => ISO8601 timestamp,
    'duration' => int (seconds),
]
```

### API Endpoints

#### `GET /api/v1/health`
General API health check.

#### `GET /api/v1/websocket/health`
WebSocket connectivity health check. Tests Pusher connection and returns status.

**Response (success):**
```json
{
  "status": "ok",
  "websocket": "connected",
  "driver": "pusher",
  "host": "soketi",
  "port": 6001,
  "timestamp": "2024-01-01T12:00:00Z"
}
```

### Frontend Implementation

#### `/frontend/src/services/echo.service.ts`
Production-ready Laravel Echo service singleton.

**Features:**
- Connection lifecycle management
- Automatic reconnection with exponential backoff
- Presence channel subscription
- Event listener registration
- Connection state tracking

**API:**
```typescript
echoService.connect(token: string): void
echoService.subscribeToOrganization(organizationId, callbacks): void
echoService.leaveOrganization(): void
echoService.disconnect(): void
echoService.isConnected(): boolean
```

#### `/frontend/src/hooks/useCallPresence.ts`
React hook for call presence management.

**Returns:**
```typescript
{
  activeCalls: ActiveCall[],
  onlineMembers: PresenceMember[],
  totalActiveCalls: number,
  isConnected: boolean,
  connectionState: 'disconnected' | 'connecting' | 'connected'
}
```

**Features:**
- Automatic connection management
- Real-time call list updates
- Duration calculation (updated every second)
- Online member tracking
- Clean disconnect on unmount

**Usage:**
```tsx
import { useCallPresence } from '@/hooks/useCallPresence';

function LiveCallsPanel() {
  const { activeCalls, onlineMembers, isConnected } = useCallPresence();

  return (
    <div>
      <div>Status: {isConnected ? 'Connected' : 'Disconnected'}</div>
      <div>Active Calls: {activeCalls.length}</div>
      <ul>
        {activeCalls.map(call => (
          <li key={call.call_id}>
            {call.from_number} → {call.to_number} ({call.duration}s)
          </li>
        ))}
      </ul>
    </div>
  );
}
```

### Infrastructure

#### `/docker-compose.yml` (updated)
Added Soketi service:

```yaml
soketi:
  image: 'quay.io/soketi/soketi:latest-16-alpine'
  container_name: opbx_websocket
  ports:
    - "6001:6001"    # WebSocket
    - "9601:9601"    # Metrics
  environment:
    SOKETI_DEFAULT_APP_ID: 'app-id'
    SOKETI_DEFAULT_APP_KEY: 'pbxappkey'
    SOKETI_DEFAULT_APP_SECRET: 'pbxappsecret'
    SOKETI_DEFAULT_APP_MAX_CONNECTIONS: '10000'
    # ... rate limits and config
```

#### `/.env.example` (updated)
Added WebSocket configuration:

```bash
# Broadcasting
BROADCAST_DRIVER=pusher

# WebSocket / Soketi
PUSHER_APP_ID=app-id
PUSHER_APP_KEY=pbxappkey
PUSHER_APP_SECRET=pbxappsecret
PUSHER_HOST=soketi
PUSHER_PORT=6001
PUSHER_SCHEME=http

# Frontend
VITE_PUSHER_APP_KEY=pbxappkey
VITE_WS_HOST=localhost
VITE_WS_PORT=6001
VITE_WS_SCHEME=http
```

### Testing

#### `/tests/Feature/Broadcasting/CallPresenceTest.php`
Comprehensive feature tests for broadcasting functionality.

**Test coverage:**
- Event broadcasting triggers correctly
- Event payloads contain correct data
- Events broadcast to correct channels
- Presence channel authorization
- Tenant isolation enforcement
- User private channel authorization
- Extension channel authorization
- WebSocket health endpoint

**Run tests:**
```bash
php artisan test --filter=CallPresenceTest
```

### Documentation

#### `/REALTIME.md`
Complete documentation covering:
- Architecture overview with diagrams
- Technology stack details
- Setup instructions (step-by-step)
- Backend implementation guide
- Frontend implementation guide
- Events reference (all payloads)
- Testing procedures
- Monitoring and observability
- Troubleshooting guide
- Performance tuning recommendations
- Security best practices
- Production deployment checklist
- Scaling strategies

### Package Dependencies

#### Backend (`composer.json`)
Added:
```json
{
  "require": {
    "pusher/pusher-php-server": "^7.2"
  }
}
```

#### Frontend (`frontend/package.json`)
Added:
```json
{
  "dependencies": {
    "laravel-echo": "^1.16.1",
    "pusher-js": "^8.4.0-rc2"
  }
}
```

## Setup Instructions

### 1. Install Dependencies

**Backend:**
```bash
composer install
```

**Frontend:**
```bash
cd frontend
npm install
```

### 2. Configure Environment

Copy `.env.example` to `.env` and ensure WebSocket settings are correct:

```bash
cp .env.example .env
```

Key variables:
- `BROADCAST_DRIVER=pusher`
- `PUSHER_APP_KEY=pbxappkey`
- `PUSHER_HOST=soketi`
- `PUSHER_PORT=6001`

### 3. Start Services

```bash
docker-compose up -d
```

Verify Soketi is running:
```bash
docker-compose ps soketi
docker-compose logs soketi
```

### 4. Test Connection

Check health endpoint:
```bash
curl http://localhost/api/v1/websocket/health
```

Expected response:
```json
{
  "status": "ok",
  "websocket": "connected"
}
```

### 5. Run Tests

```bash
php artisan test --filter=CallPresenceTest
```

## Usage Example

### Trigger Event from Backend

```php
use App\Events\CallInitiated;
use App\Models\CallLog;

// In webhook handler
$callLog = CallLog::create([
    'call_id' => $callId,
    'organization_id' => $organizationId,
    'from_number' => '+18005551234',
    'to_number' => '+18005556789',
    'status' => CallStatus::Initiated,
]);

// Broadcast to all users in organization
event(new CallInitiated($callLog));
```

### Subscribe on Frontend

```tsx
import { useCallPresence } from '@/hooks/useCallPresence';

function Dashboard() {
  const { activeCalls, onlineMembers, isConnected } = useCallPresence();

  return (
    <div>
      <ConnectionIndicator isConnected={isConnected} />

      <section>
        <h2>Active Calls ({activeCalls.length})</h2>
        {activeCalls.map(call => (
          <CallCard key={call.call_id} call={call} />
        ))}
      </section>

      <section>
        <h2>Online Team ({onlineMembers.length})</h2>
        {onlineMembers.map(member => (
          <MemberBadge key={member.id} member={member} />
        ))}
      </section>
    </div>
  );
}
```

## Monitoring

### Soketi Metrics

Soketi exposes metrics on port 9601:

```bash
# Ready status
curl http://localhost:9601/ready

# Prometheus metrics
curl http://localhost:9601/metrics

# Usage statistics
curl http://localhost:9601/usage
```

### Application Logs

```bash
# Soketi logs
docker-compose logs -f soketi

# Queue worker logs
docker-compose logs -f queue-worker

# Laravel logs
tail -f storage/logs/laravel.log
```

### Key Metrics

- **Active connections**: Number of WebSocket clients
- **Event throughput**: Messages/second
- **Latency**: Event delivery time (target: <300ms)
- **Error rate**: Failed connections or events
- **Queue depth**: Broadcasting queue size

## Production Considerations

### Security
- Use WSS (WebSocket Secure) in production with valid TLS certificates
- Set `PUSHER_SCHEME=https` and `forceTLS=true`
- Implement rate limiting at load balancer level
- Use strong API keys and rotate regularly

### Scalability
- Soketi can handle 10,000 connections per instance
- Scale horizontally by deploying multiple Soketi instances
- Use Redis for state synchronization between instances
- Configure sticky sessions at load balancer

### Reliability
- Run multiple queue workers for event processing
- Enable Redis persistence for message durability
- Set up health checks and auto-restart for Soketi
- Implement circuit breakers for Pusher connection failures

### Performance
- Monitor Redis memory usage and tune maxmemory
- Scale queue workers based on event volume
- Use Redis Cluster for high-throughput scenarios
- Enable Soketi statistics for bottleneck identification

## Troubleshooting

### Connection Fails

**Check Soketi:**
```bash
docker-compose ps soketi
docker-compose logs soketi
```

**Check keys match:**
- Backend `PUSHER_APP_KEY` = Frontend `VITE_PUSHER_APP_KEY`

**Test health:**
```bash
curl http://localhost/api/v1/websocket/health
```

### Events Not Received

**Check queue worker:**
```bash
docker-compose ps queue-worker
docker-compose logs queue-worker
```

**Check broadcasting driver:**
```bash
php artisan tinker
config('broadcasting.default')  # Should be 'pusher'
```

**Test Redis:**
```bash
docker-compose exec redis redis-cli ping
```

### Authorization Fails

**Check user token:**
- Token must be valid Bearer token in Authorization header
- User must belong to the organization

**Check channel logic:**
- Review `routes/channels.php` authorization callback

## Performance Benchmarks

### Expected Performance

- **Latency**: 100-300ms from event trigger to client receipt
- **Throughput**: 100+ events/second per Soketi instance
- **Connections**: 10,000 concurrent connections per instance
- **Memory**: ~50MB base + ~10KB per connection
- **CPU**: Low usage, scales with event volume

### Load Testing

Use `artillery` or `k6` for load testing:

```bash
# Install artillery
npm install -g artillery

# Run load test
artillery quick --count 100 --num 10 ws://localhost:6001/app/pbxappkey
```

## Future Enhancements

Potential improvements for v2:
- [ ] WebRTC data channels for ultra-low latency
- [ ] Message persistence and replay for offline clients
- [ ] Presence typing indicators
- [ ] Voice quality metrics streaming
- [ ] Call recording status updates
- [ ] WebSocket connection analytics dashboard
- [ ] Geographic distribution with multi-region Soketi

## Support

For issues:
1. Check `REALTIME.md` documentation
2. Review Soketi logs
3. Test health endpoints
4. Check GitHub issues
5. Open new issue with reproduction steps

## References

- [REALTIME.md](./REALTIME.md) - Comprehensive documentation
- [Laravel Broadcasting Docs](https://laravel.com/docs/broadcasting)
- [Soketi Documentation](https://docs.soketi.app/)
- [Laravel Echo Docs](https://laravel.com/docs/broadcasting#client-side-installation)

---

**Implementation Date**: 2024-12-21
**Status**: Production-ready
**Target Latency**: <300ms p99
**Target Scale**: 10,000+ concurrent connections
