# AI Assistant Load Balancer (ALBS) Feature Specification

## Executive Summary

The AI Assistant Load Balancer (ALBS) feature enables intelligent distribution of incoming calls across multiple AI Assistants, improving system availability, load distribution, and resource utilization. This feature extends the PBX's capability to handle high-volume AI-powered call interactions while providing redundancy and failover mechanisms.

**Key Benefits:**
- Distribute call load across multiple AI Assistants
- Support for three distribution strategies: Round Robin, Priority Based, and Percentage Based
- Automatic failover when AI Assistants are unavailable
- Seamless integration with existing routing infrastructure
- Real-time distribution decisions during call processing

**Estimated Effort:** 4-5 weeks
**Priority:** Medium (enhancement feature)
**Dependencies:** AI Assistants feature, Ring Groups infrastructure, DID mapping, call routing engine

---

## Scope and Objectives

### In Scope
- AI Assistant Load Balancer configuration (name, description, strategy, status)
- Member management (add/remove AI Assistants, set priorities/weights)
- Three distribution strategies (Round Robin, Priority, Percentage)
- Fallback action handling (extension, ring group, IVR, AI Assistant, hangup)
- Integration with call routing engine
- Administrative UI for configuration
- API endpoints for CRUD operations
- Member status management (active/inactive)
- Circular reference prevention (fallback validation)
- Comprehensive testing and validation

### Out of Scope
- AI provider configuration (managed separately in AI Assistants feature)
- Advanced scheduling or time-based routing
- Geographic or skill-based routing
- Call queueing or hold music
- Analytics and reporting dashboards
- Real-time load monitoring
- Integration with external AI platforms
- Automatic AI Assistant health checking

### Objectives
1. Enable organizations to distribute AI-powered calls across multiple assistants
2. Provide reliable and configurable distribution algorithms
3. Support failover and redundancy for AI Assistant unavailability
4. Deliver intuitive administrative interface
5. Ensure high availability and performance (< 200ms routing decisions)
6. Maintain consistency with Ring Groups UX patterns

---

## Task Breakdown

### Backend Tasks

#### Database Schema & Models
- [ ] Design `ai_assistant_load_balancers` table structure
- [ ] Create `ai_assistant_load_balancer_members` junction table
- [ ] Implement Eloquent models with relationships
- [ ] Create database migrations with proper constraints
- [ ] Add tenant scoping (OrganizationScope) to all models
- [ ] Implement soft deletes for load balancers
- [ ] Add foreign key constraints with cascade rules
- [ ] Create database indexes for performance

#### Enums
- [ ] Create `AlbsStrategy` enum (ROUND_ROBIN, PRIORITY, PERCENTAGE)
- [ ] Create `AlbsStatus` enum (ACTIVE, INACTIVE)  
- [ ] Reuse `RingGroupFallbackAction` enum for fallback actions
- [ ] Add human-readable labels and descriptions to enums

#### API Endpoints
- [ ] `GET /api/ai-assistant-load-balancers` - List load balancers
- [ ] `POST /api/ai-assistant-load-balancers` - Create new load balancer
- [ ] `GET /api/ai-assistant-load-balancers/{id}` - Get specific load balancer
- [ ] `PUT /api/ai-assistant-load-balancers/{id}` - Update load balancer
- [ ] `DELETE /api/ai-assistant-load-balancers/{id}` - Delete load balancer
- [ ] Implement tenant scoping middleware
- [ ] Add validation rules for all endpoints
- [ ] Implement circular reference checking
- [ ] Add member weight normalization logic

#### Business Logic
- [ ] Create `AlbsDistributionService` class
- [ ] Implement Round Robin algorithm with Redis counter
- [ ] Implement Priority-based selection algorithm
- [ ] Implement Percentage-based (weighted random) algorithm
- [ ] Create member availability checking logic
- [ ] Implement fallback resolution logic
- [ ] Add circular reference validation (max depth 5)
- [ ] Create Redis-based state management for round robin

#### Integration Tasks
- [ ] Add `AI_ASSISTANT_LBS` to `ExtensionType` enum
- [ ] Modify `VoiceRoutingManager` to support ALBS routing
- [ ] Update CXML generation to use selected AI Assistant
- [ ] Add ALBS validation to DID mapping
- [ ] Implement webhook processing for ALBS routing
- [ ] Add Redis-based caching for load balancer config
- [ ] Create reference checking for AI Assistant deletions

### Frontend Tasks

#### UI Components
- [ ] Create AiAssistantLoadBalancersList component with data table
- [ ] Build AiAssistantLoadBalancerForm for create/edit dialog
- [ ] Implement MemberSelector component (AI Assistant picker)
- [ ] Create StrategySelector component with conditional fields
- [ ] Add PriorityEditor component (drag-and-drop ordering)
- [ ] Implement WeightEditor component (percentage sliders)
- [ ] Create FallbackDestinationSelector component
- [ ] Implement StatusBadge component for member status
- [ ] Add empty state handling per requirements

#### Pages & Navigation
- [ ] Add AI Assistant Load Balancers page to admin navigation
- [ ] Create load balancer list page with filtering/search
- [ ] Build load balancer detail/edit dialog
- [ ] Add ALBS option to DID routing configuration
- [ ] Implement responsive layout for mobile/tablet

#### API Integration
- [ ] Create ALBS API service functions
- [ ] Implement React Query hooks for data fetching
- [ ] Add form validation and error handling
- [ ] Create optimistic updates for better UX
- [ ] Implement loading and error states

### Integration Tasks

#### Call Routing Integration
- [ ] Update voice routing webhook handler for ALBS
- [ ] Modify CXML generation to include ALBS logic
- [ ] Add ALBS evaluation to call state machine
- [ ] Implement fallback routing when all members unavailable
- [ ] Create unit tests for routing logic

#### System Integration
- [ ] Add ALBS to DID routing options enum
- [ ] Update extension creation to support ALBS type
- [ ] Implement tenant isolation for ALBS data
- [ ] Add audit logging for ALBS changes
- [ ] Create database seeders for demo data
- [ ] Add reference checking when deleting AI Assistants

### Testing Tasks

#### Unit Tests
- [ ] AiAssistantLoadBalancer model tests
- [ ] AiAssistantLoadBalancerMember model tests
- [ ] AlbsDistributionService algorithm tests
- [ ] API endpoint unit tests
- [ ] Validation logic tests
- [ ] Circular reference detection tests
- [ ] Weight normalization tests

