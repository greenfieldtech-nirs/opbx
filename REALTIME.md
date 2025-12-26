# Real-Time Features Documentation

This document describes the real-time WebSocket infrastructure for the OPBX application, which provides live call presence updates and user presence awareness.

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [Technology Stack](#technology-stack)
- [Setup Instructions](#setup-instructions)
- [Backend Implementation](#backend-implementation)
- [Frontend Implementation](#frontend-implementation)
- [Events Reference](#events-reference)
- [Testing](#testing)
- [Monitoring](#monitoring)
- [Troubleshooting](#troubleshooting)
- [Performance Tuning](#performance-tuning)
- [Security](#security)

---

## Architecture Overview

The real-time system uses a publish/subscribe pattern with the following components:

```
┌─────────────┐         ┌──────────────┐         ┌────────────┐
│   Laravel   │────────▶│    Redis     │◀────────│   Soketi   │
│   Backend   │         │  Pub/Sub     │         │  WebSocket │
└─────────────┘         └──────────────┘         └────────────┘
                                                         │
                                                         ▼
                                                  ┌────────────┐
                                                  │   React    │
                                                  │  Frontend  │
                                                  └────────────┘
```

### Flow:

1. **Event Trigger**: Laravel backend triggers broadcast events (CallInitiated, CallAnswered, CallEnded)
2. **Redis Pub/Sub**: Events are published to Redis using Laravel Broadcasting
3. **Soketi**: WebSocket server subscribes to Redis and pushes events to connected clients
4. **Frontend**: React app receives events via Laravel Echo and updates UI in real-time

### Key Features:

- **Multi-tenant isolation**: Each organization has its own presence channel
- **Automatic reconnection**: Client reconnects with exponential backoff on disconnect
- **Presence awareness**: See which team members are online
- **Sub-second latency**: Events delivered to clients within 100-300ms
- **Horizontal scalability**: Soketi can be scaled across multiple servers using Redis

---

## Technology Stack

### Backend
- **Laravel Broadcasting**: Event broadcasting framework
- **Pusher Protocol**: Industry-standard WebSocket protocol
- **Redis**: Pub/sub message broker
- **Soketi**: Self-hosted, Pusher-compatible WebSocket server

### Frontend
- **Laravel Echo**: Official JavaScript client for Laravel Broadcasting
- **Pusher JS**: Pusher protocol client library
- **React**: UI framework with hooks for state management

### Infrastructure
- **Docker**: Containerized deployment
- **Nginx**: Reverse proxy (optional WebSocket upgrade)

---

## Setup Instructions

### 1. Environment Configuration

Add the following to your `.env` file:

```bash
# Broadcasting
BROADCAST_DRIVER=pusher
BROADCAST_CONNECTION=redis

# WebSocket / Soketi Configuration
PUSHER_APP_ID=app-id
PUSHER_APP_KEY=pbxappkey
PUSHER_APP_SECRET=pbxappsecret
PUSHER_HOST=soketi
PUSHER_PORT=6001
PUSHER_SCHEME=http
PUSHER_APP_CLUSTER=mt1
SOKETI_PORT=6001
SOKETI_METRICS_PORT=9601
SOKETI_DEBUG=0
```

**Frontend environment variables** (in `.env` or Vite config):

```bash
VITE_PUSHER_APP_KEY=pbxappkey
VITE_WS_HOST=localhost
VITE_WS_PORT=6001
VITE_WS_SCHEME=http
```

### 2. Install Dependencies

**Backend:**

Laravel requires the Pusher PHP SDK for the broadcasting driver:

```bash
composer require pusher/pusher-php-server
```

**Frontend:**

Install Laravel Echo and Pusher JS client:

```bash
npm install laravel-echo pusher-js
```

### 3. Start Services

Start all services including Soketi:

```bash
docker-compose up -d
```

Verify Soketi is running:

```bash
docker-compose ps soketi
docker-compose logs soketi
```

### 4. Test Connection

Check WebSocket health:

```bash
curl http://localhost/api/v1/websocket/health
```

Expected response:

```json
{
  "status": "ok",
  "websocket": "connected",
  "driver": "pusher",
  "host": "soketi",
  "port": 6001,
  "timestamp": "2024-01-01T12:00:00+00:00"
}
```

---

## Backend Implementation

### Broadcasting Events

All call-related events implement `ShouldBroadcast` interface:

**Example: CallInitiated Event**

```php
<?php

namespace App\Events;

use App\Models\CallLog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class CallInitiated implements ShouldBroadcast
{
    public function __construct(public CallLog $callLog) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('presence.org.' . $this->callLog->organization_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'call.initiated';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callLog->call_id,
            'from_number' => $this->callLog->from_number,
            'to_number' => $this->callLog->to_number,
            'did_id' => $this->callLog->did_id,
            'status' => $this->callLog->status->value,
            'initiated_at' => $this->callLog->initiated_at?->toIso8601String(),
        ];
    }
}
```

### Triggering Events

Events are triggered automatically in webhook handlers:

```php
// In webhook handler when call is initiated
$callLog = CallLog::create([...]);

event(new CallInitiated($callLog));
```

### Channel Authorization

Authorization is defined in `routes/channels.php`:

```php
Broadcast::channel('presence.org.{organizationId}', function ($user, $organizationId) {
    // Only allow users from the same organization
    if ((string) $user->organization_id !== (string) $organizationId) {
        return false;
    }

    // Return user data for presence list
    return [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role->value,
    ];
});
```

### Queue Workers

Broadcasting events are queued by default. Ensure queue workers are running:

```bash
php artisan queue:work redis --sleep=3 --tries=3
```

In Docker, this is handled by the `queue-worker` service.

---

## Frontend Implementation

### Echo Service

The Echo service (`src/services/echo.service.ts`) handles WebSocket connections:

```typescript
import { echoService } from '@/services/echo.service';

// Connect to WebSocket (call once on app initialization)
echoService.connect(authToken);

// Subscribe to organization channel
echoService.subscribeToOrganization(organizationId, {
  onCallInitiated: (data) => {
    console.log('New call:', data.call_id);
  },
  onCallAnswered: (data) => {
    console.log('Call answered:', data.call_id);
  },
  onCallEnded: (data) => {
    console.log('Call ended:', data.call_id);
  },
  onMemberJoined: (member) => {
    console.log('User joined:', member.name);
  },
  onMemberLeft: (member) => {
    console.log('User left:', member.name);
  },
});

// Disconnect when done
echoService.disconnect();
```

### React Hook: useCallPresence

The `useCallPresence` hook provides an easy way to manage call presence in React components:

```typescript
import { useCallPresence } from '@/hooks/useCallPresence';

function LiveCallsPanel() {
  const { activeCalls, onlineMembers, totalActiveCalls, isConnected } = useCallPresence();

  return (
    <div>
      <div>Status: {isConnected ? 'Connected' : 'Disconnected'}</div>
      <div>Active Calls: {totalActiveCalls}</div>

      <ul>
        {activeCalls.map(call => (
          <li key={call.call_id}>
            {call.from_number} → {call.to_number} ({call.duration}s)
          </li>
        ))}
      </ul>

      <div>Online: {onlineMembers.length} members</div>
    </div>
  );
}
```

### Connection State Management

The hook automatically manages:
- **Connection lifecycle**: Connects when user authenticates, disconnects on logout
- **Reconnection**: Automatic reconnection with exponential backoff
- **Call duration tracking**: Updates every second
- **State synchronization**: Keeps call list in sync with backend events

---

## Events Reference

### Call Events

#### call.initiated

Fired when a new inbound call arrives.

**Channel**: `presence.org.{organization_id}`

**Payload**:
```json
{
  "call_id": "550e8400-e29b-41d4-a716-446655440000",
  "from_number": "+18005551234",
  "to_number": "+18005556789",
  "did_id": "abc-123",
  "status": "initiated",
  "initiated_at": "2024-01-01T12:00:00Z"
}
```

#### call.answered

Fired when a call is answered by an extension.

**Payload**:
```json
{
  "call_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "answered",
  "answered_at": "2024-01-01T12:00:05Z",
  "extension_id": "ext-456"
}
```

#### call.ended

Fired when a call completes or fails.

**Payload**:
```json
{
  "call_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "completed",
  "ended_at": "2024-01-01T12:02:15Z",
  "duration": 130
}
```

### Presence Events

#### Member Joined

Fired when a team member connects to the presence channel.

**Data**:
```json
{
  "id": "user-123",
  "name": "John Doe",
  "email": "john@example.com",
  "role": "admin"
}
```

#### Member Left

Fired when a team member disconnects.

---

## Testing

### Running Tests

Run the broadcasting feature tests:

```bash
php artisan test --filter=CallPresenceTest
```

### Manual Testing

1. **Start Soketi**:
   ```bash
   docker-compose up -d soketi
   ```

2. **Open browser console** in your React app

3. **Trigger a test event from backend**:
   ```bash
   php artisan tinker
   ```
   ```php
   $org = App\Models\Organization::first();
   $call = App\Models\CallLog::factory()->for($org)->create();
   event(new App\Events\CallInitiated($call));
   ```

4. **Check frontend console** - you should see:
   ```
   [Echo] Call initiated: <call-id>
   ```

### Test Coverage

Key test scenarios:
- Event broadcasting triggers correctly
- Channel authorization enforces tenant isolation
- Presence channel returns user info
- WebSocket health endpoint responds
- Events contain correct data structure

---

## Monitoring

### Soketi Metrics

Soketi exposes metrics on port 9601:

```bash
curl http://localhost:9601/metrics
curl http://localhost:9601/ready
curl http://localhost:9601/usage
```

### Health Checks

**API Health**:
```bash
curl http://localhost/api/v1/health
```

**WebSocket Health**:
```bash
curl http://localhost/api/v1/websocket/health
```

### Logs

**Soketi logs**:
```bash
docker-compose logs -f soketi
```

**Laravel queue logs**:
```bash
docker-compose logs -f queue-worker
```

### Key Metrics to Monitor

- **Connection count**: Number of active WebSocket connections
- **Event throughput**: Messages/second being broadcast
- **Latency**: Time from event trigger to client receipt (target: <300ms)
- **Connection errors**: Failed connections or disconnections
- **Queue depth**: Broadcasting queue should not grow unbounded

---

## Troubleshooting

### WebSocket Connection Fails

**Symptoms**: Frontend shows "Disconnected", no events received

**Checks**:
1. Verify Soketi is running:
   ```bash
   docker-compose ps soketi
   ```

2. Check Soketi logs for errors:
   ```bash
   docker-compose logs soketi
   ```

3. Verify PUSHER_APP_KEY matches between backend `.env` and frontend config

4. Test health endpoint:
   ```bash
   curl http://localhost/api/v1/websocket/health
   ```

5. Check browser console for connection errors

### Events Not Received

**Symptoms**: Connection successful but no events arrive

**Checks**:
1. Verify queue worker is running:
   ```bash
   docker-compose ps queue-worker
   ```

2. Check queue worker logs:
   ```bash
   docker-compose logs queue-worker
   ```

3. Verify BROADCAST_DRIVER is set to `pusher` in `.env`

4. Check Redis is running:
   ```bash
   docker-compose ps redis
   ```

5. Verify event implements `ShouldBroadcast` interface

6. Test manual event trigger:
   ```bash
   php artisan tinker
   event(new App\Events\CallInitiated(App\Models\CallLog::first()));
   ```

### Authorization Fails

**Symptoms**: `403 Forbidden` on `/broadcasting/auth`

**Checks**:
1. Verify user is authenticated (Bearer token is valid)
2. Check `routes/channels.php` authorization logic
3. Ensure user belongs to the organization they're trying to access
4. Check Laravel logs for authorization errors

### High Latency

**Symptoms**: Events arrive delayed (>1 second)

**Solutions**:
1. Check Redis performance (high memory, slow queries)
2. Verify queue worker is not overloaded
3. Scale queue workers horizontally
4. Check network latency between services
5. Enable Soketi clustering for high load

### Memory Leaks

**Symptoms**: Soketi or browser memory grows unbounded

**Solutions**:
1. Ensure frontend properly unsubscribes from events on unmount
2. Check for event listener accumulation
3. Restart Soketi periodically if needed
4. Use Soketi connection limits

---

## Performance Tuning

### Soketi Configuration

Tune Soketi for high concurrency in docker-compose.yml:

```yaml
soketi:
  environment:
    SOKETI_DEFAULT_APP_MAX_CONNECTIONS: '10000'
    SOKETI_DEFAULT_APP_MAX_BACKEND_EVENTS_PER_SECOND: '100'
    SOKETI_DEFAULT_APP_MAX_CLIENT_EVENTS_PER_SECOND: '100'
    SOKETI_DEFAULT_APP_MAX_READ_REQUEST_PER_SECOND: '100'
```

### Laravel Queue Workers

Scale queue workers based on event volume:

```bash
# Run 3 queue workers in parallel
docker-compose up -d --scale queue-worker=3
```

### Redis Configuration

For high-throughput scenarios:
- Use Redis in memory-only mode (no persistence)
- Increase max memory limit
- Use Redis Cluster for horizontal scaling

### Frontend Optimization

- Debounce UI updates if event rate is very high
- Use virtualization for long call lists
- Implement pagination for historical calls

---

## Security

### Authentication

- WebSocket connections require valid Bearer token
- Token is sent in authorization header to `/broadcasting/auth`
- Expired tokens are rejected

### Channel Authorization

- Presence channels verify user belongs to organization
- Private channels verify user owns the resource
- Authorization logic in `routes/channels.php` is enforced on every connection

### Rate Limiting

Soketi enforces rate limits:
- Max connections per app
- Max events per second
- Prevents abuse and DoS attacks

### Data Validation

- All broadcast events validate data before sending
- Sensitive data (passwords, secrets) never included in broadcasts
- User info is limited to necessary fields (id, name, role)

### Transport Security

For production:
- Use WSS (WebSocket Secure) with TLS certificates
- Set `PUSHER_SCHEME=https` and `forceTLS=true`
- Terminate TLS at load balancer or reverse proxy

---

## Production Deployment

### Checklist

- [ ] Set `SOKETI_DEBUG=0` for production
- [ ] Use WSS (WebSocket Secure) with valid TLS certificate
- [ ] Configure firewall to allow port 6001 (or custom port)
- [ ] Set up monitoring and alerting for Soketi
- [ ] Scale queue workers based on event volume
- [ ] Enable Soketi clustering if using multiple servers
- [ ] Configure Redis persistence for reliability
- [ ] Set up log aggregation (ELK, Datadog, etc.)
- [ ] Test failover and disaster recovery procedures

### Scaling Strategy

**Vertical Scaling**:
- Increase Soketi resources (CPU, memory)
- Tune connection limits and event throughput

**Horizontal Scaling**:
- Deploy multiple Soketi instances behind load balancer
- Use Redis for state synchronization between instances
- Ensure sticky sessions or use Soketi adapter for Redis

**Geographic Distribution**:
- Deploy Soketi in multiple regions
- Route users to nearest WebSocket server
- Use Redis cluster for global state

---

## References

- [Laravel Broadcasting Docs](https://laravel.com/docs/broadcasting)
- [Soketi Documentation](https://docs.soketi.app/)
- [Laravel Echo Docs](https://laravel.com/docs/broadcasting#client-side-installation)
- [Pusher Protocol Spec](https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/)

---

## Support

For issues or questions:
- Check this documentation first
- Review Soketi and Laravel Broadcasting docs
- Check GitHub issues for known problems
- Open a new issue with logs and reproduction steps

