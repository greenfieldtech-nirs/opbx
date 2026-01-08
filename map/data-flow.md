# Data Flow & Integration Patterns

## Overview

OpBX implements a sophisticated data flow architecture with clear separation between control plane (configuration) and execution plane (real-time call processing). This document outlines the end-to-end data flows and integration patterns.

## Control Plane Data Flow (Configuration)

### User Interface → Database

#### 1. User Action (React Component)
```tsx
function CreateUserForm() {
  const createUserMutation = useCreateUser();
  
  const handleSubmit = async (userData) => {
    await createUserMutation.mutateAsync(userData);
  };
  
  return (
    <form onSubmit={handleSubmit}>
      {/* Form fields */}
    </form>
  );
}
```

#### 2. API Service Call (React Service)
```typescript
// frontend/src/services/users.service.ts
export const usersService = {
  async create(data: CreateUserData): Promise<User> {
    const response = await api.post('/users', data);
    return response.data.data;
  }
};
```

#### 3. Laravel Controller (Backend Controller)
```php
// app/Http/Controllers/Api/UsersController.php
public function store(CreateUserRequest $request): JsonResponse
{
    $user = $this->userService->create(
        $request->validated(),
        $request->organization()
    );
    
    return new UserResource($user);
}
```

#### 4. Business Logic Service (Backend Service)
```php
// app/Services/UserService.php
public function create(array $data, Organization $organization): User
{
    DB::transaction(function () use ($data, $organization) {
        $user = User::create([
            'organization_id' => $organization->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
        ]);
        
        // Create extension if specified
        if (isset($data['extension_number'])) {
            $this->createExtensionForUser($user, $data['extension_number']);
        }
        
        return $user;
    });
}
```

#### 5. Database Storage (Eloquent Model)
```php
// app/Models/User.php
class User extends Model
{
    protected $fillable = [
        'organization_id', 'name', 'email', 'password', 'role'
    ];
    
    protected static function booted()
    {
        static::addGlobalScope(new OrganizationScope());
    }
    
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
    
    public function extension(): HasOne
    {
        return $this->hasOne(Extension::class);
    }
}
```

### Data Flow Diagram
```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   React Form    │───▶│   API Service   │───▶│   Controller    │
│                 │    │   (Axios)       │    │   (Laravel)     │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Service Layer   │───▶│   Eloquent      │───▶│   Database      │
│ (Business Logic)│    │   Model         │    │   (MySQL)       │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## Execution Plane Data Flow (Real-time Calls)

### Inbound Call Processing

#### 1. Cloudonix Webhook (External Event)
```json
POST /webhooks/cloudonix/call-initiated
{
  "event": "call-initiated",
  "call_id": "call-12345",
  "direction": "inbound",
  "from": "+1234567890",
  "to": "+0987654321",
  "timestamp": "2024-01-01T10:00:00Z"
}
```

#### 2. Webhook Controller (Immediate Processing)
```php
// app/Http/Controllers/Webhooks/CloudonixWebhookController.php
public function callInitiated(Request $request): Response
{
    $payload = $request->all();
    $callId = $payload['call_id'];
    
    // Idempotency check
    if ($this->isProcessed($callId, $payload)) {
        return response('Processed', 200);
    }
    
    // Acquire distributed lock
    $lock = Redis::lock("call:{$callId}", 30);
    
    try {
        $lock->acquire();
        
        // Process call routing
        $routing = $this->routingService->routeInboundCall(
            $payload['to'],
            $payload
        );
        
        // Generate CXML response
        $cxml = $this->cxmlBuilder->createDialResponse($routing);
        
        // Mark as processed
        $this->markProcessed($callId, $payload);
        
        return response($cxml, 200, [
            'Content-Type' => 'application/xml'
        ]);
        
    } finally {
        $lock->release();
    }
}
```

#### 3. Routing Service (Business Logic)
```php
// app/Services/CallRoutingService.php
public function routeInboundCall(string $did, array $context): RoutingResult
{
    // Get DID configuration from cache
    $didConfig = $this->cache->get("did_routing:{$did}");
    
    if (!$didConfig) {
        $didConfig = DidNumber::where('number', $did)->first();
        $this->cache->set("did_routing:{$did}", $didConfig, 3600);
    }
    
    // Determine routing based on type
    switch ($didConfig->routing_type) {
        case 'extension':
            return $this->routeToExtension($didConfig->routing_target_id);
        case 'ring_group':
            return $this->routeToRingGroup($didConfig->routing_target_id);
        case 'business_hours':
            return $this->routeWithBusinessHours($didConfig->routing_target_id);
        default:
            return $this->routeToDefault();
    }
}
```

#### 4. CXML Generation (Response)
```php
// app/Services/CxmlBuilder.php
public function createDialResponse(RoutingResult $routing): string
{
    $xml = new SimpleXMLElement('<Response/>');
    
    switch ($routing->type) {
        case 'extension':
            $dial = $xml->addChild('Dial');
            $dial->addChild('Number', $routing->destination);
            break;
            
        case 'ring_group':
            $dial = $xml->addChild('Dial');
            foreach ($routing->members as $member) {
                $dial->addChild('Number', $member->extension_number);
            }
            break;
            
        case 'ivr':
            $gather = $xml->addChild('Gather');
            $gather->addAttribute('action', '/voice/ivr-input');
            $gather->addChild('Say', $routing->message);
            break;
    }
    
    return $xml->asXML();
}
```

### Execution Plane Data Flow Diagram
```
┌─────────────────┐    ┌─────────────────┐
│ Cloudonix       │───▶│ Webhook         │
│ Webhook         │    │ Controller      │
│ (External)      │    │                 │
└─────────────────┘    └─────────────────┘
         │                        │
         ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Idempotency     │───▶│ Distributed     │───▶│ Routing         │