#### Integration Tests
- [ ] ALBS CRUD API tests
- [ ] Call routing integration tests
- [ ] Webhook processing tests
- [ ] Database relationship tests
- [ ] Caching layer tests
- [ ] Redis state management tests

#### Acceptance Tests
- [ ] Load balancer configuration workflow
- [ ] Round Robin distribution behavior
- [ ] Priority-based distribution behavior
- [ ] Percentage-based distribution behavior
- [ ] Fallback handling when all members unavailable
- [ ] Member status toggle behavior
- [ ] UI form validation and submission

---

## Implementation Phases

### Phase 1: Foundation (Week 1)
**Dependencies:** None
**Deliverables:** Database schema, basic models, API skeleton

1. Database schema design and migrations
2. Eloquent models with relationships (AiAssistantLoadBalancer, AiAssistantLoadBalancerMember)
3. Enum classes (AlbsStrategy, AlbsStatus)
4. Basic API endpoints structure (Controller extending AbstractApiCrudController)
5. Unit test setup for models

**Detailed Tasks:**
- Create migration for `ai_assistant_load_balancers` table
- Create migration for `ai_assistant_load_balancer_members` table
- Implement `AiAssistantLoadBalancer` model with OrganizationScope and SoftDeletes
- Implement `AiAssistantLoadBalancerMember` model
- Add relationships (hasMany members, belongsTo aiAssistant, fallback relationships)
- Create `AlbsStrategy` enum with label() and description() methods
- Create `AiAssistantLoadBalancerController` skeleton
- Set up PHPUnit test structure

### Phase 2: Core Business Logic (Week 2)
**Dependencies:** Phase 1 complete
**Deliverables:** Distribution algorithms, API completion

1. AlbsDistributionService implementation with all three algorithms
2. Complete API endpoints with FormRequest validation
3. Member management logic (add/remove/reorder)
4. Fallback resolution logic
5. Circular reference validation
6. Unit tests for distribution algorithms
7. Integration tests for API endpoints

**Detailed Tasks:**
- Implement Round Robin algorithm:
  ```php
  public function selectUsingRoundRobin(AiAssistantLoadBalancer $albs): ?AiAssistant
  {
      $members = $albs->getActiveMembers(); // ordered by position
      if ($members->isEmpty()) return null;
      
      $key = "albs:rr:{$albs->id}";
      $counter = Redis::incr($key);
      Redis::expire($key, 86400); // 24 hour TTL
      
      $index = ($counter - 1) % $members->count();
      return $members[$index]->aiAssistant;
  }
  ```
- Implement Priority algorithm:
  ```php
  public function selectUsingPriority(AiAssistantLoadBalancer $albs): ?AiAssistant
  {
      $members = $albs->getActiveMembers()->sortBy('priority');
      return $members->first()?->aiAssistant;
  }
  ```
- Implement Percentage algorithm:
  ```php
  public function selectUsingPercentage(AiAssistantLoadBalancer $albs): ?AiAssistant
  {
      $members = $albs->getActiveMembers();
      if ($members->isEmpty()) return null;
      
      // Normalize weights to 100%
      $totalWeight = $members->sum('weight');
      $random = rand(1, $totalWeight);
      
      $cumulative = 0;
      foreach ($members as $member) {
          $cumulative += $member->weight;
          if ($random <= $cumulative) {
              return $member->aiAssistant;
          }
      }
      
      return $members->first()->aiAssistant;
  }
  ```
- Create `StoreAlbsRequest` and `UpdateAlbsRequest` FormRequests
- Add circular reference checking (prevent infinite loops)
- Implement member normalization (clear irrelevant fallback IDs)

### Phase 3: Frontend Development (Week 2-3)
**Dependencies:** Phase 2 API complete
**Deliverables:** Admin UI for ALBS management

1. Load balancer list and detail components
2. Member management UI with drag-and-drop
3. Strategy selector with conditional fields
4. Priority/weight editors
5. Form validation and error handling
6. API integration with React Query

**Detailed Tasks:**
- Create `AiAssistantLoadBalancers.tsx` page (similar to RingGroups.tsx):
  - Data table with columns: Name, Strategy, Members, Status, Actions
  - Search/filter by strategy, status
  - Create/Edit dialog with tabs: Basic Info, Members, Fallback
  - Empty state with icon and "Create Load Balancer" button
- Implement member management:
  - AI Assistant multi-select dropdown
  - Drag-and-drop for priority/position ordering
  - Weight sliders for percentage strategy (0-100, shows percentage)
  - Status toggle per member (active/inactive)
- Add strategy-specific UI:
  - Round Robin: Show member order (position)
  - Priority: Show priority numbers (lower = higher priority)
  - Percentage: Show weight sliders with percentage display
- Create `aiAssistantLoadBalancers.service.ts` with CRUD methods
- Add React Query hooks (useLoadBalancers, useCreateLoadBalancer, etc.)
- Implement form validation (min 1 member, weights sum to 100 for percentage)

### Phase 4: Integration & Voice Routing (Week 3-4)
**Dependencies:** Phase 2 and Phase 3 complete
**Deliverables:** Fully integrated feature with call routing

1. Voice routing integration with VoiceRoutingManager
2. CXML generation updates
3. Cache integration for performance
4. End-to-end testing
5. Performance optimization

**Detailed Tasks:**
- Add `AI_ASSISTANT_LBS` case to `ExtensionType` enum:
  ```php
  case AI_ASSISTANT_LBS = 'ai_assistant_lbs';
  ```
- Update `VoiceRoutingManager::routeUsingExtensionStrategies()`:
  ```php
  case ExtensionType::AI_ASSISTANT_LBS:
      return $this->routeToAiAssistantLoadBalancer($extension, $request);
  ```
- Implement routing method:
  ```php
  private function routeToAiAssistantLoadBalancer(Extension $extension, Request $request): Response
  {
      $albs = $extension->aiAssistantLoadBalancer;
      $distributionService = app(AlbsDistributionService::class);
      
      $selectedAssistant = $distributionService->selectAssistant($albs);
      
      if (!$selectedAssistant) {
          return $this->handleAlbsFallback($albs, $request);
      }
      
      // Reuse existing AI Assistant routing logic
      return $this->routeToAiAssistant($selectedAssistant, $request);
  }
  ```
- Add caching to VoiceRoutingCacheService:
  ```php
  public function getAiAssistantLoadBalancer(int $id): ?AiAssistantLoadBalancer
  {
      return Cache::remember(
          "albs:{$id}",
          config('voice_routing.cache_ttl', 1800),
          fn() => AiAssistantLoadBalancer::with('members.aiAssistant')->find($id)
      );
  }
  ```
- Update DID routing to support ALBS extension type
- Add reference checking when deleting AI Assistants

### Phase 5: Testing & QA (Week 4-5)
**Dependencies:** Phase 4 complete
**Deliverables:** Production-ready feature with comprehensive testing

1. Comprehensive unit test coverage (>90%)
2. Integration test coverage for critical paths
3. End-to-end acceptance tests
4. Performance testing for concurrent routing
5. Bug fixes and optimization
6. Documentation updates

**Detailed Tasks:**
- Unit tests for AlbsDistributionService algorithms:
  - Round Robin: Verify counter increment, modulo, Redis TTL
  - Priority: Verify sorting by priority, selection of first
  - Percentage: Verify weight normalization, distribution over 1000 calls
- Integration tests:
  - CRUD API operations with tenant scoping
  - Circular reference detection
  - Member management (add/remove/reorder)
  - Fallback resolution
- Acceptance tests:
  - Create load balancer with 3 AI Assistants, round robin strategy
  - Make 10 test calls, verify distribution is even (3-4-3 or 3-3-4)
  - Deactivate all members, verify fallback triggers
  - Test percentage strategy with weights 60/40, verify distribution
- Performance tests:
  - 100 concurrent routing requests < 200ms p95
  - Redis counter atomic operations under load
  - Cache hit rate > 90% for load balancer config

---

## Database Schema Detailed Specification

### `ai_assistant_load_balancers` Table

```sql
CREATE TABLE `ai_assistant_load_balancers` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `organization_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `strategy` ENUM('round_robin', 'priority', 'percentage') NOT NULL DEFAULT 'round_robin',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `fallback_action` ENUM('extension', 'ring_group', 'ivr_menu', 'ai_assistant', 'hangup') NOT NULL DEFAULT 'hangup',
  `fallback_extension_id` BIGINT UNSIGNED NULL,
  `fallback_ring_group_id` BIGINT UNSIGNED NULL,
  `fallback_ivr_menu_id` BIGINT UNSIGNED NULL,
  `fallback_ai_assistant_id` BIGINT UNSIGNED NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  
  FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`fallback_extension_id`) REFERENCES `extensions`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`fallback_ring_group_id`) REFERENCES `ring_groups`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`fallback_ivr_menu_id`) REFERENCES `ivr_menus`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`fallback_ai_assistant_id`) REFERENCES `ai_assistants`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  
  INDEX `idx_org_status` (`organization_id`, `status`),
  INDEX `idx_strategy` (`strategy`),
  INDEX `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Field Descriptions:**
- `name`: User-friendly name for the load balancer (e.g., "Customer Support AI Pool")
- `description`: Optional detailed description
- `strategy`: Distribution algorithm (round_robin, priority, percentage)
- `status`: Active/inactive toggle
- `fallback_action`: What to do when all members are unavailable
- `fallback_*_id`: Foreign keys for fallback destinations (only one relevant based on action)
- `created_by`/`updated_by`: Audit trail for who made changes
- `deleted_at`: Soft delete timestamp

### `ai_assistant_load_balancer_members` Table

```sql
CREATE TABLE `ai_assistant_load_balancer_members` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `load_balancer_id` BIGINT UNSIGNED NOT NULL,
  `ai_assistant_id` BIGINT UNSIGNED NOT NULL,
  `priority` INT NOT NULL DEFAULT 0,
  `weight` INT NOT NULL DEFAULT 100,
  `position` INT NOT NULL DEFAULT 0,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  
  FOREIGN KEY (`load_balancer_id`) REFERENCES `ai_assistant_load_balancers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`ai_assistant_id`) REFERENCES `ai_assistants`(`id`) ON DELETE CASCADE,
  
  UNIQUE KEY `unique_member` (`load_balancer_id`, `ai_assistant_id`),
  INDEX `idx_lb_status` (`load_balancer_id`, `status`),
  INDEX `idx_priority` (`load_balancer_id`, `priority`),
  INDEX `idx_position` (`load_balancer_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Field Descriptions:**
- `priority`: Used for priority strategy (lower number = higher priority, e.g., 0 is highest)
- `weight`: Used for percentage strategy (0-100, represents percentage of calls)
- `position`: Used for round robin strategy (determines order, 0-indexed)
- `status`: Active/inactive toggle per member
- Unique constraint: Same AI Assistant cannot be added twice to the same load balancer

---

## API Endpoint Specifications

### List Load Balancers
```
GET /api/ai-assistant-load-balancers
```