│ Check           │    │ Lock            │    │ Service         │
│ (Redis)         │    │ (Redis)         │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Cache Lookup    │───▶│ Business       │───▶│ CXML Response   │
│ (Redis)         │    │ Logic          │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Cloudonix API   │◀───│ XML Response    │    │ Call State      │
│ (External)      │    │                 │    │ Tracking        │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## Real-time Updates Flow

### WebSocket Broadcasting

#### 1. Database Change Detection (Observer)
```php
// app/Observers/CallStateObserver.php
class CallStateObserver
{
    public function updated(CallState $callState)
    {
        // Broadcast to WebSocket channel
        broadcast(new CallStateUpdated($callState))->toOthers();
    }
}
```

#### 2. Laravel Broadcasting (Queue)
```php
// app/Events/CallStateUpdated.php
class CallStateUpdated implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        return new PrivateChannel('calls');
    }
    
    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callState->call_id,
            'state' => $this->callState->state,
            'timestamp' => now(),
        ];
    }
}
```

#### 3. Frontend WebSocket Reception
```typescript
// frontend/src/hooks/useCallPresence.ts
export function useCallPresence() {
  const [calls, setCalls] = useState<Call[]>([]);
  
  useEffect(() => {
    // Connect to WebSocket
    const channel = window.Echo.private('calls');
    
    channel.listen('.call.state.updated', (event: CallStateEvent) => {
      setCalls(prevCalls => 
        prevCalls.map(call => 
          call.id === event.call_id 
            ? { ...call, state: event.state, updatedAt: event.timestamp }
            : call
        )
      );
    });
    
    return () => channel.stopListening('.call.state.updated');
  }, []);
  
  return calls;
}
```

#### 4. UI Update (React Component)
```tsx
function LiveCallsPage() {
  const calls = useCallPresence();
  
  return (
    <div>
      {calls.map(call => (
        <LiveCallCard key={call.id} call={call} />
      ))}
    </div>
  );
}
```

### Real-time Data Flow Diagram
```
┌─────────────────┐    ┌─────────────────┐
│ Database        │───▶│ Model Observer  │
│ Change          │    │                 │
└─────────────────┘    └─────────────────┘
         │                        │
         ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Laravel         │───▶│ Queue Worker    │───▶│ Broadcasting    │
│ Event           │    │                 │    │ Service         │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ WebSocket       │───▶│ Frontend        │───▶│ React Component │
│ Server          │    │ Service         │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Real-time UI    │    │ State Update    │    │ Re-render       │
│ Update          │    │                 │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## Caching Strategy Integration

### Multi-Level Caching Flow

#### 1. Application Cache (Redis)
```php
// Cache routing lookups
$extension = Cache::remember(
    "extension:{$orgId}:{$number}", 
    3600, 
    fn() => Extension::where('extension_number', $number)->first()
);
```

#### 2. Query Cache (Database)
```php
// Cache complex queries
$users = Cache::tags(['users'])->remember(
    "users:{$orgId}:{$filters}", 
    1800,
    fn() => User::where('organization_id', $orgId)->filter($filters)->get()
);
```

#### 3. Frontend Cache (React Query)
```typescript
// Cache API responses
const { data: users } = useQuery({
  queryKey: ['users', filters],
  queryFn: () => usersApi.getAll(filters),
  staleTime: 5 * 60 * 1000, // 5 minutes
});
```

### Cache Invalidation Flow
```
┌─────────────────┐    ┌─────────────────┐
│ Database        │───▶│ Model Observer  │
│ Update          │    │                 │
└─────────────────┘    └─────────────────┘
         │                        │
         ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Invalidate      │───▶│ Queue Job       │───▶│ Clear Redis     │
│ Redis Cache     │    │                 │    │ Keys            │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Frontend        │───▶│ Invalidate      │───▶│ Refetch Data    │
│ WebSocket       │    │ React Query     │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## Authentication Flow

### API Authentication
```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Login Form      │───▶│ Sanctum Token   │───▶│ Store Token     │
│                 │    │ Request         │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ API Request     │───▶│ Attach Bearer   │───▶│ Validate Token  │
│                 │    │ Token           │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Laravel Auth    │───▶│ Organization    │───▶│ Authorize       │
│ Middleware      │    │ Context         │    │ Action          │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

### Voice Authentication
```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Cloudonix       │───▶│ Bearer Token    │───▶│ Validate API    │
│ Webhook         │    │ Header          │    │ Key             │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Lookup Org      │───▶│ Set Tenant      │───▶│ Process         │
│ by API Key      │    │ Context         │    │ Request         │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## Error Handling Flow

### API Error Flow
```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Frontend        │───▶│ Network Error   │───▶│ Axios           │
│ Request         │    │                 │    │ Interceptor     │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Backend         │───▶│ Exception       │───▶│ Error Handler   │
│ Error           │    │                 │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ JSON Error      │───▶│ Frontend        │───▶│ Toast/Alert     │
│ Response        │    │ Interceptor     │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

### Voice Error Flow
```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ Webhook         │───▶│ Processing      │───▶│ Exception       │
│ Request         │    │ Error           │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ CXML Error      │───▶│ Cloudonix       │───▶│ Play Error      │
│ Response        │    │ Platform        │    │ Message         │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

These data flows ensure reliable, performant, and maintainable communication between all components of the OpBX system, with proper error handling and real-time capabilities.