**Query Parameters:**
- `page` (integer, default: 1)
- `per_page` (integer, default: 15, max: 100)
- `strategy` (enum: round_robin, priority, percentage)
- `status` (enum: active, inactive)
- `search` (string, searches name and description)
- `sort_by` (string, default: created_at)
- `sort_order` (string, default: desc)

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "organization_id": 5,
      "name": "Customer Support AI Pool",
      "description": "Distributes customer calls across AI agents",
      "strategy": "round_robin",
      "status": "active",
      "fallback_action": "extension",
      "fallback_extension_id": 100,
      "members_count": 3,
      "active_members_count": 3,
      "members": [
        {
          "id": 10,
          "ai_assistant_id": 50,
          "ai_assistant": {
            "id": 50,
            "name": "AI Agent 1",
            "status": "active"
          },
          "priority": 0,
          "weight": 50,
          "position": 0,
          "status": "active"
        }
      ],
      "fallback_extension": {
        "id": 100,
        "extension_number": "100"
      },
      "created_at": "2026-02-08T10:00:00Z",
      "updated_at": "2026-02-08T10:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 5,
    "per_page": 15
  }
}
```

### Create Load Balancer
```
POST /api/ai-assistant-load-balancers
```

**Request Body:**
```json
{
  "name": "Customer Support AI Pool",
  "description": "Distributes customer calls across AI agents",
  "strategy": "round_robin",
  "status": "active",
  "fallback_action": "extension",
  "fallback_extension_id": 100,
  "members": [
    {
      "ai_assistant_id": 50,
      "priority": 0,
      "weight": 50,
      "position": 0,
      "status": "active"
    },
    {
      "ai_assistant_id": 51,
      "priority": 1,
      "weight": 50,
      "position": 1,
      "status": "active"
    }
  ]
}
```

**Validation Rules:**
- `name`: required, string, max:255, unique per organization
- `description`: nullable, string
- `strategy`: required, enum (round_robin, priority, percentage)
- `status`: required, enum (active, inactive)
- `fallback_action`: required, enum (extension, ring_group, ivr_menu, ai_assistant, hangup)
- `fallback_extension_id`: required_if:fallback_action,extension, exists:extensions,id (tenant-scoped)
- `fallback_ring_group_id`: required_if:fallback_action,ring_group, exists:ring_groups,id (tenant-scoped)
- `fallback_ivr_menu_id`: required_if:fallback_action,ivr_menu, exists:ivr_menus,id (tenant-scoped)
- `fallback_ai_assistant_id`: required_if:fallback_action,ai_assistant, exists:ai_assistants,id (tenant-scoped)
- `members`: required, array, min:1
- `members.*.ai_assistant_id`: required, exists:ai_assistants,id (tenant-scoped), distinct
- `members.*.priority`: nullable, integer, min:0
- `members.*.weight`: nullable, integer, min:0, max:100
- `members.*.position`: required, integer, min:0
- `members.*.status`: required, enum (active, inactive)

**Special Validation:**
- Circular reference check: Prevent fallback_ai_assistant_id from referencing a member of the load balancer
- Percentage strategy: Warn if weights don't sum to 100 (normalized on backend)
- Member count: At least 1 member required

**Response:** 201 Created with load balancer resource

### Update Load Balancer
```
PUT /api/ai-assistant-load-balancers/{id}
```

**Request Body:** Same as Create
**Response:** 200 OK with updated load balancer resource

**Notes:**
- Members are replaced entirely (delete existing + insert new)
- Distributed lock acquired during update to prevent race conditions
- Cache invalidation for `albs:{id}` and `albs:rr:{id}` keys

### Get Load Balancer
```
GET /api/ai-assistant-load-balancers/{id}
```

**Response:** 200 OK with load balancer resource (includes members)

### Delete Load Balancer
```
DELETE /api/ai-assistant-load-balancers/{id}
```

**Response:** 204 No Content

**Pre-delete Checks:**
- Verify no extensions reference this load balancer
- Verify not used as fallback destination in other load balancers or ring groups
- Return 409 Conflict if referenced

---

## Distribution Algorithm Specifications

### Round Robin Algorithm

**Purpose:** Evenly distribute calls across all active members in sequential order.

**Implementation:**
```php
public function selectUsingRoundRobin(AiAssistantLoadBalancer $albs): ?AiAssistant
{
    // Get active members ordered by position
    $members = $albs->members()
        ->where('status', 'active')
        ->whereHas('aiAssistant', fn($q) => $q->where('status', 'active'))
        ->orderBy('position', 'asc')
        ->get();
    
    if ($members->isEmpty()) {
        return null;
    }
    
    // Atomic increment Redis counter
    $key = "albs:rr:{$albs->id}";
    $counter = Redis::incr($key);
    
    // Set 24-hour expiry on first increment
    if ($counter === 1) {
        Redis::expire($key, 86400);
    }
    
    // Select member using modulo
    $index = ($counter - 1) % $members->count();
    
    return $members[$index]->aiAssistant;
}
```

**Key Points:**
- Uses Redis counter for atomic increments (thread-safe)
- Counter resets after 24 hours (or can be manually reset)
- Only includes active members with active AI Assistants
- Position field determines member order (0, 1, 2, ...)
- Modulo ensures wrapping back to first member

**Edge Cases:**
- Empty members: Return null, trigger fallback
- Member added/removed mid-operation: Counter continues, next call adjusts
- Redis unavailable: Log error, fall back to random selection

### Priority-Based Algorithm

**Purpose:** Always route to the highest-priority (lowest priority number) available AI Assistant.

**Implementation:**
```php
public function selectUsingPriority(AiAssistantLoadBalancer $albs): ?AiAssistant
{
    // Get active members ordered by priority ascending (0 = highest)
    $member = $albs->members()
        ->where('status', 'active')
        ->whereHas('aiAssistant', fn($q) => $q->where('status', 'active'))
        ->orderBy('priority', 'asc')
        ->first();
    
    return $member?->aiAssistant;
}
```

**Key Points:**
- Lower priority number = higher priority (0 is highest, like HTTP status codes)
- Always selects first member in priority order
- Useful for primary/backup scenarios (primary has priority 0, backups have 1, 2, etc.)
- No state management required (stateless)

**Edge Cases:**
- Multiple members with same priority: Undefined behavior (first by DB order)
- All members inactive: Return null, trigger fallback

### Percentage-Based (Weighted Random) Algorithm

**Purpose:** Distribute calls according to specified weight percentages (e.g., 60% to Agent A, 40% to Agent B).

**Implementation:**
```php
public function selectUsingPercentage(AiAssistantLoadBalancer $albs): ?AiAssistant
{
    // Get active members with weights
    $members = $albs->members()
        ->where('status', 'active')
        ->whereHas('aiAssistant', fn($q) => $q->where('status', 'active'))
        ->get();
    
    if ($members->isEmpty()) {
        return null;
    }
    
    // Normalize weights (handle cases where they don't sum to 100)
    $totalWeight = $members->sum('weight');
    
    if ($totalWeight === 0) {
        // All weights are 0, use round robin as fallback
        return $members->random()->aiAssistant;
    }
    
    // Generate random number between 1 and total weight
    $random = rand(1, $totalWeight);
    
    // Select member based on cumulative weight ranges
    $cumulative = 0;
    foreach ($members as $member) {
        $cumulative += $member->weight;
        if ($random <= $cumulative) {
            return $member->aiAssistant;
        }
    }
    
    // Fallback (should never reach here)
    return $members->first()->aiAssistant;
}
```

**Key Points:**
- Weights are automatically normalized (don't need to sum to 100)
- Uses weighted random selection (not deterministic)
- Example: Weights [60, 40] → 60% chance of first, 40% of second
- Stateless (no Redis state required)

**Edge Cases:**
- Weights sum to 0: Random selection
- Weights don't sum to 100: Normalized proportionally (e.g., [30, 30] → 50/50)
- Single member: Always selected (100%)

---

## Integration Points

### VoiceRoutingManager Integration

#### Add ALBS Extension Type

**File:** `app/Enums/ExtensionType.php`

```php
enum ExtensionType: string
{
    case USER = 'user';
    case CONFERENCE = 'conference';
    case RING_GROUP = 'ring_group';
    case IVR = 'ivr';
    case AI_ASSISTANT = 'ai_assistant';
    case AI_ASSISTANT_LBS = 'ai_assistant_lbs'; // NEW
    case CUSTOM_LOGIC = 'custom_logic';
    case FORWARD = 'forward';
    case QUEUE = 'queue';

    public function label(): string
    {
        return match ($this) {
            // ... existing cases
            self::AI_ASSISTANT_LBS => 'AI Assistant Load Balancer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            // ... existing cases
            self::AI_ASSISTANT_LBS => 'Distribute calls across multiple AI assistants',
        };
    }
}
```

#### Add Routing Strategy

**File:** `app/Services/VoiceRouting/VoiceRoutingManager.php`

```php
private function routeUsingExtensionStrategies(Extension $extension, Request $request): Response
{
    return match ($extension->type) {
        ExtensionType::USER => $this->routeToUser($extension, $request),
        ExtensionType::CONFERENCE => $this->routeToConferenceRoom($extension, $request),
        ExtensionType::RING_GROUP => $this->routeToRingGroup($extension, $request),
        ExtensionType::IVR => $this->routeToIvrMenu($extension, $request),
        ExtensionType::AI_ASSISTANT => $this->routeToAiAssistant($extension, $request),
        ExtensionType::AI_ASSISTANT_LBS => $this->routeToAiAssistantLoadBalancer($extension, $request), // NEW
        default => $this->handleUnknownExtensionType($extension, $request),
    };
}

private function routeToAiAssistantLoadBalancer(Extension $extension, Request $request): Response
{
    $callId = $request->input('CallSid');
    $orgId = $request->input('_organization_id');
    
    Log::debug('Routing to AI Assistant Load Balancer', [
        'call_id' => $callId,
        'extension_id' => $extension->id,
        'albs_id' => $extension->ai_assistant_load_balancer_id,
    ]);
    
    // Load load balancer from cache
    $albs = $this->cache->getAiAssistantLoadBalancer($extension->ai_assistant_load_balancer_id);
    
    if (!$albs || !$albs->isActive()) {
        Log::warning('ALBS not found or inactive', [
            'call_id' => $callId,
            'albs_id' => $extension->ai_assistant_load_balancer_id,
        ]);
        return $this->generateErrorResponse('Load balancer unavailable');
    }
    
    // Use distribution service to select AI Assistant
    $distributionService = app(AlbsDistributionService::class);
    $selectedAssistant = $distributionService->selectAssistant($albs);
    
    if (!$selectedAssistant) {
        Log::warning('No available AI Assistants in ALBS', [
            'call_id' => $callId,
            'albs_id' => $albs->id,
        ]);
        return $this->handleAlbsFallback($albs, $request);
    }
    
    Log::info('Selected AI Assistant from ALBS', [
        'call_id' => $callId,
        'albs_id' => $albs->id,
        'strategy' => $albs->strategy->value,
        'selected_assistant_id' => $selectedAssistant->id,
    ]);
    
    // Reuse existing AI Assistant routing logic
    return $this->generateAiAssistantCxml($selectedAssistant, $request);
}

private function handleAlbsFallback(AiAssistantLoadBalancer $albs, Request $request): Response
{
    $callId = $request->input('CallSid');
    
    Log::info('Handling ALBS fallback', [
        'call_id' => $callId,
        'albs_id' => $albs->id,
        'fallback_action' => $albs->fallback_action->value,
    ]);
    
    return match ($albs->fallback_action) {
        RingGroupFallbackAction::EXTENSION => $this->routeToExtension($albs->fallbackExtension, $request),
        RingGroupFallbackAction::RING_GROUP => $this->routeToRingGroup($albs->fallbackRingGroup, $request),
        RingGroupFallbackAction::IVR_MENU => $this->routeToIvrMenu($albs->fallbackIvrMenu, $request),
        RingGroupFallbackAction::AI_ASSISTANT => $this->routeToAiAssistant($albs->fallbackAiAssistant, $request),
        RingGroupFallbackAction::HANGUP => $this->generateHangupCxml('No AI assistants available'),
        default => $this->generateHangupCxml('Unknown fallback action'),
    };
}
```

### Cache Integration

**File:** `app/Services/VoiceRouting/VoiceRoutingCacheService.php`

```php
public function getAiAssistantLoadBalancer(int $id): ?AiAssistantLoadBalancer
{
    return Cache::remember(
        "albs:{$id}",
        config('voice_routing.cache_ttl', 1800), // 30 minutes
        function () use ($id) {
            return AiAssistantLoadBalancer::with([
                'members' => fn($q) => $q->where('status', 'active')->orderBy('position'),
                'members.aiAssistant' => fn($q) => $q->where('status', 'active'),
                'fallbackExtension',
                'fallbackRingGroup',
                'fallbackIvrMenu',
                'fallbackAiAssistant',
            ])->find($id);
        }
    );
}

public function invalidateAiAssistantLoadBalancer(int $id): void
{
    Cache::forget("albs:{$id}");
    Cache::forget("albs:rr:{$id}"); // Also clear round robin counter
}
```

### DID Routing Integration

**File:** `database/migrations/YYYY_MM_DD_add_albs_to_extensions.php`

```php
public function up()
{
    Schema::table('extensions', function (Blueprint $table) {
        $table->foreignId('ai_assistant_load_balancer_id')
            ->nullable()
            ->after('ai_assistant_id')
            ->constrained('ai_assistant_load_balancers')
            ->onDelete('set null');
    });
}
```

---

## Success Criteria and Acceptance Tests

### Functional Requirements
- [ ] Admin can create AI Assistant Load Balancer with name, description, strategy
- [ ] Admin can add/remove AI Assistants as members
- [ ] Admin can set priority/weight/position for each member
- [ ] Admin can toggle member status (active/inactive)
- [ ] Admin can configure fallback action and destination
- [ ] Calls distribute correctly using Round Robin strategy
- [ ] Calls distribute correctly using Priority strategy
- [ ] Calls distribute correctly using Percentage strategy
- [ ] Fallback triggers when all members unavailable
- [ ] Circular reference validation prevents infinite loops

### Non-Functional Requirements
- [ ] API response time < 200ms for routing decisions (p95)
- [ ] UI loads within 2 seconds
- [ ] 99.9% uptime for routing service
- [ ] Supports 100+ concurrent routing evaluations without degradation
- [ ] Cache hit rate > 90% for load balancer config
- [ ] Redis operations are atomic (no race conditions)

### Acceptance Test Scenarios

#### Scenario 1: Round Robin Distribution
```
Given: ALBS with 3 AI Assistants (ID: 50, 51, 52) using Round Robin strategy
And: All members are active with positions 0, 1, 2
When: 9 calls arrive at the load balancer
Then: Calls distribute evenly (3 calls to each AI Assistant in sequence)
And: CXML contains correct AI Assistant configuration for each call
And: Redis counter increments from 1 to 9
```

#### Scenario 2: Priority-Based Selection
```
Given: ALBS with 3 AI Assistants using Priority strategy
And: Assistant A has priority 0, B has priority 1, C has priority 2
And: All members are active
When: 10 calls arrive at the load balancer
Then: All calls route to Assistant A (highest priority)
When: Assistant A is marked inactive
And: Next call arrives
Then: Call routes to Assistant B (next highest priority)
```

#### Scenario 3: Percentage-Based Distribution
```
Given: ALBS with 2 AI Assistants using Percentage strategy
And: Assistant A has weight 60, Assistant B has weight 40
When: 1000 calls arrive at the load balancer
Then: Approximately 600 calls route to Assistant A (58-62%)
And: Approximately 400 calls route to Assistant B (38-42%)
```

#### Scenario 4: Fallback to Extension
```
Given: ALBS configured with fallback action "extension" to ext 100
And: All AI Assistant members are inactive
When: Call arrives at the load balancer
Then: Call routes to extension 100
And: CXML contains extension routing instructions
And: Call log records fallback routing
```

#### Scenario 5: Circular Reference Prevention
```
Given: Admin creates ALBS with AI Assistant A as member
When: Admin sets fallback action to "AI Assistant"
And: Selects AI Assistant A as fallback destination
Then: Validation error returned: "Circular reference detected"
And: Load balancer is not saved
```

#### Scenario 6: Member Management UI
```
Given: Admin opens load balancer edit dialog
When: Admin adds 3 AI Assistants as members
And: Drags Assistant 3 to position 1 (reordering)
And: Sets weights to 50, 30, 20 for percentage strategy
And: Toggles Assistant 2 to inactive
And: Saves changes
Then: Members saved with new positions: [3, 1, 2]
And: Weights saved: [50, 30, 20]
And: Assistant 2 status is inactive
And: Cache invalidated for load balancer
```

---

## Edge Cases and Design Decisions

### Q: What happens when all AI Assistants are unavailable?
**A:** The load balancer triggers its configured fallback action (extension, ring group, IVR, AI Assistant, or hangup). This is evaluated in real-time during call routing.

**Implementation:**
- Check member availability before distribution
- If `getActiveMembers()->isEmpty()`, invoke `handleAlbsFallback()`
- Log warning with call_id and albs_id
- Record fallback trigger in call logs

### Q: How are percentage weights handled if they don't sum to 100?
**A:** Weights are automatically normalized proportionally during distribution. Example: Weights [30, 30, 30] are treated as [33.3%, 33.3%, 33.3%].

**Implementation:**
```php
$totalWeight = $members->sum('weight');
$random = rand(1, $totalWeight); // Random between 1 and total, not 1-100
```

**UI Behavior:**
- Display warning if weights don't sum to 100: "Weights will be normalized to 100%"
- Show calculated percentages next to weight inputs
- Example: Weights [60, 30] → Display "66.7% (60)" and "33.3% (30)"

### Q: How is Round Robin state persisted across server restarts?
**A:** Round Robin counter is stored in Redis with a 24-hour TTL. If Redis is flushed or unavailable, the counter resets to 0 and distribution starts over.

**Edge Cases:**
- Redis unavailable: Fall back to random selection, log error
- Counter reaches MAX_INT: Redis handles wraparound automatically
- Multiple app servers: Redis ensures atomic increments (thread-safe)

**Manual Reset:**
- Provide admin action "Reset Round Robin Counter" in UI
- Clears Redis key `albs:rr:{id}`

### Q: Can the same AI Assistant belong to multiple load balancers?
**A:** Yes. The junction table (`ai_assistant_load_balancer_members`) supports many-to-many relationships. An AI Assistant can be a member of multiple load balancers with different priorities/weights.

**Use Case:** AI Assistant "General Support Agent" is in both "Customer Support LBS" (60% weight) and "Sales Support LBS" (40% weight).

**Validation:**
- Unique constraint only within same load balancer
- No global uniqueness enforced

### Q: How are circular fallback references prevented?
**A:** Validation checks fallback chains on save, preventing loops and limiting depth to 5 levels.

**Validation Logic:**
```php
public function validateFallback(AiAssistantLoadBalancer $albs): void
{
    if ($albs->fallback_action !== RingGroupFallbackAction::AI_ASSISTANT) {
        return; // No circular risk
    }
    
    $visited = collect([$albs->id]);
    $current = $albs->fallbackAiAssistant;
    $depth = 0;
    
    while ($current && $depth < 5) {
        // Check if fallback is a member of the load balancer
        if ($albs->members()->where('ai_assistant_id', $current->id)->exists()) {
            throw new ValidationException('Circular reference: Fallback AI Assistant is a member of this load balancer');
        }
        
        // Check if fallback leads back to this load balancer
        if ($current->extension?->ai_assistant_load_balancer_id === $albs->id) {
            throw new ValidationException('Circular reference detected in fallback chain');
        }
        
        // Follow chain if fallback is also an ALBS
        if ($current->extension?->type === ExtensionType::AI_ASSISTANT_LBS) {
            $nextAlbs = $current->extension->aiAssistantLoadBalancer;
            
            if ($visited->contains($nextAlbs->id)) {
                throw new ValidationException('Circular reference detected in fallback chain');
            }
            
            $visited->push($nextAlbs->id);
            $current = $nextAlbs->fallbackAiAssistant;
            $depth++;
        } else {
            break; // Chain ends
        }
    }
    
    if ($depth >= 5) {
        throw new ValidationException('Fallback chain too deep (max 5 levels)');
    }
}
```

**Validation Triggers:**
- On load balancer create/update
- On fallback destination change
- Returns 422 Unprocessable Entity with error message

### Q: What happens when a member's status changes mid-call?
**A:** Status changes only affect NEW calls. Active calls continue with the selected AI Assistant.

**Behavior:**
- Status checked at routing time (not during call)
- Cache TTL means status changes propagate within 30 minutes
- Manual cache invalidation available via admin action

### Q: How are deleted AI Assistants handled?
**A:** Foreign key constraint `ON DELETE CASCADE` automatically removes members when AI Assistant is deleted. If all members removed, fallback triggers on next call.

**Pre-delete Checks:**
- Check if AI Assistant is the only active member in any ALBS
- Warn admin: "This AI Assistant is the only active member in Load Balancer X. Deletion will trigger fallback."
- Require confirmation before deletion

---

## Timeline Estimates

### Phase 1: Foundation (5-7 days)
- Database migrations: 1 day
- Model implementation: 2 days
- Enum classes: 0.5 days
- API controller skeleton: 1 day
- Unit test setup: 0.5 days
- Buffer: 1 day

### Phase 2: Core Logic (7-10 days)
- AlbsDistributionService: 3 days (1 day per algorithm)
- API completion (FormRequests, validation): 2 days
- Fallback resolution: 1 day
- Circular reference validation: 1 day
- Unit tests: 2 days
- Buffer: 1 day

### Phase 3: Frontend (7-10 days)
- List page component: 2 days
- Create/Edit dialog: 2 days
- Member management UI: 2 days
- Strategy-specific fields (priority/weight editors): 1 day
- API integration (React Query): 1 day
- Testing and polish: 1 day
- Buffer: 1 day

### Phase 4: Integration (5-7 days)
- VoiceRoutingManager integration: 2 days
- Cache integration: 1 day
- DID routing updates: 1 day
- End-to-end testing: 1 day
- Bug fixes: 1 day
- Buffer: 1 day

### Phase 5: Testing & QA (5-7 days)
- Comprehensive unit tests: 2 days
- Integration tests: 1 day
- Acceptance tests: 1 day
- Performance testing: 1 day
- Documentation: 0.5 days
- Buffer: 1 day

**Total Timeline:** 29-41 working days (approximately 4-6 weeks)
**Recommended Buffer:** 20% (6-8 days) for unexpected issues
**Final Estimate:** 5-7 weeks with buffer

---

## Resource Requirements

### Team Composition
- **Backend Developer (PHP/Laravel):** 3-4 weeks full-time
  - Database schema and models
  - API endpoints and validation
  - Distribution algorithms
  - Voice routing integration
  - Unit and integration tests
  
- **Frontend Developer (React):** 2-3 weeks full-time
  - UI components and pages
  - Member management interface
  - Strategy-specific editors
  - API integration with React Query
  - Frontend tests
  
- **QA Engineer:** 1 week full-time
  - Acceptance test scenarios
  - Performance testing
  - Edge case validation
  - Regression testing
  
- **DevOps Engineer:** 1 day for deployment support
  - Database migration review
  - Redis configuration verification
  - Deployment strategy

### Technical Requirements
- **Development Environment:** Laravel 11+, React 18+, MySQL 8+, Redis 7+
- **Testing Tools:** PHPUnit, Pest (optional), Jest, React Testing Library
- **Code Quality:** PHPStan level 8, ESLint, Prettier
- **Documentation:** API docs (OpenAPI), user guide, developer README

### Infrastructure Requirements
- **Database:** 2 new tables, ~10-100 rows per organization
- **Redis:** Additional keys for round robin counters (1 per ALBS), cache entries
- **Storage:** Minimal increase (~1KB per load balancer)
- **Compute:** +10-20ms avg per routing request (distribution algorithm overhead)

---

## Rollback Strategy

### Database Rollback
1. **Schema Rollback:** Migration down() methods tested and ready
2. **Data Preservation:** Export ALBS config before rollback
3. **Feature Flag:** `config('features.albs_routing')` can disable feature without rollback

### Application Rollback
1. **Code Deployment:** Git revert to previous commit, redeploy
2. **Cache Clearing:** Clear Redis keys: `albs:*`, `albs:rr:*`
3. **Queue Processing:** Ensure no pending jobs related to ALBS
4. **DID Reconfiguration:** Extensions with type AI_ASSISTANT_LBS revert to fallback

### Feature Flag Strategy
```php
// config/features.php
'albs_routing' => env('FEATURE_ALBS_ROUTING', true),

// VoiceRoutingManager.php
if (!config('features.albs_routing') && $extension->type === ExtensionType::AI_ASSISTANT_LBS) {
    Log::warning('ALBS feature disabled', ['extension_id' => $extension->id]);
    return $this->generateErrorResponse('Feature temporarily unavailable');
}
```

### Monitoring and Alerts
1. **Error Monitoring:** Sentry/Bugsnag for ALBS routing errors
2. **Performance Monitoring:** Track distribution algorithm latency (p50, p95, p99)
3. **Business Metrics:** Track fallback trigger rate, distribution evenness
4. **Alerts:** Trigger alert if fallback rate > 10% or latency > 500ms

### Communication Plan
1. **Stakeholder Notification:** 48-hour advance notice of deployment
2. **Incident Response:** On-call engineer during rollout window
3. **Post-Mortem:** Document issues, lessons learned, improvements

---

## Files to Create/Modify

### New Files to Create

#### Backend
- `database/migrations/2026_02_10_000001_create_ai_assistant_load_balancers_table.php`
- `database/migrations/2026_02_10_000002_create_ai_assistant_load_balancer_members_table.php`
- `database/migrations/2026_02_10_000003_add_albs_to_extensions_table.php`
- `app/Enums/AlbsStrategy.php`
- `app/Enums/AlbsStatus.php`
- `app/Models/AiAssistantLoadBalancer.php`
- `app/Models/AiAssistantLoadBalancerMember.php`
- `app/Http/Controllers/Api/AiAssistantLoadBalancerController.php`
- `app/Http/Requests/AiAssistantLoadBalancer/StoreAlbsRequest.php`
- `app/Http/Requests/AiAssistantLoadBalancer/UpdateAlbsRequest.php`
- `app/Http/Resources/AiAssistantLoadBalancerResource.php`
- `app/Services/VoiceRouting/AlbsDistributionService.php`
- `tests/Unit/Services/AlbsDistributionServiceTest.php`
- `tests/Feature/Api/AiAssistantLoadBalancerControllerTest.php`
- `tests/Integration/VoiceRouting/AlbsRoutingTest.php`

#### Frontend
- `frontend/src/pages/AiAssistantLoadBalancers.tsx`
- `frontend/src/services/aiAssistantLoadBalancers.service.ts`
- `frontend/src/types/aiAssistantLoadBalancer.ts`
- `frontend/src/components/albs/MemberSelector.tsx` (optional)
- `frontend/src/components/albs/StrategyEditor.tsx` (optional)
- `frontend/src/components/albs/FallbackSelector.tsx` (optional)

### Files to Modify

#### Backend
- `app/Enums/ExtensionType.php` - Add `AI_ASSISTANT_LBS` case
- `app/Services/VoiceRouting/VoiceRoutingManager.php` - Add routing strategy for ALBS
- `app/Services/VoiceRouting/VoiceRoutingCacheService.php` - Add cache methods
- `routes/api.php` - Add ALBS resource routes
- `config/voice_routing.php` - Add ALBS-specific config
- `app/Http/Controllers/Api/AbstractApiCrudController.php` - Add ALBS reference checking (if needed)
- `.env.example` - Add ALBS feature flag

#### Frontend
- `frontend/src/App.tsx` - Add ALBS route
- `frontend/src/components/Layout.tsx` - Add navigation item
- `frontend/src/pages/DidNumbers.tsx` - Add ALBS option to routing type selector (if needed)

### Files to Reuse (No Changes)
- `app/Enums/RingGroupFallbackAction.php` - Reuse for ALBS fallback actions
- `app/Services/CxmlBuilder/CxmlBuilder.php` - Reuse for CXML generation
- `app/Http/Controllers/Api/AbstractApiCrudController.php` - Extend for controller
- `app/Services/VoiceRouting/VoiceRoutingManager.php` - Add method, reuse infrastructure

---

## Appendix

### Model Code Example

```php
<?php

namespace App\Models;

use App\Enums\AlbsStrategy;
use App\Enums\AlbsStatus;
use App\Enums\RingGroupFallbackAction;
use App\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

#[ScopedBy([OrganizationScope::class])]
class AiAssistantLoadBalancer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'strategy',
        'status',
        'fallback_action',
        'fallback_extension_id',
        'fallback_ring_group_id',
        'fallback_ivr_menu_id',
        'fallback_ai_assistant_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'strategy' => AlbsStrategy::class,
            'status' => AlbsStatus::class,
            'fallback_action' => RingGroupFallbackAction::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(AiAssistantLoadBalancerMember::class, 'load_balancer_id');
    }

    public function fallbackExtension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'fallback_extension_id');
    }

    public function fallbackRingGroup(): BelongsTo
    {
        return $this->belongsTo(RingGroup::class, 'fallback_ring_group_id');
    }

    public function fallbackIvrMenu(): BelongsTo
    {
        return $this->belongsTo(IvrMenu::class, 'fallback_ivr_menu_id');
    }

    public function fallbackAiAssistant(): BelongsTo
    {
        return $this->belongsTo(AiAssistant::class, 'fallback_ai_assistant_id');
    }

    public function isActive(): bool
    {
        return $this->status === AlbsStatus::ACTIVE;
    }

    public function getActiveMembers(): Collection
    {
        return $this->members()
            ->where('status', 'active')
            ->whereHas('aiAssistant', fn($q) => $q->where('status', 'active'))
            ->orderBy('position')
            ->get();
    }
}
```

### Frontend Component Example

```tsx
// frontend/src/pages/AiAssistantLoadBalancers.tsx
import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { DataTable } from '@/components/ui/data-table';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Plus, Settings } from 'lucide-react';
import * as albsService from '@/services/aiAssistantLoadBalancers.service';

export function AiAssistantLoadBalancers() {
  const queryClient = useQueryClient();
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  
  const { data, isLoading } = useQuery({
    queryKey: ['ai-assistant-load-balancers'],
    queryFn: albsService.getLoadBalancers,
  });

  const columns = [
    {
      accessorKey: 'name',
      header: 'Name',
    },
    {
      accessorKey: 'strategy',
      header: 'Strategy',
      cell: ({ row }) => row.original.strategy.replace('_', ' ').toUpperCase(),
    },
    {
      accessorKey: 'members_count',
      header: 'Members',
      cell: ({ row }) => `${row.original.active_members_count} / ${row.original.members_count}`,
    },
    {
      accessorKey: 'status',
      header: 'Status',
      cell: ({ row }) => (
        <Badge variant={row.original.status === 'active' ? 'success' : 'secondary'}>
          {row.original.status}
        </Badge>
      ),
    },
  ];

  return (
    <div className="container mx-auto py-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-3xl font-bold">AI Assistant Load Balancers</h1>
        <Button onClick={() => setIsCreateDialogOpen(true)}>
          <Plus className="h-4 w-4 mr-2" />
          Create Load Balancer
        </Button>
      </div>

      <DataTable columns={columns} data={data?.data ?? []} isLoading={isLoading} />

      <Dialog open={isCreateDialogOpen} onOpenChange={setIsCreateDialogOpen}>
        <DialogContent>
          {/* Form component here */}
        </DialogContent>
      </Dialog>
    </div>
  );
}
```

---

## Conclusion

This feature specification provides a comprehensive blueprint for implementing the AI Assistant Load Balancer (ALBS) feature in the OPBX system. The specification covers all aspects including database design, API endpoints, business logic, UI components, integration points, testing requirements, and operational considerations.

**Key Takeaways:**
1. ALBS extends existing infrastructure (Ring Groups, AI Assistants)
2. Three distribution strategies provide flexibility (Round Robin, Priority, Percentage)
3. Fallback mechanism ensures high availability
4. Implementation follows established patterns (AbstractApiCrudController, OrganizationScope)
5. Comprehensive validation prevents edge cases (circular references, invalid weights)
6. Performance optimized with Redis caching and atomic operations

**Next Steps:**
1. Review and approve specification with stakeholders
2. Create GitHub issues/tickets for each phase
3. Begin Phase 1 implementation (Foundation)
4. Conduct weekly progress reviews
5. Deploy to staging environment for testing
6. Production rollout with feature flag

This document is ready for developer implementation and should be treated as the source of truth throughout the development lifecycle.
